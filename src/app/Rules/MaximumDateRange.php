<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaximumDateRange implements ValidationRule
{
    public const MAXIMUM_DAYS = 31;

    public function __construct(private readonly mixed $from) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($this->from) || ! is_string($value)) {
            return;
        }

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->from);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return;
        }

        if ($to->greaterThan($from->addDays(self::MAXIMUM_DAYS - 1))) {
            $fail('対象期間は31日以内で指定してください。');
        }
    }
}
