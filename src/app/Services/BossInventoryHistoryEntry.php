<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** BOSS の在庫変動履歴の1行（自社倉庫・RFC倉庫のどちらか） */
final readonly class BossInventoryHistoryEntry
{
    public function __construct(
        public CarbonImmutable $processedAt,
        public string $type,
        public string $number,
        public string $skuCode,
        /** own: 自社倉庫、rfc: RFC倉庫 */
        public string $warehouse,
        public int $stockBefore,
        public int $stockAfter,
        public int $reservedBefore,
        public int $reservedAfter,
        public int $rowNumber,
    ) {}

    /** 変動前の「実在庫 − 引当済」（調査の BOSS 在庫と同じ定義） */
    public function availableBefore(): int
    {
        return $this->stockBefore - $this->reservedBefore;
    }

    /** 変動後の「実在庫 − 引当済」 */
    public function availableAfter(): int
    {
        return $this->stockAfter - $this->reservedAfter;
    }
}
