<?php

namespace App\Models;

use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['executed_at'])]
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }
}
