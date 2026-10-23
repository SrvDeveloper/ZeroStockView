<?php

namespace App\Models;

use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['executed_at', 'survey_date', 'import_setting_name', 'includes_amazon_inventory', 'amazon_source', 'amazon_inventory_import_id', 'boss_source', 'boss_basis_at', 'boss_inventory_history_id'])]
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

    public const AMAZON_SOURCE_LEGACY_FILE = 'legacy_file';

    public const AMAZON_SOURCE_SP_API = 'sp_api';

    /** BOSS 在庫を、BOSS のレポート（倉庫毎の在庫数レポート）から取り込んだ */
    public const BOSS_SOURCE_REPORT = 'report';

    /** BOSS 在庫を、在庫変動履歴から再現した（boss_basis_at の時点。docs/BOSS在庫の休日補完） */
    public const BOSS_SOURCE_HISTORY = 'history';

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

    /** @return BelongsTo<BossInventoryHistory, $this> BOSS 在庫を再現した在庫変動履歴 */
    public function bossInventoryHistory(): BelongsTo
    {
        return $this->belongsTo(BossInventoryHistory::class);
    }

    /**
     * Amazon在庫をSP-APIの同日取込から反映する調査か。旧5ファイル調査と取得方式が未確定の調査は false。
     * Amazon在庫の再同期と再照合は、この調査だけを対象にする（旧方式の保存値を上書きしないため）。
     */
    public function usesSpApiAmazonInventory(): bool
    {
        return $this->amazon_source === self::AMAZON_SOURCE_SP_API && ! $this->includes_amazon_inventory;
    }

    public function hasSavedAmazonInventory(): bool
    {
        return $this->resolveHasSavedAmazonInventory(fn (): bool => $this->products->contains(fn ($product): bool => $product->skus->contains(
            fn ($sku): bool => $sku->amazon_own_stock > 0 || $sku->amazon_fba_stock > 0,
        )));
    }

    public function amazonInventoryStatus(): string
    {
        return $this->resolveAmazonInventoryStatus(
            fn (): bool => $this->products->contains(fn ($product): bool => $product->skus->contains(
                fn ($sku): bool => ($sku->hasAmazonAsin() || $sku->amazon_own_stock !== null || $sku->amazon_fba_stock !== null)
                    && ($sku->amazon_own_stock === null || $sku->amazon_fba_stock === null),
            )),
            fn (): bool => $this->hasSavedAmazonInventory(),
        );
    }

    /**
     * Amazon在庫を調査に保存しているか（旧方式）。SKUを読み込まずに判定したいとき（一覧の集計）は、
     * 「Amazon在庫が1以上のSKUがあるか」を集計値で渡す。
     *
     * @param  callable(): bool  $hasPositiveAmazonStock
     */
    public function resolveHasSavedAmazonInventory(callable $hasPositiveAmazonStock): bool
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

        return $hasPositiveAmazonStock();
    }

    /**
     * Amazon在庫の反映状況。SP-API調査で「ASINがある（または値がある）のにAmazon在庫が揃っていないSKUがあるか」と、
     * 旧方式の判定を渡す。
     *
     * @param  callable(): bool  $hasIncompleteAmazonStock
     * @param  callable(): bool  $hasSavedAmazonInventory
     */
    public function resolveAmazonInventoryStatus(callable $hasIncompleteAmazonStock, callable $hasSavedAmazonInventory): string
    {
        if ($this->amazon_source === self::AMAZON_SOURCE_SP_API) {
            if ($this->amazon_inventory_import_id === null) {
                return 'pending';
            }

            return $hasIncompleteAmazonStock() ? 'partial' : 'complete';
        }

        return $hasSavedAmazonInventory() ? 'legacy_file' : 'unclassified';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'includes_amazon_inventory' => 'boolean',
            'boss_basis_at' => 'datetime',
        ];
    }
}
