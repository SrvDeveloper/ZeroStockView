<?php

namespace App\Models;

use Database\Factories\SurveyFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['survey_id', 'file_type', 'original_name', 'disk', 'stored_path', 'mime_type', 'size_bytes', 'uploaded_at', 'expires_at'])]
class SurveyFile extends Model
{
    /** @use HasFactory<SurveyFileFactory> */
    use HasFactory;

    /** @return BelongsTo<Survey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
