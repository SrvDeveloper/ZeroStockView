<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Survey;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InventoryImportService
{
    private const FILE_TYPES = [
        'boss' => '倉庫毎の在庫数レポート',
        'ec_stock' => 'KEEP一覧表',
        'free_stock' => '在庫一覧照会表',
    ];

    public function __construct(
        private CrossWalkerClient $crossWalker,
        private SurveyAmazonInventorySyncService $amazonSync,
    ) {}

    public function reimport(Survey $source, ImportSetting $setting): Survey
    {
        if (! $source->usesSpApiAmazonInventory()) {
            throw ValidationException::withMessages([
                'survey' => ['Amazon在庫をファイルで取り込んだ旧方式の調査のため、再照合できません。'],
            ]);
        }

        $source->loadMissing('files');
        $files = [];

        $sourceFiles = $source->files->whereIn('file_type', array_values(self::FILE_TYPES));
        if ($sourceFiles->isEmpty()) {
            throw ValidationException::withMessages(['files' => ['再照合に使える取込ファイルがないため、再照合できません。']]);
        }

        foreach ($sourceFiles as $file) {
            if ($file->expires_at === null || $file->expires_at->isPast() || ! Storage::disk($file->disk)->exists($file->stored_path)) {
                throw ValidationException::withMessages([
                    'files' => ['取込ファイルが削除されているため、再照合できません。'],
                ]);
            }

            $files[] = new UploadedFile(
                Storage::disk($file->disk)->path($file->stored_path),
                $file->original_name,
                $file->mime_type,
                null,
                true,
            );
        }

        return $this->import($files, $setting, $source->executed_at, $source);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  CarbonInterface|null  $executedAt  在庫データの基準日時。新規取込では現在日時、再照合では元の調査日時を使用する。
     */
    public function import(array $files, ?ImportSetting $setting = null, ?CarbonInterface $executedAt = null, ?Survey $source = null): Survey
    {
        $basisAt = $executedAt ?? now();
        $classifiedFiles = $this->classifyFiles($files);

        $allTargets = ImportTarget::query()->with('setting', 'product.skus')
            ->orderBy('import_setting_id')->orderBy('sort_order')->get();
        $targets = $allTargets->unique('product_id')->values();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'targets' => ['品番グループに取込対象品番を設定してください。'],
            ]);
        }

        $this->crossWalker->refresh($targets);
        [$targets, $groupMemberships] = $this->loadTargets();

        $storedFiles = [];
        $replacedFiles = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $survey = DB::transaction(function () use ($targets, $groupMemberships, $classifiedFiles, $setting, $basisAt, $source, &$storedFiles, &$replacedFiles): Survey {
                    $date = $basisAt->toDateString();
                    $survey = Survey::query()->where('survey_date', $date)->lockForUpdate()->first();
                    if ($survey === null && $source !== null) {
                        $survey = Survey::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                    }
                    if ($survey === null) {
                        $survey = Survey::query()
                            ->whereDate('executed_at', $date)
                            ->orderByDesc('executed_at')->orderByDesc('id')
                            ->lockForUpdate()->first();
                    }
                    $previousSettingName = $survey?->import_setting_name;
                    if ($survey === null) {
                        $survey = Survey::query()->create([
                            'executed_at' => $basisAt,
                            'survey_date' => $date,
                            'import_setting_name' => $setting?->name,
                            'includes_amazon_inventory' => false,
                            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                            'amazon_inventory_import_id' => null,
                        ]);
                    } else {
                        $survey->update([
                            'survey_date' => $date,
                            'import_setting_name' => $setting?->name,
                            'includes_amazon_inventory' => false,
                            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                        ]);
                        $survey->touch();
                    }

                    $existingFiles = $survey->files()->get()->keyBy('file_type');
                    $allFiles = $classifiedFiles;
                    foreach (self::FILE_TYPES as $key => $type) {
                        if (isset($allFiles[$key])) {
                            continue;
                        }
                        $saved = $existingFiles->get($type);
                        if ($saved !== null && $saved->expires_at?->isFuture() && Storage::disk($saved->disk)->exists($saved->stored_path)) {
                            $allFiles[$key] = new UploadedFile(
                                Storage::disk($saved->disk)->path($saved->stored_path),
                                $saved->original_name,
                                $saved->mime_type,
                                null,
                                true,
                            );
                        }
                    }

                    $skus = $targets->pluck('product.skus')->flatten();
                    $stocks = $skus->mapWithKeys(fn ($sku): array => [
                        $sku->sku_code => [
                            'amazon_own_stock' => null,
                            'amazon_fba_stock' => null,
                            'boss_own_stock' => isset($allFiles['boss']) ? 0 : null,
                            'boss_rfc_stock' => isset($allFiles['boss']) ? 0 : null,
                            'free_stock' => isset($allFiles['free_stock']) ? 0 : null,
                            'ec_stock' => isset($allFiles['ec_stock']) ? 0 : null,
                        ],
                    ])->all();
                    $skuLookup = $skus->pluck('sku_code', 'sku_code')->all();
                    $tqLookup = $skus->mapWithKeys(fn ($sku): array => [
                        $this->tqKey($sku->tq_item_no, $sku->tq_color_no, $sku->tq_size) => $sku->sku_code,
                    ])->all();
                    if (isset($allFiles['boss'])) {
                        $this->applyBoss($stocks, $skuLookup, $allFiles['boss']);
                    }
                    if (isset($allFiles['ec_stock'])) {
                        $this->applyTq($stocks, $tqLookup, $allFiles['ec_stock'], 'キープ残数', 'ec_stock', true);
                    }
                    if (isset($allFiles['free_stock'])) {
                        $this->applyTq($stocks, $tqLookup, $allFiles['free_stock'], '可能在庫数', 'free_stock', false);
                    }

                    $this->writeProducts($survey, $targets, $groupMemberships, $stocks, $previousSettingName);

                    foreach ($classifiedFiles as $key => $file) {
                        $type = self::FILE_TYPES[$key];
                        $oldFile = $existingFiles->get($type);
                        if ($oldFile !== null) {
                            $replacedFiles[] = [$oldFile->disk, $oldFile->stored_path];
                            $oldFile->delete();
                        }
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

                    $this->amazonSync->syncSurvey($survey->id);

                    return $survey;
                });
                break;
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($storedFiles);
                if ($exception instanceof UniqueConstraintViolationException && $source === null
                    && $attempt === 0 && str_contains($exception->getMessage(), 'survey_date')) {
                    // Another request claimed this day first. Re-read and update that row.
                    $storedFiles = [];
                    $replacedFiles = [];

                    continue;
                }

                throw $exception;
            }
        }

        foreach ($replacedFiles as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        try {
            // Catch an import that finished while the survey transaction was committing.
            $this->amazonSync->syncSurvey($survey->id);
        } catch (\Throwable $exception) {
            Log::error('調査保存後のAmazon在庫再同期に失敗しました。', [
                'survey_id' => $survey->id,
                'reason' => $exception->getMessage(),
            ]);
        }

        return $survey->refresh()->load(['products.skus', 'files', 'amazonImport.snapshots']);
    }

    /**
     * Amazon在庫の取得だけが成功した日に、ファイルなしの調査を作成する。
     * 品番・SKUは手元の商品情報を使い、CrossWalkerは呼ばない（Amazon取得の開始時に最新化済み）。
     * BOSS・フリー在庫・ECストックは取込待ち（null）とし、Amazon数量は同日の最新成功分を反映する。
     *
     * @return Survey|null 作成した調査。成功した取込でない、同じ日の調査がすでにある、対象品番がない場合は null
     */
    public function createFromAmazonImport(AmazonInventoryImport $import): ?Survey
    {
        if ($import->status !== 'succeeded' || $import->snapshot_date === null || $import->basis_at === null) {
            return null;
        }

        $date = $import->snapshot_date->toDateString();
        if ($this->surveyExistsOn($date)) {
            return null;
        }

        [$targets, $groupMemberships] = $this->loadTargets();
        if ($targets->isEmpty()) {
            return null;
        }

        $completedAt = $import->completed_at?->copy()->setTimezone('Asia/Tokyo');
        $executedAt = $completedAt !== null && $completedAt->toDateString() === $date ? $import->completed_at : $import->basis_at;

        try {
            return DB::transaction(function () use ($targets, $groupMemberships, $date, $executedAt): ?Survey {
                if ($this->surveyExistsOn($date)) {
                    return null;
                }

                $survey = Survey::query()->create([
                    'executed_at' => $executedAt,
                    'survey_date' => $date,
                    'import_setting_name' => null,
                    'includes_amazon_inventory' => false,
                    'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                    'amazon_inventory_import_id' => null,
                ]);

                $stocks = $targets->pluck('product.skus')->flatten()->mapWithKeys(fn ($sku): array => [
                    $sku->sku_code => [
                        'amazon_own_stock' => null,
                        'amazon_fba_stock' => null,
                        'boss_own_stock' => null,
                        'boss_rfc_stock' => null,
                        'free_stock' => null,
                        'ec_stock' => null,
                    ],
                ])->all();
                $this->writeProducts($survey, $targets, $groupMemberships, $stocks, null);
                $this->amazonSync->syncSurvey($survey->id);

                return $survey;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'survey_date')) {
                // 同じ日の調査をファイル取込が先に作成した。Amazon数量はその調査へ同期される。
                return null;
            }

            throw $exception;
        }
    }

    private function surveyExistsOn(string $date): bool
    {
        return Survey::query()
            ->where(fn ($query) => $query->where('survey_date', $date)->orWhereDate('executed_at', $date))
            ->exists();
    }

    /**
     * 全品番グループの対象品番を重複なく集め、有効なSKUと各品番のグループ所属を読み込む。
     *
     * @return array{0: Collection<int, ImportTarget>, 1: Collection<int, list<array{key: string, name: string, sortOrder: int}>>}
     */
    private function loadTargets(): array
    {
        $allTargets = ImportTarget::query()->with(['setting', 'product.skus' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('import_setting_id')->orderBy('sort_order')->get();
        $targets = $allTargets->unique('product_id')->sortBy('product.product_code')->values();
        $groupMemberships = $allTargets->groupBy('product_id')->map(fn ($members): array => $members->map(fn ($member): array => [
            'key' => 'setting:'.$member->import_setting_id,
            'name' => $member->setting->name,
            'sortOrder' => $member->sort_order,
        ])->all());

        return [$targets, $groupMemberships];
    }

    /**
     * 調査の品番・SKUを作り直す。既存のメモとグループ所属は引き継ぐ。
     *
     * @param  Collection<int, ImportTarget>  $targets
     * @param  Collection<int, list<array{key: string, name: string, sortOrder: int}>>  $groupMemberships
     * @param  array<string, array<string, int|null>>  $stocks
     */
    private function writeProducts(Survey $survey, Collection $targets, Collection $groupMemberships, array $stocks, ?string $previousSettingName): void
    {
        $existingProducts = $survey->products()->with('skus')->get();
        $productMemos = $existingProducts->pluck('memo', 'product_code');
        $skuMemos = $existingProducts->flatMap->skus->pluck('memo', 'sku_code');
        $priorProducts = $existingProducts->keyBy('product_code');
        $survey->products()->delete();

        foreach ($targets as $index => $target) {
            $product = $target->product;
            $priorProduct = $priorProducts->get($product->product_code);
            $priorMemberships = collect($priorProduct?->group_memberships ?? []);
            if ($priorProduct !== null && $priorMemberships->isEmpty() && $previousSettingName !== null) {
                $priorMemberships->push(['key' => 'legacy:'.$previousSettingName, 'name' => $previousSettingName, 'sortOrder' => $priorProduct->sort_order]);
            }
            $memberships = $priorMemberships->concat($groupMemberships->get($product->id, []))
                ->unique('key')->values()->all();
            $surveyProduct = $survey->products()->create([
                'product_code' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'parent_asin' => $product->parent_asin,
                'status' => $product->status,
                'source_updated_at' => $product->source_updated_at,
                'sort_order' => $index + 1,
                'group_memberships' => $memberships,
                'memo' => $productMemos->get($product->product_code),
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
                    'memo' => $skuMemos->get($sku->sku_code),
                ], $stocks[$sku->sku_code]));
            }
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<string, UploadedFile>
     */
    private function classifyFiles(array $files): array
    {
        if (count($files) < 1 || count($files) > count(self::FILE_TYPES)) {
            throw ValidationException::withMessages(['files' => ['取込ファイルを1〜3件添付してください。']]);
        }

        $classified = [];
        foreach ($files as $file) {
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
            $headerRow = fgetcsv($stream, separator: ',');
            fclose($stream);
            $headers = $headerRow === false ? [] : array_map($this->normalize(...), $headerRow);

            $matchingTypes = array_keys(array_filter([
                'boss' => $this->hasHeaders($headers, ['倉庫', 'SKUコード', '実在庫 (倉庫毎)', '引当済 (倉庫毎)']),
                'ec_stock' => $this->hasHeaders($headers, ['品番', 'カラーNo', 'キープ残数']) && $this->hasAnyHeader($headers, ['サイズ', 'ｻｲｽﾞ']),
                'free_stock' => $this->hasHeaders($headers, ['品番', 'カラーNo', '可能在庫数']) && $this->hasAnyHeader($headers, ['サイズ', 'ｻｲｽﾞ']),
            ]));
            if (count($matchingTypes) !== 1) {
                throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'の種類を内容から判定できません。ヘッダーを確認してください。']]);
            }
            $type = $matchingTypes[0];
            if (isset($classified[$type])) {
                throw ValidationException::withMessages(['files' => [self::FILE_TYPES[$type].'のファイルが複数あります。']]);
            }
            $classified[$type] = $file;
        }

        return $classified;
    }

    /** @param array<int, string> $headers @param array<int, string> $required */
    private function hasHeaders(array $headers, array $required): bool
    {
        return count(array_intersect($required, $headers)) === count($required);
    }

    /** @param array<int, string> $headers @param array<int, string> $candidates */
    private function hasAnyHeader(array $headers, array $candidates): bool
    {
        return count(array_intersect($candidates, $headers)) > 0;
    }

    /**
     * @param  array<string, array<string, int|null>>  $stocks
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
     * @param  array<string, array<string, int|null>>  $stocks
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
}
