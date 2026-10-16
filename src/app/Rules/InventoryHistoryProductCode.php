<?php

namespace App\Rules;

use App\Models\AmazonInventorySnapshot;
use App\Models\SurveyProduct;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InventoryHistoryProductCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('選択した品番の在庫履歴がありません。');

            return;
        }

        if (SurveyProduct::query()->where('product_code', $value)->exists()
            || AmazonInventorySnapshot::query()->where('product_code', $value)
                ->whereHas('import', fn ($query) => $query->where('status', 'succeeded'))->exists()) {
            return;
        }

        $fail('選択した品番の在庫履歴がありません。');
    }
}
