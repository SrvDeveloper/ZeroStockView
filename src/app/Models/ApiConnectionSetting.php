<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['enabled', 'api_key_hash', 'api_key_suffix', 'api_key_issued_at', 'allowed_networks'])]
class ApiConnectionSetting extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key_issued_at' => 'datetime',
            'allowed_networks' => 'array',
        ];
    }
}
