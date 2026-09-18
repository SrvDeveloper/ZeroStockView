<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_code', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'synced_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @return HasMany<Sku, $this> */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class)->orderBy('sort_order');
    }

    /** @return HasMany<ImportTarget, $this> */
    public function importTargets(): HasMany
    {
        return $this->hasMany(ImportTarget::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
