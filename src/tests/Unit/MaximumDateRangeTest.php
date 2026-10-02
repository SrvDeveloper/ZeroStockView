<?php

namespace Tests\Unit;

use App\Rules\MaximumDateRange;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MaximumDateRangeTest extends TestCase
{
    public function test_it_allows_up_to_31_calendar_days_inclusively(): void
    {
        $validator = Validator::make(
            ['to' => '2026-10-31'],
            ['to' => [new MaximumDateRange('2026-10-01')]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_it_rejects_32_calendar_days(): void
    {
        $validator = Validator::make(
            ['to' => '2026-11-01'],
            ['to' => [new MaximumDateRange('2026-10-01')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('対象期間は31日以内で指定してください。', $validator->errors()->first('to'));
    }
}
