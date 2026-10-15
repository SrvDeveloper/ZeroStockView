<?php

namespace App\Http\Resources;

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
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
            'importSettingName' => $this->import_setting_name,
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
                'skus' => $product->skus->map(function ($sku): array {
                    $amazonTotal = $sku->amazon_own_stock === null || $sku->amazon_fba_stock === null
                        ? null
                        : $sku->amazon_own_stock + $sku->amazon_fba_stock;

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
                            'mallTotal' => $amazonTotal === null ? null : $amazonTotal + $sku->boss_own_stock + $sku->boss_rfc_stock,
                            'grandTotal' => $amazonTotal === null ? null : $amazonTotal + $sku->boss_own_stock + $sku->boss_rfc_stock + $sku->free_stock + $sku->ec_stock,
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
}
