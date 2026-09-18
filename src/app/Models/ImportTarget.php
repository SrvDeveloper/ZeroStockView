<?php

namespace App\Models;

use Database\Factories\ImportTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_setting_id', 'product_id', 'sort_order'])]
class ImportTarget extends Model
{
    /** @use HasFactory<ImportTargetFactory> */
    use HasFactory;

    /** @return BelongsTo<ImportSetting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(ImportSetting::class, 'import_setting_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
