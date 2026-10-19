<?php

namespace App\Models;

use Database\Factories\SurveyProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['survey_id', 'product_code', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'sort_order', 'group_memberships', 'memo'])]
class SurveyProduct extends Model
{
    /** @use HasFactory<SurveyProductFactory> */
    use HasFactory;

    /** @return BelongsTo<Survey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** @return HasMany<SurveySku, $this> */
    public function skus(): HasMany
    {
        return $this->hasMany(SurveySku::class)->orderBy('sort_order');
    }

    /**
     * 品番グループの所属を、キーの重複なくまとめる。
     * 改修前の調査の所属（`legacy:グループ名`）は、同じ名前の現在のグループ（`setting:ID`）があればそのキーに寄せる。
     * グループ名は一意なので、同じ名前は同じグループとみなす。同じキーが重なれば先のものを残す。
     *
     * @param  iterable<array{key: string, name: string, sortOrder: int}>  $memberships
     * @param  array<string, string>  $settingKeysByName  グループ名 => `setting:ID`
     * @return list<array{key: string, name: string, sortOrder: int}>
     */
    public static function mergeGroupMemberships(iterable $memberships, array $settingKeysByName): array
    {
        return collect($memberships)
            ->map(fn (array $membership): array => str_starts_with($membership['key'], 'legacy:') && isset($settingKeysByName[$membership['name']])
                ? ['key' => $settingKeysByName[$membership['name']]] + $membership
                : $membership)
            ->unique('key')
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'group_memberships' => 'array',
        ];
    }
}
