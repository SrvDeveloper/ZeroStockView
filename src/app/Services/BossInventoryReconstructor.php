<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * 在庫変動履歴（BHR-01）と、期間内に BOSS のレポートを取り込んだ調査（基準の調査）から、
 * 補完する日の 9:00 時点の BOSS 在庫を SKU・倉庫ごとに計算する。データベースには書き込まない（docs/BOSS在庫の休日補完 BHR-02）。
 *
 * ある時刻 t の「実在庫 − 引当済」は、t 以前の最後の行の変動後、なければ t より後の最初の行の変動前、
 * 期間内に行がなければ基準の調査の値とする（README の「再現の考え方」）。
 */
class BossInventoryReconstructor
{
    /** 再現する時刻（日本時間。README の前提 1） */
    public const BASIS_TIME = '09:00:00';

    private const TIMEZONE = 'Asia/Tokyo';

    private const MISMATCH_LIMIT = 100;

    private const WAREHOUSES = ['own', 'rfc'];

    /**
     * @param  list<string>  $skuCodes  対象の SKU コード
     * @param  array<string, array<string, array{own: int|null, rfc: int|null}>>  $baseSurveys  基準の調査の日 => SKUコード => 保存済みの BOSS 在庫
     * @param  list<string>  $dates  補完する日（Y-m-d）
     */
    public function reconstruct(BossInventoryHistory $history, array $skuCodes, array $baseSurveys, array $dates): BossInventoryReconstruction
    {
        $reportTimes = [];
        $unusableBaseDates = [];
        /** @var array<string, array<string, string>> $mismatched SKUコード => 倉庫 => 合わなかった基準の調査の日 */
        $mismatched = [];
        ksort($baseSurveys, SORT_STRING);
        $targets = array_flip($skuCodes);

        foreach ($baseSurveys as $baseDate => $stocks) {
            if ($history->from === null || $baseDate < $history->from->toDateString() || $baseDate > $history->to->toDateString()) {
                $reportTimes[$baseDate] = null;
                $unusableBaseDates[$baseDate] = '基準の調査の日が、在庫変動履歴の期間に入っていません。';

                continue;
            }
            $reportTime = $this->estimateReportTime($history, $baseDate, $stocks, $targets);
            if ($reportTime->lt($history->from)) {
                // レポートの出力から履歴の始まりまでの動きが抜けている（期間内に行のない SKU では気づけない）
                $reportTimes[$baseDate] = null;
                $unusableBaseDates[$baseDate] = 'レポートを出力した時刻が、在庫変動履歴の最初の行より前です。';

                continue;
            }
            $reportTimes[$baseDate] = $reportTime;
            foreach ($history->entries as $skuCode => $warehouses) {
                if (! isset($targets[$skuCode])) {
                    continue;
                }
                foreach ($warehouses as $warehouse => $entries) {
                    $stored = $stocks[$skuCode][$warehouse] ?? null;
                    if ($stored !== null && $this->valueAt($entries, $reportTime) !== $stored) {
                        $mismatched[$skuCode][$warehouse] ??= $baseDate;
                    }
                }
            }
        }

        $usableBases = array_filter($reportTimes);
        if ($usableBases === []) {
            return new BossInventoryReconstruction([], [], [], $reportTimes, $unusableBaseDates, [], 0, [], [],
                '在庫変動履歴の期間に、使える BOSS のレポートを取り込んだ調査がありません。');
        }

        // 期間内に行のない SKU・倉庫は基準の調査の値になる。基準の調査どうしで値が違えば、合わないものとして扱う
        // （基準の調査のレポートに載っていない（NULL）だけなら、違うとはみなさない）
        foreach ($skuCodes as $skuCode) {
            foreach (self::WAREHOUSES as $warehouse) {
                if (isset($history->entries[$skuCode][$warehouse]) || isset($mismatched[$skuCode][$warehouse])) {
                    continue;
                }
                $values = array_filter(array_map(fn (string $baseDate): ?int => $baseSurveys[$baseDate][$skuCode][$warehouse] ?? null, array_keys($usableBases)), fn (?int $value): bool => $value !== null);
                if (count(array_unique($values)) > 1) {
                    $mismatched[$skuCode][$warehouse] = (string) array_key_last($usableBases);
                }
            }
        }

        $values = [];
        $dateSummaries = [];
        $unavailableDates = [];
        /** @var array<string, array<string, string>> $emptied SKUコード => 倉庫 => つながりの切れ目・マイナスで NULL にした理由 */
        $emptied = [];
        foreach ($dates as $date) {
            $basis = CarbonImmutable::parse("{$date} ".self::BASIS_TIME, self::TIMEZONE);
            if ($basis->lt($history->from) || $basis->gt($history->to)) {
                $unavailableDates[$date] = '9:00 が在庫変動履歴の期間の外です。';

                continue;
            }
            $nearestBases = $this->baseDatesByDistance(array_keys($usableBases), $date);
            $summary = ['filled' => 0, 'empty' => 0, 'reasons' => []];
            foreach ($skuCodes as $skuCode) {
                $stock = [];
                $reasons = [];
                foreach (self::WAREHOUSES as $warehouse) {
                    [$stock[$warehouse], $reason] = $this->valueOnDate($history, $baseSurveys, $nearestBases, $mismatched, $skuCode, $warehouse, $basis);
                    if ($reason !== null) {
                        $reasons[$reason] = true;
                    }
                    if ($reason === BossInventoryReconstruction::REASON_BREAK || $reason === BossInventoryReconstruction::REASON_NEGATIVE) {
                        $emptied[$skuCode][$warehouse] ??= $reason;
                    }
                }
                $values[$date][$skuCode] = $stock;
                if ($stock['own'] !== null && $stock['rfc'] !== null) {
                    $summary['filled']++;

                    continue;
                }
                $summary['empty']++;
                foreach (array_keys($reasons) as $reason) {
                    $summary['reasons'][$reason] = ($summary['reasons'][$reason] ?? 0) + 1;
                }
            }
            ksort($summary['reasons'], SORT_STRING);
            $dateSummaries[$date] = $summary;
        }

        $mismatches = [];
        $mismatchCounts = array_fill_keys(array_keys($usableBases), 0);
        ksort($mismatched, SORT_STRING);
        foreach ($mismatched as $skuCode => $warehouses) {
            foreach ($warehouses as $warehouse => $baseDate) {
                $mismatches[] = ['skuCode' => (string) $skuCode, 'warehouse' => $warehouse, 'baseDate' => $baseDate];
                $mismatchCounts[$baseDate]++;
            }
        }
        $emptiedCells = [];
        ksort($emptied, SORT_STRING);
        foreach ($emptied as $skuCode => $warehouses) {
            ksort($warehouses);
            foreach ($warehouses as $warehouse => $reason) {
                $emptiedCells[] = ['skuCode' => (string) $skuCode, 'warehouse' => $warehouse, 'reason' => $reason];
            }
        }

        return new BossInventoryReconstruction(
            $values,
            $dateSummaries,
            $unavailableDates,
            $reportTimes,
            $unusableBaseDates,
            array_slice($mismatches, 0, self::MISMATCH_LIMIT),
            count($mismatches),
            $mismatchCounts,
            $emptiedCells,
            null,
        );
    }

