<?php

namespace App\Http\Resources;

use App\Models\Survey;
use App\Models\SurveyProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    /** @var array<string, string>|null グループ名 => `setting:ID`（調査に保存されている現在のグループ） */
    private ?array $settingKeysByName = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasSavedAmazonInventory = $this->hasSavedAmazonInventory();
        $amazonStatus = $this->amazonInventoryStatus();

        return [
            'id' => (string) $this->getKey(),
            'executedAt' => $this->executed_at->toISOString(),
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
            'importSettingName' => $this->import_setting_name,
            'groups' => $this->products->flatMap(fn (SurveyProduct $product): array => $this->memberships($product))
                ->unique('key')->map(fn (array $group): array => ['key' => $group['key'], 'name' => $group['name']])->values(),
            'includesAmazonInventory' => $hasSavedAmazonInventory,
            'amazonSource' => $hasSavedAmazonInventory ? 'survey' : ($this->amazon_source === Survey::AMAZON_SOURCE_SP_API ? 'sp_api' : null),
            'amazonInventoryStatus' => $amazonStatus,
            'amazonInventoryImportId' => $this->amazon_source === Survey::AMAZON_SOURCE_SP_API && $this->amazon_inventory_import_id !== null
                ? (string) $this->amazon_inventory_import_id : null,
            'amazonBasisAt' => $hasSavedAmazonInventory ? $this->executed_at->toISOString() : $this->amazonImport?->basis_at?->toISOString(),
            'products' => $this->products->map(fn ($product): array => [
                'id' => (string) $product->getKey(),
                'productCode' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'parentAsin' => $product->parent_asin ?? '',
                'memo' => $product->memo ?? '',
                'groups' => $this->memberships($product),
                'skus' => $product->skus->map(function ($sku): array {
                    $amazonTotal = $sku->amazon_own_stock === null || $sku->amazon_fba_stock === null
                        ? null
                        : $sku->amazon_own_stock + $sku->amazon_fba_stock;
                    $amazonContribution = $amazonTotal ?? ($this->amazon_source === Survey::AMAZON_SOURCE_SP_API && ! $sku->hasAmazonAsin() ? 0 : null);
                    $bossTotal = $sku->boss_own_stock === null || $sku->boss_rfc_stock === null
                        ? null : $sku->boss_own_stock + $sku->boss_rfc_stock;
                    $mallTotal = $amazonContribution === null || $bossTotal === null ? null : $amazonContribution + $bossTotal;

                    return [
                        'id' => (string) $sku->getKey(),
                        'skuCode' => $sku->sku_code,
                        'colorName' => '',
                        'size' => $sku->tq_size,
                        'asin' => $sku->child_asin ?? '',
                        'tqCode' => $sku->tq_item_no,
                        'tqColorNo' => $sku->tq_color_no,
                        'tqSize' => $sku->tq_size,
                        'stock' => [
                            'amazonOwn' => $sku->amazon_own_stock,
                            'amazonFba' => $sku->amazon_fba_stock,
                            'amazonTotal' => $amazonTotal,
                            'bossOwn' => $sku->boss_own_stock,
                            'bossRfc' => $sku->boss_rfc_stock,
                            'freeStock' => $sku->free_stock,
                            'ecStock' => $sku->ec_stock,
                            'mallTotal' => $mallTotal,
                            'grandTotal' => $mallTotal === null || $sku->free_stock === null || $sku->ec_stock === null
                                ? null : $mallTotal + $sku->free_stock + $sku->ec_stock,
                        ],
                        'memo' => $sku->memo ?? '',
                    ];
                })->values(),
            ])->values(),
            'files' => $this->files->map(fn ($file): array => [
                'id' => (string) $file->getKey(),
                'type' => $file->file_type,
                'fileName' => $file->original_name,
                'uploadedAt' => $file->uploaded_at->toISOString(),
                'sizeKb' => (int) ceil($file->size_bytes / 1024),
            ])->values(),
        ];
    }

    /** @return array<string, string> */
    private function settingKeysByName(): array
    {
        return $this->settingKeysByName ??= $this->products
            ->flatMap(fn (SurveyProduct $product): array => $product->group_memberships ?? [])
            ->filter(fn (array $membership): bool => str_starts_with($membership['key'], 'setting:'))
            ->pluck('key', 'name')
            ->all();
    }

    /** @return array<int, array{key: string, name: string, sortOrder: int}> */
    private function memberships(SurveyProduct $product): array
    {
        if ($product->group_memberships !== null) {
            // 再照合前の重複（`legacy:名前` と同名の `setting:ID`）が保存済みの調査も、1 つのグループとして返す
            return SurveyProduct::mergeGroupMemberships($product->group_memberships, $this->settingKeysByName());
        }

        return $this->import_setting_name === null ? [] : [[
            'key' => 'legacy:'.$this->import_setting_name,
            'name' => $this->import_setting_name,
            'sortOrder' => $product->sort_order,
        ]];
    }
}
