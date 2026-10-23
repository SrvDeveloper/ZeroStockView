<?php

namespace Tests\Feature;

use App\Services\BossInventoryHistory;
use App\Services\BossInventoryHistoryParser;
use App\Services\BossInventoryReconstruction;
use App\Services\BossInventoryReconstructor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 在庫変動履歴からの BOSS 在庫の再現（docs/BOSS在庫の休日補完 BHR-02）。データはすべて架空。
 * 金曜（10/16）と月曜（10/19）に BOSS のレポートを取り込み、土日（10/17・10/18）を補完する。
 */
class BossInventoryReconstructorTest extends TestCase
{
    private const HEADER = '処理日時,処理種別,処理番号,SKUコード,倉庫,変動前実在庫,変動後実在庫,変動前引当済,変動後引当済';

    private const WEEKEND = ['2026-10-17', '2026-10-18'];

    /** 金曜 0:00 〜 月曜のレポートのあとまでの履歴。金曜のレポートは 8:00 ごろ、月曜のレポートは 9:30 ごろに出力した想定 */
    private const ROWS = [
        // A: 金曜 7:00 に入荷（10 → 12）、土曜 15:00 に引当（12 → 11）、月曜 9:10 に出荷実績（実在庫・引当済とも 1 減る）
        ['2026/10/16 07:00:00', '在庫調整（増）', '1', 'A', '自社倉庫', 10, 12, 0, 0],
        ['2026/10/17 15:00:00', 'API注文引当', '2', 'A', '自社倉庫', 12, 12, 0, 1],
        ['2026/10/19 9:10:00', 'API注文出荷実績', '3', 'A', '自社倉庫', 12, 11, 1, 0],
        // B: 日曜 12:00 に初めて動く（5 → 4）
        ['2026/10/18 12:00:00', 'API注文引当', '4', 'B', 'RFC倉庫', 5, 5, 0, 1],
        // 月曜のレポートのあと（10:00）の動き
        ['2026/10/19 10:00:00', 'API注文引当', '5', 'B', 'RFC倉庫', 5, 5, 1, 2],
    ];

    /** 金曜のレポート（7:00 の入荷のあと）。C は期間内に動きがない。D はレポートにない */
    private const FRIDAY = [
        'A' => ['own' => 12, 'rfc' => 0],
        'B' => ['own' => 0, 'rfc' => 5],
        'C' => ['own' => 7, 'rfc' => 3],
    ];

    /** 月曜のレポート（9:10 の出荷実績のあと、10:00 の引当の前） */
    private const MONDAY = [
        'A' => ['own' => 11, 'rfc' => 0],
        'B' => ['own' => 0, 'rfc' => 4],
        'C' => ['own' => 7, 'rfc' => 3],
    ];

    private const SKUS = ['A', 'B', 'C', 'D'];