    /**
     * レポートを出力した時刻を推定する。候補はその日の 0:00 と、その日の履歴の各行の直後。
     * 保存済みの値と履歴の値が一致する SKU・倉庫が最も多い時刻を選ぶ（同じ数なら遅い方。履歴の始まりより前と判定しにくくするため）。
     *
     * @param  array<string, array{own: int|null, rfc: int|null}>  $stocks
     * @param  array<string, int>  $targets
     */
    private function estimateReportTime(BossInventoryHistory $history, string $baseDate, array $stocks, array $targets): CarbonImmutable
    {
        $candidates = [CarbonImmutable::parse("{$baseDate} 00:00:00", self::TIMEZONE)];
        foreach ($history->entries as $warehouses) {
            foreach ($warehouses as $entries) {
                foreach ($entries as $entry) {
                    if ($entry->processedAt->toDateString() === $baseDate) {
                        $candidates[] = $entry->processedAt;
                    }
                }
            }
        }
        $candidates = collect($candidates)->unique(fn (CarbonImmutable $time): int => $time->getTimestamp())->sortBy(fn (CarbonImmutable $time): int => $time->getTimestamp())->values();

        $best = $candidates->first();
        $bestCount = -1;
        foreach ($candidates as $candidate) {
            $count = 0;
            foreach ($history->entries as $skuCode => $warehouses) {
                if (! isset($targets[$skuCode])) {
                    continue;
                }
                foreach ($warehouses as $warehouse => $entries) {
                    $stored = $stocks[$skuCode][$warehouse] ?? null;
                    if ($stored !== null && $this->valueAt($entries, $candidate) === $stored) {
                        $count++;
                    }
                }
            }
            if ($count >= $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * 補完する日の 9:00 の値と、NULL にしたときの理由。
     *
     * @param  array<string, array<string, array{own: int|null, rfc: int|null}>>  $baseSurveys
     * @param  list<string>  $nearestBases  使える基準の調査の日（補完する日に近い順）
     * @param  array<string, array<string, string>>  $mismatched
     * @return array{0: int|null, 1: string|null}
     */
    private function valueOnDate(BossInventoryHistory $history, array $baseSurveys, array $nearestBases, array $mismatched, string $skuCode, string $warehouse, CarbonImmutable $basis): array
    {
        if (isset($mismatched[$skuCode][$warehouse])) {
            return [null, BossInventoryReconstruction::REASON_MISMATCH];
        }
        $entries = $history->entries[$skuCode][$warehouse] ?? [];
        if ($entries === []) {
            // 補完する日に近い基準の調査から、値のあるものを使う
            $value = null;
            foreach ($nearestBases as $baseDate) {
                $value ??= $baseSurveys[$baseDate][$skuCode][$warehouse] ?? null;
            }

            return $value === null ? [null, BossInventoryReconstruction::REASON_NO_DATA] : $this->unlessNegative($value);
        }
        foreach ($history->breaks as $break) {
            // 9:00 をまたいでつながりが切れていると、9:00 の値が決まらない
            if ($break['skuCode'] === $skuCode && $break['warehouse'] === $warehouse && $break['processedAt']->gt($basis) && $this->previousEntryTime($entries, $break['processedAt'])?->lte($basis)) {
                return [null, BossInventoryReconstruction::REASON_BREAK];
            }
        }

        return $this->unlessNegative($this->valueAt($entries, $basis));
    }

    /** @return array{0: int|null, 1: string|null} */
    private function unlessNegative(int $value): array
    {
        return $value < 0 ? [null, BossInventoryReconstruction::REASON_NEGATIVE] : [$value, null];
    }

    /**
     * 時刻 t の「実在庫 − 引当済」。t 以前の最後の行の変動後、なければ t より後の最初の行の変動前。
     *
     * @param  list<BossInventoryHistoryEntry>  $entries  処理の順
     */
    private function valueAt(array $entries, CarbonImmutable $time): int
    {
        $last = null;
        foreach ($entries as $entry) {
            if ($entry->processedAt->gt($time)) {
                break;
            }
            $last = $entry;
        }

        return $last?->availableAfter() ?? $entries[0]->availableBefore();
    }

    /** @param list<BossInventoryHistoryEntry> $entries */
    private function previousEntryTime(array $entries, CarbonImmutable $time): ?CarbonImmutable
    {
        $previous = null;
        foreach ($entries as $entry) {
            if ($entry->processedAt->gte($time)) {
                break;
            }
            $previous = $entry->processedAt;
        }

        return $previous;
    }

    /**
     * @param  list<string>  $baseDates
     * @return list<string> 補完する日に近い順（同じ近さなら前の日を先に）
     */
    private function baseDatesByDistance(array $baseDates, string $date): array
    {
        $target = CarbonImmutable::parse($date)->getTimestamp();
        usort($baseDates, fn (string $left, string $right): int => abs(CarbonImmutable::parse($left)->getTimestamp() - $target) <=> abs(CarbonImmutable::parse($right)->getTimestamp() - $target) ?: strcmp($left, $right));

        return $baseDates;
    }
}
