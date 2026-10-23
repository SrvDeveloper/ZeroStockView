<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** BOSS の在庫変動履歴から再現した、補完する日の BOSS 在庫と照合の結果（docs/BOSS在庫の休日補完 BHR-02） */
final readonly class BossInventoryReconstruction
{
    public const REASON_MISMATCH = 'mismatch';

    public const REASON_BREAK = 'break';

    public const REASON_NEGATIVE = 'negative';

    public const REASON_NO_DATA = 'noData';

    /**
     * @param  array<string, array<string, array{own: int|null, rfc: int|null}>>  $values  補完する日 => SKUコード => 9:00 時点の BOSS 在庫（実在庫 − 引当済）
     * @param  array<string, array{filled: int, empty: int, reasons: array<string, int>}>  $dateSummaries  補完する日 => 値が入る SKU 数、NULL になる SKU 数と理由ごとの件数
     * @param  array<string, string>  $unavailableDates  補完できない日 => 理由
     * @param  array<string, CarbonImmutable|null>  $reportTimes  基準の調査の日 => レポートを出力した時刻（推定）。使えない基準の調査は null
     * @param  array<string, string>  $unusableBaseDates  使えない基準の調査の日 => 理由
     * @param  list<array{skuCode: string, warehouse: string, baseDate: string}>  $mismatches  基準の調査と履歴が合わない SKU・倉庫（最大 100件）
     * @param  array<string, int>  $mismatchCounts  使える基準の調査の日 => その日に合わなかった SKU・倉庫の数
     * @param  list<array{skuCode: string, warehouse: string, reason: string}>  $emptiedCells  つながりの切れ目・マイナスで、補完する日のいずれかで NULL にした SKU・倉庫
     */
    public function __construct(
        public array $values,
        public array $dateSummaries,
        public array $unavailableDates,
        public array $reportTimes,
        public array $unusableBaseDates,
        public array $mismatches,
        public int $mismatchCount,
        public array $mismatchCounts,
        public array $emptiedCells,
        /** 補完できないときの理由（使える基準の調査がない、など）。補完できるときは null */
        public ?string $failure,
    ) {}
}