    public function test_reconstructs_nine_oclock_from_the_last_row_before_the_first_row_after_or_the_base_survey(): void
    {
        $result = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY], self::WEEKEND);

        $this->assertNull($result->failure);
        // A: 土曜 9:00 は金曜 7:00 の変動後（12）、日曜 9:00 は土曜 15:00 の変動後（11）
        $this->assertSame(['own' => 12, 'rfc' => 0], $result->values['2026-10-17']['A']);
        $this->assertSame(['own' => 11, 'rfc' => 0], $result->values['2026-10-18']['A']);
        // B: 9:00 より後の最初の行（日曜 12:00）の変動前（5）
        $this->assertSame(['own' => 0, 'rfc' => 5], $result->values['2026-10-18']['B']);
        // C: 期間内に行がないので基準の調査の値。D: 基準の調査にもないので NULL
        $this->assertSame(['own' => 7, 'rfc' => 3], $result->values['2026-10-17']['C']);
        $this->assertSame(['own' => null, 'rfc' => null], $result->values['2026-10-17']['D']);
        $this->assertSame(['filled' => 3, 'empty' => 1, 'reasons' => [BossInventoryReconstruction::REASON_NO_DATA => 1]], $result->dateSummaries['2026-10-17']);
        $this->assertSame('2026-10-16 07:00:00', $result->reportTimes['2026-10-16']?->format('Y-m-d H:i:s'));
        $this->assertSame([], $result->mismatches);
    }

    public function test_values_carried_forward_from_friday_match_those_carried_back_from_monday(): void
    {
        $fromFriday = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY], self::WEEKEND);
        $fromMonday = $this->reconstruct(self::ROWS, ['2026-10-19' => self::MONDAY], self::WEEKEND);
        $fromBoth = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY, '2026-10-19' => self::MONDAY], self::WEEKEND);

        $this->assertSame($fromFriday->values, $fromMonday->values);
        $this->assertSame($fromFriday->values, $fromBoth->values);
        $this->assertSame(0, $fromBoth->mismatchCount);
        // 月曜のレポートは 9:10 の出荷実績のあと。出荷実績では実在庫 − 引当済は変わらないので、9:10 でも 0:00 でも合う。同じ数なら遅い方を選ぶ
        $this->assertSame('2026-10-19 09:10:00', $fromBoth->reportTimes['2026-10-19']?->format('Y-m-d H:i:s'));
    }

    public function test_skus_that_do_not_match_the_base_survey_at_the_estimated_report_time_are_null_on_every_day(): void
    {
        $friday = self::FRIDAY;
        // A の保存済みの値（9）は、どの時刻の履歴の値（10・12）とも合わない
        $friday['A']['own'] = 9;
        $result = $this->reconstruct(self::ROWS, ['2026-10-16' => $friday], self::WEEKEND);

        $this->assertSame(['own' => null, 'rfc' => 0], $result->values['2026-10-17']['A']);
        $this->assertSame(['own' => null, 'rfc' => 0], $result->values['2026-10-18']['A']);
        $this->assertSame([['skuCode' => 'A', 'warehouse' => 'own', 'baseDate' => '2026-10-16']], $result->mismatches);
        $this->assertSame(1, $result->mismatchCount);
        $this->assertSame(['2026-10-16' => 1], $result->mismatchCounts);
        $this->assertSame([BossInventoryReconstruction::REASON_MISMATCH => 1, BossInventoryReconstruction::REASON_NO_DATA => 1], $result->dateSummaries['2026-10-17']['reasons']);
    }

    public function test_estimates_the_report_time_from_the_time_most_skus_match(): void
    {
        $rows = [
            ['2026/10/16 06:00:00', '在庫調整（増）', '1', 'A', '自社倉庫', 1, 2, 0, 0],
            ['2026/10/16 08:30:00', '在庫調整（増）', '2', 'B', '自社倉庫', 1, 2, 0, 0],
            ['2026/10/16 11:00:00', '在庫調整（増）', '3', 'C', '自社倉庫', 1, 2, 0, 0],
            ['2026/10/18 11:00:00', '在庫調整（増）', '4', 'A', '自社倉庫', 2, 3, 0, 0],
        ];
        // 8:30 の B の動きのあと、11:00 の C の動きの前に出力したレポート
        $base = ['A' => ['own' => 2, 'rfc' => 0], 'B' => ['own' => 2, 'rfc' => 0], 'C' => ['own' => 1, 'rfc' => 0]];
        $result = $this->reconstruct($rows, ['2026-10-16' => $base], self::WEEKEND, ['A', 'B', 'C']);

        $this->assertSame('2026-10-16 08:30:00', $result->reportTimes['2026-10-16']?->format('Y-m-d H:i:s'));
        $this->assertSame(['own' => 2, 'rfc' => 0], $result->values['2026-10-17']['C']);
        // 日曜 9:00 は 11:00 の動きの前
        $this->assertSame(['own' => 2, 'rfc' => 0], $result->values['2026-10-18']['A']);
    }

    public function test_breaks_across_nine_oclock_and_negative_values_are_null_with_their_reasons(): void
    {
        $rows = [
            ...self::ROWS,
            // E: 日曜 8:30 の変動後（7）と日曜 10:00 の変動前（4）がつながらない。日曜 9:00 は決まらない（土曜 9:00 は決まる）
            ['2026/10/17 08:00:00', '在庫調整（増）', '6', 'E', '自社倉庫', 5, 6, 0, 0],
            ['2026/10/18 08:30:00', '在庫調整（増）', '9', 'E', '自社倉庫', 6, 7, 0, 0],
            ['2026/10/18 10:00:00', '在庫調整（減）', '7', 'E', '自社倉庫', 4, 3, 0, 0],
            // F: 9:00 の値（実在庫 1 − 引当済 2）がマイナス
            ['2026/10/16 08:00:00', 'API注文引当', '8', 'F', 'RFC倉庫', 1, 1, 1, 2],
        ];
        $friday = self::FRIDAY + ['E' => ['own' => 5, 'rfc' => 0], 'F' => ['own' => 0, 'rfc' => -1]];
        $result = $this->reconstruct($rows, ['2026-10-16' => $friday], self::WEEKEND, ['A', 'B', 'C', 'E', 'F']);

        $this->assertSame(['own' => 6, 'rfc' => 0], $result->values['2026-10-17']['E']);
        $this->assertSame(['own' => null, 'rfc' => 0], $result->values['2026-10-18']['E']);
        $this->assertSame(['own' => 0, 'rfc' => null], $result->values['2026-10-17']['F']);
        $this->assertSame([BossInventoryReconstruction::REASON_NEGATIVE => 1], $result->dateSummaries['2026-10-17']['reasons']);
        $this->assertSame([BossInventoryReconstruction::REASON_BREAK => 1, BossInventoryReconstruction::REASON_NEGATIVE => 1], $result->dateSummaries['2026-10-18']['reasons']);
        // 確認画面に出す、つながりの切れ目・マイナスで NULL にした SKU・倉庫
        $this->assertSame([
            ['skuCode' => 'E', 'warehouse' => 'own', 'reason' => BossInventoryReconstruction::REASON_BREAK],
            ['skuCode' => 'F', 'warehouse' => 'rfc', 'reason' => BossInventoryReconstruction::REASON_NEGATIVE],
        ], $result->emptiedCells);
    }

    public function test_days_whose_nine_oclock_is_outside_the_history_cannot_be_filled(): void
    {
        $result = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY], [...self::WEEKEND, '2026-10-20']);

        $this->assertSame(['2026-10-20' => '9:00 が在庫変動履歴の期間の外です。'], $result->unavailableDates);
        $this->assertArrayNotHasKey('2026-10-20', $result->values);
        $this->assertArrayHasKey('2026-10-18', $result->values);
    }

    public function test_equal_shifts_of_stock_and_reserved_still_match_the_base_survey(): void
    {
        $rows = [
            ['2026/10/16 06:00:00', 'API注文引当', '1', 'A', '自社倉庫', 10, 10, 0, 1],
            // 出荷が履歴に出ず、実在庫と引当済が同じ 1 だけ減っている（10 − 1 → 9 − 0 で、実在庫 − 引当済は 9 のまま）
            ['2026/10/17 10:00:00', 'API注文引当', '2', 'A', '自社倉庫', 9, 9, 0, 1],
            ['2026/10/19 08:00:00', 'API注文出荷実績', '3', 'A', '自社倉庫', 9, 8, 1, 0],
        ];
        $result = $this->reconstruct($rows, ['2026-10-16' => ['A' => ['own' => 9, 'rfc' => 0]]], self::WEEKEND, ['A']);

        $this->assertSame(0, $result->mismatchCount);
        $this->assertSame(['own' => 9, 'rfc' => 0], $result->values['2026-10-17']['A']);
        $this->assertSame(['own' => 8, 'rfc' => 0], $result->values['2026-10-18']['A']);
    }

    public function test_cannot_reconstruct_without_a_usable_base_survey(): void
    {
        // 履歴は金曜 10:00 から。保存済みの値は 10:00 の動きの前なので、レポートは履歴の始まりより前に出力されている
        $rows = [['2026/10/16 10:00:00', '在庫調整（増）', '1', 'A', '自社倉庫', 1, 2, 0, 0]];
        $result = $this->reconstruct($rows, ['2026-10-16' => ['A' => ['own' => 1, 'rfc' => 0]], '2026-10-13' => ['A' => ['own' => 1, 'rfc' => 0]]], self::WEEKEND, ['A']);

        $this->assertSame('在庫変動履歴の期間に、使える BOSS のレポートを取り込んだ調査がありません。', $result->failure);
        $this->assertSame([
            '2026-10-13' => '基準の調査の日が、在庫変動履歴の期間に入っていません。',
            '2026-10-16' => 'レポートを出力した時刻が、在庫変動履歴の最初の行より前です。',
        ], $result->unusableBaseDates);
        $this->assertSame([], $result->values);
    }

    public function test_skus_without_rows_whose_base_surveys_disagree_are_treated_as_mismatches(): void
    {
        $monday = self::MONDAY;
        $monday['C']['own'] = 8;
        $result = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY, '2026-10-19' => $monday], self::WEEKEND);

        $this->assertSame(['own' => null, 'rfc' => 3], $result->values['2026-10-17']['C']);
        $this->assertSame([['skuCode' => 'C', 'warehouse' => 'own', 'baseDate' => '2026-10-19']], $result->mismatches);
    }

    public function test_a_sku_missing_from_one_base_survey_takes_the_value_of_another_without_a_mismatch(): void
    {
        $monday = self::MONDAY;
        // C は月曜のレポートに載っていない（NULL）。土曜・日曜は金曜の値を使い、合わないものとはみなさない
        $monday['C'] = ['own' => null, 'rfc' => null];
        $result = $this->reconstruct(self::ROWS, ['2026-10-16' => self::FRIDAY, '2026-10-19' => $monday], self::WEEKEND);

        $this->assertSame(0, $result->mismatchCount);
        $this->assertSame(['own' => 7, 'rfc' => 3], $result->values['2026-10-18']['C']);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}>  $rows
     * @param  array<string, array<string, array{own: int|null, rfc: int|null}>>  $baseSurveys
     * @param  list<string>  $dates
     * @param  list<string>  $skuCodes
     */
    private function reconstruct(array $rows, array $baseSurveys, array $dates, array $skuCodes = self::SKUS): BossInventoryReconstruction
    {
        return app(BossInventoryReconstructor::class)->reconstruct($this->history($rows), $skuCodes, $baseSurveys, $dates);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}> $rows */
    private function history(array $rows): BossInventoryHistory
    {
        $csv = self::HEADER."\n".implode("\n", array_map(fn (array $row): string => implode(',', $row), $rows))."\n";

        return app(BossInventoryHistoryParser::class)->parse(UploadedFile::fake()->createWithContent('history.csv', $csv));
    }
}
