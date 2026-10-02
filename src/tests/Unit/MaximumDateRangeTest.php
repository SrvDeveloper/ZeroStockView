<?php

namespace Tests\Unit;

use App\Rules\MaximumDateRange;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MaximumDateRangeTest extends TestCase
{
    public function test_it_allows_up_to_365_calendar_days_inclusively(): void
    {
        $validator = Validator::make(
            ['to' => '2027-09-30'],
            ['to' => [new MaximumDateRange('2026-10-01')]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_it_rejects_366_calendar_days(): void
    {
        $validator = Validator::make(
            ['to' => '2027-10-01'],
            ['to' => [new MaximumDateRange('2026-10-01')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('対象期間は365日以内で指定してください。', $validator->errors()->first('to'));
    }
}
