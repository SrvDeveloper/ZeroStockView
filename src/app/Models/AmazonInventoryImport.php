<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'status', 'trigger_source', 'source', 'active_key', 'started_at', 'completed_at',
    'own_retrieved_at', 'fba_retrieved_at', 'basis_at', 'snapshot_date',
    'target_sku_count', 'own_row_count', 'fba_row_count',
    'matched_own_count', 'matched_fba_count', 'error_message',
])]
class AmazonInventoryImport extends Model
{
    /**
     * スナップショットを残す日数。スナップショットは調査にAmazon在庫を写すためだけに使い、
     * 取込ファイル（アップロードから30日）で調査を作り直せる間だけ残す。
     */
    public const SNAPSHOT_RETENTION_DAYS = 30;

    /** この日より前の取込のスナップショットは消す（取込ファイルの保存期限が切れたあとに消える） */
    public static function snapshotCutoffDate(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Tokyo')->startOfDay()->subDays(self::SNAPSHOT_RETENTION_DAYS);
    }

    /** 保存期間を過ぎて、スナップショットを消してよい取込か */
    public function snapshotsExpired(): bool
    {
        return $this->snapshot_date !== null && $this->snapshot_date->lt(self::snapshotCutoffDate());
    }

    /** @return HasMany<AmazonInventorySnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(AmazonInventorySnapshot::class)->orderBy('sku_code');
    }

    /** @return Attribute<CarbonImmutable|null, string|null> */
    protected function snapshotDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), 'Asia/Tokyo'),
            set: fn ($value): ?string => $value === null ? null : (is_string($value) ? substr($value, 0, 10) : CarbonImmutable::instance($value)->setTimezone('Asia/Tokyo')->toDateString()),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'own_retrieved_at' => 'datetime',
            'fba_retrieved_at' => 'datetime',
            'basis_at' => 'datetime',
            'active_key' => 'integer',
            'target_sku_count' => 'integer',
            'own_row_count' => 'integer',
            'fba_row_count' => 'integer',
            'matched_own_count' => 'integer',
            'matched_fba_count' => 'integer',
        ];
    }
}
