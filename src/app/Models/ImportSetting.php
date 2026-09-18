<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ImportSetting extends Model
{
    /** @return HasMany<ImportTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(ImportTarget::class)->orderBy('sort_order');
    }
}
