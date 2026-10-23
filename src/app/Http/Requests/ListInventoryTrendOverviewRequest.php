<?php

namespace App\Http\Requests;

use App\Rules\MaximumDateRange;
use App\Services\InventoryTrendOverviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryTrendOverviewRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', new MaximumDateRange($this->input('from'))],
            'scope' => ['required', Rule::in(['mallTotal', 'amazon', 'boss', 'free', 'stock', 'grandTotal'])],
            'group' => ['nullable', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'focus' => ['nullable', Rule::in(InventoryTrendOverviewService::FOCUSES)],
            'sort' => ['nullable', Rule::in(InventoryTrendOverviewService::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
