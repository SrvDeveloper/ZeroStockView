<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListInventoryTrendProductsRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'brand' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
        ];
    }
}
