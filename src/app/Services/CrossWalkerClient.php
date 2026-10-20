<?php

namespace App\Services;

use App\Exceptions\CrossWalkerException;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CrossWalkerClient
{
    public function __construct(private CrossWalkerConnectionStatus $connectionStatus) {}

    /**
     * @return array{
     *     products: list<array<string, mixed>>,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     totalPages: int
     * }
     */
    public function searchItems(?string $keyword, int $page, int $perPage): array
    {
        return $this->recordConnection(fn (): array => $this->fetchItems($keyword, $page, $perPage));
    }

    /**
     * @param  list<string>  $itemNos
     * @return list<array<string, mixed>>
     */
    public function lookupItems(array $itemNos): array
    {
        return $this->recordConnection(fn (): array => $this->fetchLookupItems($itemNos));
    }

    /** @param Collection<int, ImportTarget> $targets */
    public function refresh(Collection $targets): void
    {
        $itemNos = $targets->pluck('product.product_code')->values()->all();
        $items = collect();
        foreach (array_chunk($itemNos, 50) as $batch) {
            $items = $items->concat($this->lookupItems($batch));
        }
        $items = $items->keyBy('item_no');

        $missingCodes = collect($itemNos)->diff($items->keys());
        if ($missingCodes->isNotEmpty()) {
            throw new CrossWalkerException('CrossWalkerから取込対象の品番を取得できませんでした: '.$missingCodes->implode(', '));
        }

        DB::transaction(fn () => $this->syncProducts(
            $targets->mapWithKeys(fn (ImportTarget $target): array => [$target->product->product_code => $target->product]),
            $items,
        ));
    }

    /**
     * CrossWalker の品番・SKUを品番マスタ（products・skus）へ反映する。中身が変わった品番・SKUだけを書き込む。
     * SKUは対象の全品番をまとめて、「なくなった・変わったSKUを消す」→「新しい・変わったSKUを入れる」の順に処理する。
     * 一意制約のある値（SKUコード・ASIN・TQの品番/カラー/サイズ・品番内の並び順）が、品番をまたいで入れ替わっても途中で重ならない。
     * SKUのIDはほかの表から参照されていないので、変わったSKUは入れ直す。
     *
     * @param  SupportCollection<string, Product>  $products  品番コード => 品番（まだ保存していない新しい品番を含む）
     * @param  SupportCollection<string, array<string, mixed>>  $items  品番コード => CrossWalker の品番
     */
    public function syncProducts(SupportCollection $products, SupportCollection $items): void
    {
        $now = now();
        $unchangedProductIds = [];
        foreach ($products as $productCode => $product) {
            $item = $items->get($productCode);
            $product->fill([
                'product_code' => $item['item_no'],
                'brand' => $item['brand'],
                'category' => $item['category'],
                'parent_asin' => $item['parent_asin'] ?: null,
                'status' => $item['status'],
                'source_updated_at' => $item['updated_at'] ?: null,
            ]);
            if ($product->exists && ! $product->isDirty()) {
                $unchangedProductIds[] = $product->getKey();

                continue;
            }
            $product->synced_at = $now;
            $product->save();
        }
        // 中身が変わらない品番も、同期した日時（品番グループの「最終同期」）はまとめて更新する
        if ($unchangedProductIds !== []) {
            Product::query()->whereKey($unchangedProductIds)->update(['synced_at' => $now]);
            $products->each(fn (Product $product) => in_array($product->getKey(), $unchangedProductIds, true)
                ? $product->forceFill(['synced_at' => $now])->syncOriginalAttribute('synced_at') : null);
        }

        $desired = [];
        foreach ($products as $productCode => $product) {
            foreach ($items->get($productCode)['skus'] as $index => $sku) {
                $desired[] = [
                    'product_id' => $product->getKey(),
                    'sku_code' => $sku['sku_code'],
                    'child_asin' => $sku['child_asin'] ?: null,
                    'status' => $sku['status'],
                    'tq_item_no' => $sku['tq_item_no'],
                    'tq_color_no' => $sku['tq_color_no'],
                    'tq_size' => $sku['tq_size'] ?? '',
                    'sort_order' => $index + 1,
                ];
            }
        }

        $existing = Sku::query()->whereIn('product_id', $products->map(fn (Product $product): int => $product->getKey())->values())->get();
        $existingBySignature = $existing->keyBy(fn (Sku $sku): string => $this->skuSignature($sku->only(array_keys($desired[0] ?? ['product_id' => 0]))));
        $keptIds = [];
        $inserts = [];
        foreach ($desired as $row) {
            $kept = $existingBySignature->get($this->skuSignature($row));
            if ($kept !== null && ! isset($keptIds[$kept->getKey()])) {
                $keptIds[$kept->getKey()] = true;

                continue;
            }
            $inserts[] = $row + ['created_at' => $now, 'updated_at' => $now];
        }

        $deletedIds = $existing->reject(fn (Sku $sku): bool => isset($keptIds[$sku->getKey()]))->modelKeys();
        foreach (array_chunk($deletedIds, 500) as $chunk) {
            Sku::query()->whereKey($chunk)->delete();
        }
        foreach (array_chunk($inserts, 500) as $chunk) {
            Sku::query()->insert($chunk);
        }
    }

    /**
     * SKUの中身を比べるための文字列。DBから読んだ値（数値が文字列になる場合がある）と、CrossWalker の値を同じ形にそろえる。
     *
     * @param  array<string, mixed>  $row
     */
    private function skuSignature(array $row): string
    {
        return json_encode([
            (int) $row['product_id'],
            (string) $row['sku_code'],
            $row['child_asin'] === null || $row['child_asin'] === '' ? null : (string) $row['child_asin'],
            (string) $row['status'],
            (string) $row['tq_item_no'],
            (string) $row['tq_color_no'],
            (string) $row['tq_size'],
            (int) $row['sort_order'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{
     *     products: list<array<string, mixed>>,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     totalPages: int
     * }
     */
    private function fetchItems(?string $keyword, int $page, int $perPage): array
    {
        try {
            $response = $this->request()->get('/items', array_filter([
                'keyword' => $keyword,
                'status' => 'active',
                'page' => $page,
                'per_page' => $perPage,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            throw new CrossWalkerException('CrossWalker APIに接続できませんでした。時間をおいて再度お試しください。');
        }

        $payload = $this->payload($response);
        $items = $this->items($payload);
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        return [
            'products' => array_map(fn (array $item): array => $this->toProduct($item), $items),
            'page' => max(1, (int) ($meta['current_page'] ?? $page)),
            'perPage' => max(1, (int) ($meta['per_page'] ?? $perPage)),
            'total' => max(0, (int) ($meta['total'] ?? count($items))),
            'totalPages' => max(1, (int) ($meta['last_page'] ?? 1)),
        ];
    }

    /**
     * @param  list<string>  $itemNos
     * @return list<array<string, mixed>>
     */
    private function fetchLookupItems(array $itemNos): array
    {
        try {
            $response = $this->request()->post('/items/lookup', [
                'item_nos' => array_values(array_unique($itemNos)),
            ]);
        } catch (ConnectionException) {
            throw new CrossWalkerException('CrossWalker APIに接続できませんでした。時間をおいて再度お試しください。');
        }

        return $this->items($this->payload($response));
    }

    /**
     * 接続の成否を記録する。失敗時に保存するのは CrossWalkerException の固定の要約文だけで、
     * 接続例外の詳細（URL を含む）は保存しない。
     *
     * @template TResult
     *
     * @param  callable(): TResult  $call
     * @return TResult
     */
    private function recordConnection(callable $call): mixed
    {
        try {
            $result = $call();
        } catch (CrossWalkerException $exception) {
            $this->connectionStatus->recordFailure($exception->getMessage());

            throw $exception;
        }

        $this->connectionStatus->recordSuccess();

        return $result;
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.crosswalker.base_url');
        $apiKey = config('services.crosswalker.api_key');
        if (! is_string($baseUrl) || trim($baseUrl) === '' || ! is_string($apiKey) || trim($apiKey) === '') {
            throw new CrossWalkerException('CrossWalker APIの接続URLまたはAPIキーが設定されていません。', 503);
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(5, (int) config('services.crosswalker.timeout', 10)))
            ->timeout((int) config('services.crosswalker.timeout', 10));
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                401 => 'CrossWalker APIのAPIキーが正しくありません。',
                403 => 'CrossWalker APIから接続を拒否されました。接続元IPアドレスとHTTPS設定を確認してください。',
                429 => 'CrossWalker APIの利用上限に達しました。時間をおいて再度お試しください。',
                default => 'CrossWalker APIから商品情報を取得できませんでした。',
            };

            throw new CrossWalkerException($message, $status === 429 || $status >= 500 ? 503 : 502);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new CrossWalkerException('CrossWalker APIから不正なレスポンスを受信しました。');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        if (! is_array($payload['data'] ?? null)) {
            throw new CrossWalkerException('CrossWalker APIから不正な商品情報を受信しました。');
        }

        foreach ($payload['data'] as $item) {
            if (
                ! is_array($item)
                || ! isset($item['item_no'], $item['brand'], $item['category'], $item['status'])
                || ! array_key_exists('parent_asin', $item)
                || ! array_key_exists('updated_at', $item)
                || ! is_array($item['skus'] ?? null)
            ) {
                throw new CrossWalkerException('CrossWalker APIから不正な商品情報を受信しました。');
            }

            foreach ($item['skus'] as $sku) {
                if (
                    ! is_array($sku)
                    || ! isset($sku['sku_code'], $sku['status'], $sku['tq_item_no'], $sku['tq_color_no'])
                    || ! array_key_exists('child_asin', $sku)
                    || ! array_key_exists('tq_size', $sku)
                ) {
                    throw new CrossWalkerException('CrossWalker APIから不正なSKU情報を受信しました。');
                }
            }
        }

        return array_values($payload['data']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function toProduct(array $item): array
    {
        return [
            'productCode' => (string) $item['item_no'],
            'brand' => (string) $item['brand'],
            'category' => (string) $item['category'],
            'parentAsin' => (string) ($item['parent_asin'] ?? ''),
            'skus' => array_values(array_map(fn (array $sku): array => [
                'skuCode' => (string) ($sku['sku_code'] ?? ''),
                'colorName' => '',
                'size' => (string) ($sku['tq_size'] ?? ''),
                'asin' => (string) ($sku['child_asin'] ?? ''),
                'tqCode' => (string) ($sku['tq_item_no'] ?? ''),
                'tqColorNo' => (string) ($sku['tq_color_no'] ?? ''),
                'tqSize' => (string) ($sku['tq_size'] ?? ''),
            ], $item['skus'])),
        ];
    }
}
