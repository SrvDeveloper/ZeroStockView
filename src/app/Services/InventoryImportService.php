<?php

namespace App\Services;

use App\Models\ImportSetting;
use App\Models\Survey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InventoryImportService
{
    private const FILE_TYPES = [
        'amazon_own' => '在庫商品レポート',
        'amazon_fba' => 'FBA在庫管理レポート',
        'boss' => '倉庫毎の在庫数レポート',
        'ec_stock' => 'KEEP一覧表',
        'free_stock' => '在庫一覧照会表',
    ];

    public function __construct(private CrossWalkerClient $crossWalker) {}

    /** @param array<string, UploadedFile> $files */
    public function import(array $files, ImportSetting $setting): Survey
    {
        $targets = $setting->targets()
            ->with('product.skus')
            ->orderBy('sort_order')
            ->get();

        if ($targets->isEmpty() || $targets->count() > 50) {
            throw ValidationException::withMessages([
                'targets' => ['取込対象品番を1〜50件設定してください。'],
            ]);
        }

        $this->crossWalker->refresh($targets);
        $targets = $setting->targets()
            ->with(['product.skus' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('sort_order')
            ->get();

        $skus = $targets->pluck('product.skus')->flatten();
        $stocks = $skus->mapWithKeys(fn ($sku): array => [
            $sku->sku_code => [
                'amazon_own_stock' => 0,
                'amazon_fba_stock' => 0,
                'boss_own_stock' => 0,
                'boss_rfc_stock' => 0,
                'free_stock' => 0,
                'ec_stock' => 0,
            ],
        ])->all();

        $asinLookup = $skus->filter(fn ($sku): bool => $sku->child_asin !== null)
            ->pluck('sku_code', 'child_asin')->all();
        $skuLookup = $skus->pluck('sku_code', 'sku_code')->all();
        $tqLookup = $skus->mapWithKeys(fn ($sku): array => [
            $this->tqKey($sku->tq_item_no, $sku->tq_color_no, $sku->tq_size) => $sku->sku_code,
        ])->all();

        $this->applyAmazon($stocks, $asinLookup, $files['amazon_own'], 'amazon_own_stock', '数量', "\t");
        $this->applyAmazon($stocks, $asinLookup, $files['amazon_fba'], 'amazon_fba_stock', 'Amazon出荷在庫(出荷可)', ',');
        $this->applyBoss($stocks, $skuLookup, $files['boss']);
        $this->applyTq($stocks, $tqLookup, $files['ec_stock'], 'キープ残数', 'ec_stock', true);
        $this->applyTq($stocks, $tqLookup, $files['free_stock'], '可能在庫数', 'free_stock', false);

        $storedFiles = [];

        try {
            $survey = DB::transaction(function () use ($targets, $stocks, $files, $setting, &$storedFiles): Survey {
                $survey = Survey::query()->create(['executed_at' => now(), 'import_setting_name' => $setting->name]);

                foreach ($targets as $target) {
                    $product = $target->product;
                    $surveyProduct = $survey->products()->create([
                        'product_code' => $product->product_code,
                        'brand' => $product->brand,
                        'category' => $product->category,
                        'parent_asin' => $product->parent_asin,
                        'status' => $product->status,
                        'source_updated_at' => $product->source_updated_at,
                        'sort_order' => $target->sort_order,
                    ]);

                    foreach ($product->skus as $sku) {
                        $surveyProduct->skus()->create(array_merge([
                            'sku_code' => $sku->sku_code,
                            'child_asin' => $sku->child_asin,
                            'status' => $sku->status,
                            'tq_item_no' => $sku->tq_item_no,
                            'tq_color_no' => $sku->tq_color_no,
                            'tq_size' => $sku->tq_size,
                            'sort_order' => $sku->sort_order,
                        ], $stocks[$sku->sku_code]));
                    }
                }

                foreach (self::FILE_TYPES as $key => $type) {
                    $file = $files[$key];
                    $extension = strtolower($file->getClientOriginalExtension()) ?: 'dat';
                    $path = $file->storeAs(
                        'survey-imports/'.$survey->getKey(),
                        Str::uuid().'.'.$extension,
                        'local',
                    );

                    if ($path === false) {
                        throw new RuntimeException('取込ファイルを保存できませんでした。');
                    }

                    $storedFiles[] = $path;
                    $survey->files()->create([
                        'file_type' => $type,
                        'original_name' => $file->getClientOriginalName(),
                        'disk' => 'local',
                        'stored_path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'size_bytes' => $file->getSize(),
                        'uploaded_at' => now(),
                        'expires_at' => now()->addDays(30),
                    ]);
                }

                return $survey;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedFiles);
            throw $exception;
        }

        $this->pruneOldSurveys();

        return $survey->load(['products.skus', 'files']);
    }

    /**
     * @param  array<string, array<string, int>>  $stocks
     * @param  array<string, string>  $asinLookup
     */
    private function applyAmazon(array &$stocks, array $asinLookup, UploadedFile $file, string $stockField, string $quantityHeader, string $delimiter): void
    {
        $seen = [];
        foreach ($this->rows($file, ['ASIN', $quantityHeader], $delimiter) as $rowNumber => $row) {
            if (! isset($asinLookup[$row['ASIN']])) {
                continue;
            }

            $quantity = $this->quantity($row[$quantityHeader], $file, $rowNumber);
            if ($quantity === null) {
                continue;
            }

            $skuCode = $asinLookup[$row['ASIN']];
            $this->ensureNotDuplicate($seen, $skuCode, $file, $rowNumber);
            $stocks[$skuCode][$stockField] = $quantity;
        }
    }

    /**
     * @param  array<string, array<string, int>>  $stocks
     * @param  array<string, string>  $skuLookup
     */
    private function applyBoss(array &$stocks, array $skuLookup, UploadedFile $file): void
    {
        $seen = [];
        foreach ($this->rows($file, ['倉庫', 'SKUコード', '実在庫 (倉庫毎)', '引当済 (倉庫毎)']) as $rowNumber => $row) {
            $stockField = match ($row['倉庫']) {
                '自社倉庫' => 'boss_own_stock',
                'RFC倉庫' => 'boss_rfc_stock',
                default => null,
            };
            if ($stockField === null || ! isset($skuLookup[$row['SKUコード']])) {
                continue;
            }

            $physicalStock = $this->quantity($row['実在庫 (倉庫毎)'], $file, $rowNumber);
            $allocatedStock = $this->quantity($row['引当済 (倉庫毎)'], $file, $rowNumber);
            if ($physicalStock === null || $allocatedStock === null) {
                continue;
            }

            $quantity = $physicalStock - $allocatedStock;
            if ($quantity < 0) {
                throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量がマイナスです。"]]);
            }

            $skuCode = $skuLookup[$row['SKUコード']];
            $this->ensureNotDuplicate($seen, $stockField.':'.$skuCode, $file, $rowNumber);
            $stocks[$skuCode][$stockField] = $quantity;
        }
    }

    /**
     * @param  array<string, array<string, int>>  $stocks
     * @param  array<string, string>  $tqLookup
     */
    private function applyTq(array &$stocks, array $tqLookup, UploadedFile $file, string $quantityHeader, string $stockField, bool $sum): void
    {
        $seen = [];
        $rows = $this->rows($file, ['品番', 'カラーNo', ['サイズ', 'ｻｲｽﾞ'], $quantityHeader]);

        foreach ($rows as $rowNumber => $row) {
            $size = $row['サイズ'] ?? $row['ｻｲｽﾞ'] ?? '';
            $key = $this->tqKey($row['品番'], $row['カラーNo'], $size);

            if (! isset($tqLookup[$key])) {
                continue;
            }

            $quantity = $this->quantity($row[$quantityHeader], $file, $rowNumber);
            if ($quantity === null || $quantity === 0) {
                continue;
            }

            $skuCode = $tqLookup[$key];
            if (! $sum) {
                $this->ensureNotDuplicate($seen, $skuCode, $file, $rowNumber);
            }
            $stocks[$skuCode][$stockField] = $sum
                ? $stocks[$skuCode][$stockField] + $quantity
                : $quantity;
        }
    }

    /**
     * @param  array<int, string|array<int, string>>  $requiredHeaders
     * @return array<int, array<string, string>>
     */
    private function rows(UploadedFile $file, array $requiredHeaders, string $delimiter = ','): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'を読み込めません。']]);
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $headers = fgetcsv($stream, separator: $delimiter);

        if ($headers === false) {
            fclose($stream);
            throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'にヘッダーがありません。']]);
        }

        $headers = array_map($this->normalize(...), $headers);
        foreach ($requiredHeaders as $required) {
            $alternatives = (array) $required;
            if (count(array_intersect($alternatives, $headers)) === 0) {
                fclose($stream);
                throw ValidationException::withMessages([
                    'files' => [$file->getClientOriginalName().'に必須列「'.implode(' または ', $alternatives).'」がありません。'],
                ]);
            }
        }

        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($stream, separator: $delimiter)) !== false) {
            $rowNumber++;
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($headers), '');
            $rows[$rowNumber] = array_combine($headers, array_slice($values, 0, count($headers)));
        }
        fclose($stream);

        return $rows;
    }

    private function normalize(?string $value): string
    {
        return preg_replace('/[\\x{FEFF}\\r\\n]/u', '', trim((string) $value)) ?? '';
    }

    private function quantity(?string $value, UploadedFile $file, int $rowNumber): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^-?\\d+(?:\\.0+)?$/', $value)) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量が数値ではありません。"]]);
        }
        $quantity = (int) $value;
        if ($quantity < 0) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量がマイナスです。"]]);
        }

        return $quantity;
    }

    /** @param array<string, bool> $seen */
    private function ensureNotDuplicate(array &$seen, string $key, UploadedFile $file, int $rowNumber): void
    {
        if (isset($seen[$key])) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目で対象SKUが重複しています。"]]);
        }
        $seen[$key] = true;
    }

    private function tqKey(string $item, string $color, string $size): string
    {
        return implode("\x1f", [$item, $color, $size]);
    }

    private function pruneOldSurveys(): void
    {
        $oldSurveys = Survey::query()->orderByDesc('executed_at')->skip(100)->take(PHP_INT_MAX)->get();
        foreach ($oldSurveys as $survey) {
            Storage::disk('local')->delete($survey->files()->pluck('stored_path')->all());
            $survey->delete();
        }
    }
}
