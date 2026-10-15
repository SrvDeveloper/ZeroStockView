<?php

namespace App\Http\Resources;

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
        $amazonSnapshots = $this->amazonImport?->snapshots->keyBy('sku_code') ?? collect();

        return [
            'id' => (string) $this->getKey(),
            'executedAt' => $this->executed_at->toISOString(),
            'createdAt' => $this->created_at->toISOString(),
            'importSettingName' => $this->import_setting_name,
            'includesAmazonInventory' => $hasSavedAmazonInventory,
            'amazonSource' => $hasSavedAmazonInventory ? 'survey' : ($this->amazonImport === null ? null : 'sp_api'),
            'amazonBasisAt' => $hasSavedAmazonInventory ? $this->executed_at->toISOString() : $this->amazonImport?->basis_at?->toISOString(),
            'products' => $this->products->map(fn ($product): array => [
                'id' => (string) $product->getKey(),
                'productCode' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'parentAsin' => $product->parent_asin ?? '',
                'memo' => $product->memo ?? '',
                'skus' => $product->skus->map(fn ($sku): array => [
                    'id' => (string) $sku->getKey(),
                    'skuCode' => $sku->sku_code,
                    'colorName' => '',
                    'size' => $sku->tq_size,
                    'asin' => $sku->child_asin ?? '',
                    'tqCode' => $sku->tq_item_no,
                    'tqColorNo' => $sku->tq_color_no,
                    'tqSize' => $sku->tq_size,
                    'stock' => [
                        'amazonOwn' => $hasSavedAmazonInventory ? $sku->amazon_own_stock : $amazonSnapshots->get($sku->sku_code)?->amazon_own_stock,
                        'amazonFba' => $hasSavedAmazonInventory ? $sku->amazon_fba_stock : $amazonSnapshots->get($sku->sku_code)?->amazon_fba_stock,
                        'bossOwn' => $sku->boss_own_stock,
                        'bossRfc' => $sku->boss_rfc_stock,
                        'freeStock' => $sku->free_stock,
                        'ecStock' => $sku->ec_stock,
                    ],
                    'memo' => $sku->memo ?? '',
                ])->values(),
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
