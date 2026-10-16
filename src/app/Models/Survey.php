<?php

namespace App\Models;

use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['executed_at', 'survey_date', 'import_setting_name', 'includes_amazon_inventory', 'amazon_source', 'amazon_inventory_import_id'])]
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

    public const AMAZON_SOURCE_LEGACY_FILE = 'legacy_file';

    public const AMAZON_SOURCE_SP_API = 'sp_api';

    /** @return HasMany<SurveyProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(SurveyProduct::class)->orderBy('sort_order');
    }

    /** @return HasMany<SurveyFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(SurveyFile::class)->orderBy('file_type');
    }

    /** @return BelongsTo<AmazonInventoryImport, $this> */
    public function amazonImport(): BelongsTo
    {
        return $this->belongsTo(AmazonInventoryImport::class, 'amazon_inventory_import_id');
    }

    public function hasSavedAmazonInventory(): bool
    {
        if ($this->amazon_source === self::AMAZON_SOURCE_SP_API) {
            return false;
        }
        if ($this->amazon_source === self::AMAZON_SOURCE_LEGACY_FILE) {
            return true;
        }
        if ($this->includes_amazon_inventory) {
            return true;
        }

        return $this->products->contains(fn ($product): bool => $product->skus->contains(
            fn ($sku): bool => $sku->amazon_own_stock > 0 || $sku->amazon_fba_stock > 0,
        ));
    }

    public function amazonInventoryStatus(): string
    {
        if ($this->amazon_source === self::AMAZON_SOURCE_SP_API) {
            if ($this->amazon_inventory_import_id === null) {
                return 'pending';
            }

            return $this->products->contains(fn ($product): bool => $product->skus->contains(
                fn ($sku): bool => ($sku->hasAmazonAsin() || $sku->amazon_own_stock !== null || $sku->amazon_fba_stock !== null)
                    && ($sku->amazon_own_stock === null || $sku->amazon_fba_stock === null),
            )) ? 'partial' : 'complete';
        }

        return $this->hasSavedAmazonInventory() ? 'legacy_file' : 'unclassified';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'includes_amazon_inventory' => 'boolean',
        ];
    }
}
