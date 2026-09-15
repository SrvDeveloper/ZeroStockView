<?php

namespace App\Services;

use App\Exceptions\CrossWalkerException;
use App\Models\ImportTarget;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CrossWalkerClient
{
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
    public function lookupItems(array $itemNos): array
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

    /** @param Collection<int, ImportTarget> $targets */
    public function refresh(Collection $targets): void
    {
        $itemNos = $targets->pluck('product.product_code')->values()->all();
        $items = collect($this->lookupItems($itemNos))->keyBy('item_no');

        $missingCodes = collect($itemNos)->diff($items->keys());
        if ($missingCodes->isNotEmpty()) {
            throw new CrossWalkerException('CrossWalkerから取込対象の品番を取得できませんでした: '.$missingCodes->implode(', '));
        }

        DB::transaction(function () use ($targets, $items): void {
            foreach ($targets as $target) {
                $this->syncProduct($target->product, $items->get($target->product->product_code));
            }
        });
    }

    /** @param array<string, mixed> $item */
    public function syncProduct(Product $product, array $item): void
    {
        $product->fill([
            'product_code' => $item['item_no'],
            'brand' => $item['brand'],
            'category' => $item['category'],
            'parent_asin' => $item['parent_asin'] ?: null,
            'status' => $item['status'],
            'source_updated_at' => $item['updated_at'] ?: null,
            'synced_at' => now(),
        ])->save();

        $product->skus()->delete();
        foreach ($item['skus'] as $index => $sku) {
            $product->skus()->create([
                'sku_code' => $sku['sku_code'],
                'child_asin' => $sku['child_asin'] ?: null,
                'status' => $sku['status'],
                'tq_item_no' => $sku['tq_item_no'],
                'tq_color_no' => $sku['tq_color_no'],
                'tq_size' => $sku['tq_size'] ?? '',
                'sort_order' => $index + 1,
            ]);
        }
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
