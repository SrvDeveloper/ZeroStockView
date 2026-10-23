<?php

namespace App\Models;

use Database\Factories\BossInventoryHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOSS の在庫変動履歴の取込記録（docs/BOSS在庫の休日補完 BHR-03）。
 * ファイルは取込ファイルと同じく30日で消す。消したあとも、どの日をどの履歴で補完したかを残すため、記録の行は残す。
 */
#[Fillable(['original_name', 'disk', 'stored_path', 'mime_type', 'size_bytes', 'period_from', 'period_to', 'row_count', 'summary', 'uploaded_by', 'uploaded_at', 'expires_at'])]
class BossInventoryHistory extends Model
{
    /** @use HasFactory<BossInventoryHistoryFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<Survey, $this> この履歴で BOSS 在庫を補完した調査 */
    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'summary' => 'array',
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
