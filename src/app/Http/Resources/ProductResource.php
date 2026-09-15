<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'productCode' => $this->product_code,
            'brand' => $this->brand,
            'category' => $this->category,
            'parentAsin' => $this->parent_asin ?? '',
            'skus' => $this->skus->map(fn ($sku): array => [
                'skuCode' => $sku->sku_code,
                'colorName' => '',
                'size' => $sku->tq_size,
                'asin' => $sku->child_asin ?? '',
                'tqCode' => $sku->tq_item_no,
                'tqColorNo' => $sku->tq_color_no,
                'tqSize' => $sku->tq_size,
            ])->values(),
        ];
    }
}
