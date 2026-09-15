<?php

namespace App\Models;

use Database\Factories\SurveySkuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'survey_product_id',
    'sku_code',
    'child_asin',
    'status',
    'tq_item_no',
    'tq_color_no',
    'tq_size',
    'sort_order',
    'amazon_own_stock',
    'amazon_fba_stock',
    'boss_own_stock',
    'boss_rfc_stock',
    'free_stock',
    'ec_stock',
    'memo',
])]
class SurveySku extends Model
{
    /** @use HasFactory<SurveySkuFactory> */
    use HasFactory;

    /** @return BelongsTo<SurveyProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SurveyProduct::class, 'survey_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amazon_own_stock' => 'integer',
            'amazon_fba_stock' => 'integer',
            'boss_own_stock' => 'integer',
            'boss_rfc_stock' => 'integer',
            'free_stock' => 'integer',
            'ec_stock' => 'integer',
        ];
    }
}
