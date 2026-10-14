<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'amazon_inventory_import_id', 'product_code', 'sku_code', 'asin',
    'amazon_own_stock', 'amazon_fba_stock', 'source', 'basis_at',
])]
class AmazonInventorySnapshot extends Model
{
    /** @return BelongsTo<AmazonInventoryImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AmazonInventoryImport::class, 'amazon_inventory_import_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amazon_own_stock' => 'integer',
            'amazon_fba_stock' => 'integer',
            'basis_at' => 'datetime',
        ];
    }
}
