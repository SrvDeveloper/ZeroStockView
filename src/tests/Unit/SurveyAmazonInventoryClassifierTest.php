<?php

namespace Tests\Unit;

use App\Services\SurveyAmazonInventoryClassifier;
use PHPUnit\Framework\TestCase;

class SurveyAmazonInventoryClassifierTest extends TestCase
{
    public function test_expired_file_records_do_not_turn_zero_stock_legacy_history_into_three_file_history(): void
    {
        $classifier = new SurveyAmazonInventoryClassifier;

        $this->assertSame('ambiguous', $classifier->classify(true, null, [], 1, 0)[0]);
        $this->assertSame('legacy_file', $classifier->classify(true, null, [], 1, 1)[0]);
        $this->assertSame('three_file', $classifier->classify(false, null, [], 1, 0)[0]);
    }

    public function test_conflicting_evidence_is_excluded_from_automatic_migration(): void
    {
        $classifier = new SurveyAmazonInventoryClassifier;

        $this->assertSame('ambiguous', $classifier->classify(false, null, ['在庫商品レポート'], 1, 0)[0]);
        $this->assertSame('ambiguous', $classifier->classify(false, null, ['倉庫毎の在庫数レポート'], 1, 1)[0]);
        $this->assertSame('ambiguous', $classifier->classify(true, 4, ['FBA在庫管理レポート'], 1, 1)[0]);
        $this->assertSame('ambiguous', $classifier->classify(true, null, ['不明なファイル'], 1, 0)[0]);
        $this->assertSame('ambiguous', $classifier->classify(false, null, [], 0, 0)[0]);
    }
}
