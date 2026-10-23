<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** 読み込んだ BOSS の在庫変動履歴と、その検証の結果 */
final readonly class BossInventoryHistory
{
    /**
     * @param  array<string, array<string, list<BossInventoryHistoryEntry>>>  $entries  SKUコード => 倉庫（own・rfc） => 処理の順に並べた行
     * @param  array<string, int>  $typeCounts  処理種別 => 行数
     * @param  list<array{skuCode: string, warehouse: string, processedAt: CarbonImmutable, expected: int, actual: int}>  $breaks  変動前の「実在庫 − 引当済」が、直前の行の変動後とつながらない箇所
     * @param  list<array{skuCode: string, warehouse: string, processedAt: CarbonImmutable, rowNumber: int}>  $negatives  変動前か変動後の「実在庫 − 引当済」がマイナスの行
     */
    public function __construct(
        public array $entries,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public int $rowCount,
        public array $typeCounts,
        public array $breaks,
        public array $negatives,
    ) {}

    /** SKU・倉庫の組み合わせの数 */
    public function skuWarehouseCount(): int
    {
        return array_sum(array_map('count', $this->entries));
    }
}
