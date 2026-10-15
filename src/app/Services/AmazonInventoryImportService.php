<?php

namespace App\Services;

use App\Exceptions\AmazonInventoryImportException;
use App\Exceptions\AmazonReportException;
use App\Exceptions\CrossWalkerException;
use App\Models\AmazonInventoryImport;
use App\Models\ImportTarget;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AmazonInventoryImportService
{
    private const SOURCE = 'sp_api';

    public function __construct(
        private CrossWalkerClient $crossWalker,
        private AmazonReportClient $reports,
        private SurveyAmazonInventorySyncService $surveySync,
    ) {}

    public function queue(string $triggerSource): AmazonInventoryImport
    {
        if (! in_array($triggerSource, ['manual', 'scheduled'], true)) {
            throw new AmazonInventoryImportException('Amazon在庫取込の起動元が不正です。');
        }

        $this->expireStale();

        try {
            return AmazonInventoryImport::query()->create([
                'status' => 'queued',
                'trigger_source' => $triggerSource,
                'source' => self::SOURCE,
                'active_key' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new AmazonInventoryImportException('Amazon在庫取込はすでに実行中です。');
        }
    }

    public function process(int $importId): AmazonInventoryImport
    {
        $claimed = AmazonInventoryImport::query()
            ->whereKey($importId)
            ->where('status', 'queued')
            ->where('active_key', 1)
            ->update(['status' => 'running', 'started_at' => now()]);
        if ($claimed !== 1) {
            throw new AmazonInventoryImportException('Amazon在庫取込を開始できません。実行状態を確認してください。');
        }

        try {
            [$stocks, $asinIndex] = $this->targetsAtStart();
            $own = $this->reports->fetchOwnInventory();
            $fba = $this->reports->fetchFbaInventory();
            $matchedOwn = $this->applyReport($stocks, $asinIndex, $own, 'amazon_own_stock');
            $matchedFba = $this->applyReport($stocks, $asinIndex, $fba, 'amazon_fba_stock');
            $basisAt = $own->retrievedAt->greaterThan($fba->retrievedAt) ? $own->retrievedAt : $fba->retrievedAt;

            DB::transaction(function () use ($importId, $stocks, $own, $fba, $matchedOwn, $matchedFba, $basisAt): void {
                $import = AmazonInventoryImport::query()->lockForUpdate()->findOrFail($importId);
                if ($import->status !== 'running' || $import->active_key !== 1) {
                    throw new AmazonInventoryImportException('Amazon在庫取込の実行権が失われました。');
                }

                $import->snapshots()->createMany(array_values(array_map(fn (array $stock): array => [
                    'product_code' => $stock['product_code'],
                    'sku_code' => $stock['sku_code'],
                    'asin' => $stock['asin'],
                    'amazon_own_stock' => $stock['amazon_own_stock'],
                    'amazon_fba_stock' => $stock['amazon_fba_stock'],
                    'source' => self::SOURCE,
                    'basis_at' => $basisAt,
                ], $stocks)));

                $import->update([
                    'status' => 'succeeded',
                    'active_key' => null,
                    'completed_at' => now(),
                    'own_retrieved_at' => $own->retrievedAt,
                    'fba_retrieved_at' => $fba->retrievedAt,
                    'basis_at' => $basisAt,
                    'snapshot_date' => $basisAt->setTimezone('Asia/Tokyo')->toDateString(),
                    'target_sku_count' => count($stocks),
                    'own_row_count' => count($own->rows),
                    'fba_row_count' => count($fba->rows),
                    'matched_own_count' => $matchedOwn,
                    'matched_fba_count' => $matchedFba,
                ]);
            });
        } catch (Throwable $exception) {
            $message = $exception instanceof AmazonInventoryImportException
                || $exception instanceof AmazonReportException
                || $exception instanceof CrossWalkerException
                ? $exception->getMessage()
                : 'Amazon在庫取込中に予期しないエラーが発生しました。';

            AmazonInventoryImport::query()
                ->whereKey($importId)
                ->where('status', 'running')
                ->where('active_key', 1)
                ->update([
                    'status' => 'failed',
                    'active_key' => null,
                    'completed_at' => now(),
                    'error_message' => $message,
                ]);
            Log::error('Amazon在庫取込に失敗しました。', ['import_id' => $importId, 'reason' => $message]);
        }

        $import = AmazonInventoryImport::query()->with('snapshots')->findOrFail($importId);
        if ($import->status === 'succeeded') {
            try {
                $this->surveySync->syncDate($import->snapshot_date);
            } catch (Throwable $exception) {
                Log::error('Amazon在庫の取得後、調査履歴への反映に失敗しました。', [
                    'import_id' => $import->id,
                    'snapshot_date' => $import->snapshot_date->toDateString(),
                    'exception' => $exception,
                ]);
            }
        }

        return $import;
    }

    public function expireStale(): void
    {
        $expired = AmazonInventoryImport::query()
            ->where('active_key', 1)
            ->where(function ($query): void {
                $cutoff = now()->subHours(2);
                $query->where(fn ($queued) => $queued->where('status', 'queued')->where('created_at', '<', $cutoff))
                    ->orWhere(fn ($running) => $running->where('status', 'running')->where('started_at', '<', $cutoff));
            })
            ->update([
                'status' => 'failed',
                'active_key' => null,
                'completed_at' => now(),
                'error_message' => 'Amazon在庫取込が制限時間内に完了しませんでした。',
            ]);

        if ($expired > 0) {
            Log::warning('Amazon在庫取込が制限時間内に完了しませんでした。', ['count' => $expired]);
        }
    }

    /**
     * @return array{0: array<string, array{product_code: string, sku_code: string, asin: string, amazon_own_stock: int, amazon_fba_stock: int}>, 1: array<string, string>}
     */
    private function targetsAtStart(): array
    {
        /** @var Collection<int, ImportTarget> $targets */
        $targets = ImportTarget::query()->with('product')->get()->unique('product_id')->values();
        if ($targets->isEmpty()) {
            throw new AmazonInventoryImportException('取込対象品番が設定されていません。');
        }

        $this->crossWalker->refresh($targets);
        $products = Product::query()
            ->whereIn('id', $targets->pluck('product_id'))
            ->with(['skus' => fn ($query) => $query->where('status', 'active')])
            ->get();
        if ($products->count() !== $targets->count()) {
            throw new AmazonInventoryImportException('取込対象品番を取得できませんでした。');
        }

        $stocks = [];
        $asinIndex = [];
        foreach ($products as $product) {
            foreach ($product->skus as $sku) {
                if (! is_string($sku->child_asin) || trim($sku->child_asin) === '') {
                    continue;
                }
                if (isset($stocks[$sku->sku_code]) || isset($asinIndex[$sku->child_asin])) {
                    throw new AmazonInventoryImportException('取込対象のSKUまたはASINが重複しています。');
                }
                $stocks[$sku->sku_code] = [
                    'product_code' => $product->product_code,
                    'sku_code' => $sku->sku_code,
                    'asin' => $sku->child_asin,
                    'amazon_own_stock' => 0,
                    'amazon_fba_stock' => 0,
                ];
                $asinIndex[$sku->child_asin] = $sku->sku_code;
            }
        }
        if ($stocks === []) {
            throw new AmazonInventoryImportException('ASINが設定された有効な取込対象SKUがありません。');
        }

        return [$stocks, $asinIndex];
    }

    /**
     * @param  array<string, array<string, int|string>>  $stocks
     * @param  array<string, string>  $asinIndex
     */
    private function applyReport(array &$stocks, array $asinIndex, AmazonInventoryReport $report, string $field): int
    {
        if ($report->rows === []) {
            throw new AmazonInventoryImportException('Amazonレポートにデータ行がありません。');
        }

        $seen = [];
        $matched = 0;
        $hasIdentifiedRow = false;
        foreach ($report->rows as $index => $row) {
            $hasIdentifiedRow = $hasIdentifiedRow || $row['asin'] !== '';
            $skuCode = $asinIndex[$row['asin']] ?? null;
            if ($skuCode === null) {
                continue;
            }
            if (isset($seen[$skuCode])) {
                throw new AmazonInventoryImportException('Amazonレポートの'.($index + 2).'行目で対象SKUが重複しています。');
            }
            $seen[$skuCode] = true;
            $stocks[$skuCode][$field] = $this->quantity($row['quantity'], $index + 2);
            $matched++;
        }

        if (! $hasIdentifiedRow) {
            throw new AmazonInventoryImportException('Amazonレポートに有効な識別子がありません。');
        }

        return $matched;
    }

    private function quantity(string $value, int $rowNumber): int
    {
        if ($value === '') {
            return 0;
        }
        if (! preg_match('/^\d+(?:\.0+)?$/D', $value)) {
            throw new AmazonInventoryImportException('Amazonレポートの'.$rowNumber.'行目の数量が不正です。');
        }
        $integer = ltrim(explode('.', $value, 2)[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        if (strlen($integer) > 10 || (strlen($integer) === 10 && strcmp($integer, '4294967295') > 0)) {
            throw new AmazonInventoryImportException('Amazonレポートの'.$rowNumber.'行目の数量が上限を超えています。');
        }

        return (int) $integer;
    }
}
