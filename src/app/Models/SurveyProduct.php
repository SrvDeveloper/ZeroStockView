<?php

namespace App\Models;

use Database\Factories\SurveyProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['survey_id', 'product_code', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'sort_order', 'memo'])]
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
        ];
    }
}
