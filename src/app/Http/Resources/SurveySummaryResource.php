<?php

namespace App\Http\Resources;

use App\Models\Survey;
use App\Models\SurveyFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 調査履歴の一覧に出す調査の概要。SKU別の在庫とメモは含めない（明細は SurveyResource）。
 *
 * @mixin Survey
 */
class SurveySummaryResource extends JsonResource
{
    /**
     * @param  array{productCount: int, skuCount: int, allZeroCount: int, partialZeroCount: int, hasSavedAmazonInventory: bool, amazonInventoryStatus: string}  $summary  SurveySummaryService の集計値
     */
    public function __construct(Survey $survey, private array $summary)
    {
        parent::__construct($survey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::sharedFields($this->resource, $this->summary['hasSavedAmazonInventory'], $this->summary['amazonInventoryStatus']) + [
            'productCount' => $this->summary['productCount'],
            'skuCount' => $this->summary['skuCount'],
            'allZeroCount' => $this->summary['allZeroCount'],
            'partialZeroCount' => $this->summary['partialZeroCount'],
        ];
    }

    /**
     * 一覧と明細（SurveyResource）で共通の項目。
     *
     * @return array<string, mixed>
     */
    public static function sharedFields(Survey $survey, bool $hasSavedAmazonInventory, string $amazonInventoryStatus): array
    {
        $usesSpApi = $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API;

        return [
            'id' => (string) $survey->getKey(),
            'executedAt' => $survey->executed_at->toISOString(),
            'createdAt' => $survey->created_at->toISOString(),
            'updatedAt' => $survey->updated_at->toISOString(),
            'importSettingName' => $survey->import_setting_name,
            'includesAmazonInventory' => $hasSavedAmazonInventory,
            'amazonSource' => $hasSavedAmazonInventory ? 'survey' : ($usesSpApi ? 'sp_api' : null),
            'amazonInventoryStatus' => $amazonInventoryStatus,
            'amazonInventoryImportId' => $usesSpApi && $survey->amazon_inventory_import_id !== null ? (string) $survey->amazon_inventory_import_id : null,
            'amazonBasisAt' => $hasSavedAmazonInventory ? $survey->executed_at->toISOString() : $survey->amazonImport?->basis_at?->toISOString(),
            'files' => $survey->files->map(fn (SurveyFile $file): array => [
                'id' => (string) $file->getKey(),
                'type' => $file->file_type,
                'fileName' => $file->original_name,
                'uploadedAt' => $file->uploaded_at->toISOString(),
                'sizeKb' => (int) ceil($file->size_bytes / 1024),
            ])->values()->all(),
        ];
    }
}
