<?php

namespace App\Http\Requests;

use App\Rules\MaximumDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowInventoryTrendRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_code' => ['required', 'string', 'max:100', Rule::exists('survey_products', 'product_code')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', new MaximumDateRange($this->input('from'))],
            'scope' => ['required', Rule::in(['mallTotal', 'amazon', 'boss', 'free', 'stock', 'grandTotal'])],
        ];
    }
}
