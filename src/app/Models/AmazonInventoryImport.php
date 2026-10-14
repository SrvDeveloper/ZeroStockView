<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    /** @return HasMany<AmazonInventorySnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(AmazonInventorySnapshot::class)->orderBy('sku_code');
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
            'snapshot_date' => 'date',
            'active_key' => 'integer',
            'target_sku_count' => 'integer',
            'own_row_count' => 'integer',
            'fba_row_count' => 'integer',
            'matched_own_count' => 'integer',
            'matched_fba_count' => 'integer',
        ];
    }
}
