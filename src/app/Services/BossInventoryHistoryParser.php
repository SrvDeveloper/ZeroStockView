<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * BOSS の「在庫変動履歴」CSV を読み、SKU・倉庫ごとに処理の順に並べて、つながりとマイナスの値を検証する
 * （docs/BOSS在庫の休日補完 BHR-01）。
 */
class BossInventoryHistoryParser
{
    /** 使う倉庫。ほかの倉庫の行は読み飛ばす */
    public const WAREHOUSES = ['自社倉庫' => 'own', 'RFC倉庫' => 'rfc'];

    private const REQUIRED_HEADERS = ['処理日時', '処理種別', '処理番号', 'SKUコード', '倉庫', '変動前実在庫', '変動後実在庫', '変動前引当済', '変動後引当済'];

    /** 計算と一致するかの確認にだけ使う列 */
    private const AVAILABLE_AFTER_HEADER = '変動後実在庫 - 変動後引当済';

    private const TIMEZONE = 'Asia/Tokyo';

    public function __construct(private CsvFileReader $csv) {}

    /** ヘッダーから、在庫変動履歴のファイルかを判別する（在庫レポート・KEEP一覧表・在庫一覧照会表と取り違えないため） */
    public function isInventoryHistory(UploadedFile $file): bool
    {
        $headerRow = $this->csv->headerRow($file);
        $headers = $headerRow === false ? [] : array_map($this->csv->normalize(...), $headerRow);

        return count(array_intersect(self::REQUIRED_HEADERS, $headers)) === count(self::REQUIRED_HEADERS);
    }

    public function parse(UploadedFile $file): BossInventoryHistory
    {
        $name = $file->getClientOriginalName();
        /** @var array<string, array<string, list<BossInventoryHistoryEntry>>> $grouped */
        $grouped = [];
        $typeCounts = [];
        $rowCount = 0;
        $from = null;
        $to = null;

        foreach ($this->csv->rows($file, self::REQUIRED_HEADERS) as $rowNumber => $row) {
            $warehouse = self::WAREHOUSES[trim($row['倉庫'])] ?? null;
            if ($warehouse === null) {
                continue;
            }
            $skuCode = trim($row['SKUコード']);
            if ($skuCode === '') {
                throw ValidationException::withMessages(['files' => ["{$name}の{$rowNumber}行目にSKUコードがありません。"]]);
            }
            $entry = new BossInventoryHistoryEntry(
                processedAt: $this->processedAt($row['処理日時'], $name, $rowNumber),
                type: trim($row['処理種別']),
                number: trim($row['処理番号']),
                skuCode: $skuCode,
                warehouse: $warehouse,
                stockBefore: $this->quantity($row['変動前実在庫'], $name, $rowNumber),
                stockAfter: $this->quantity($row['変動後実在庫'], $name, $rowNumber),
                reservedBefore: $this->quantity($row['変動前引当済'], $name, $rowNumber),
                reservedAfter: $this->quantity($row['変動後引当済'], $name, $rowNumber),
                rowNumber: $rowNumber,
            );
            $availableAfter = trim($row[self::AVAILABLE_AFTER_HEADER] ?? '');
            if ($availableAfter !== '' && $this->quantity($availableAfter, $name, $rowNumber) !== $entry->availableAfter()) {
                throw ValidationException::withMessages(['files' => ["{$name}の{$rowNumber}行目の「".self::AVAILABLE_AFTER_HEADER.'」が、変動後実在庫と変動後引当済の差と合いません。']]);
            }

            $grouped[$skuCode][$warehouse][] = $entry;
            $typeCounts[$entry->type] = ($typeCounts[$entry->type] ?? 0) + 1;
            $rowCount++;
            $from = $from === null || $entry->processedAt->lt($from) ? $entry->processedAt : $from;
            $to = $to === null || $entry->processedAt->gt($to) ? $entry->processedAt : $to;
        }

        ksort($grouped, SORT_STRING);
        $breaks = [];
        $negatives = [];
        foreach ($grouped as $skuCode => $warehouses) {
            ksort($warehouses);
            foreach ($warehouses as $warehouse => $entries) {
                $ordered = $this->order($entries);
                $grouped[$skuCode][$warehouse] = $ordered;
                foreach ($ordered as $index => $entry) {
                    if ($entry->availableBefore() < 0 || $entry->availableAfter() < 0) {
                        $negatives[] = ['skuCode' => $skuCode, 'warehouse' => $warehouse, 'processedAt' => $entry->processedAt, 'rowNumber' => $entry->rowNumber];
                    }
                    $previous = $ordered[$index - 1] ?? null;
                    // 実在庫と引当済が同じ数だけずれる（出荷が履歴に出ない経路）ときは、実在庫 − 引当済はつながるので切れ目に数えない
                    if ($previous !== null && $previous->availableAfter() !== $entry->availableBefore()) {
                        $breaks[] = ['skuCode' => $skuCode, 'warehouse' => $warehouse, 'processedAt' => $entry->processedAt, 'expected' => $previous->availableAfter(), 'actual' => $entry->availableBefore()];
                    }
                }
            }
        }

        ksort($typeCounts, SORT_STRING);

        return new BossInventoryHistory($grouped, $from, $to, $rowCount, $typeCounts, $breaks, $negatives);
    }

    /**
     * 処理日時の順に並べる。同じ秒の行は、変動前の値が直前の行の変動後の値とつながる順にし、
     * つながりで決まらなければ処理番号の順にする。
     *
     * @param  list<BossInventoryHistoryEntry>  $entries
     * @return list<BossInventoryHistoryEntry>
     */
    private function order(array $entries): array
    {
        $bySecond = [];
        foreach ($entries as $entry) {
            $bySecond[$entry->processedAt->format('YmdHis')][] = $entry;
        }
        ksort($bySecond, SORT_STRING);

        $ordered = [];
        foreach ($bySecond as $remaining) {
            while ($remaining !== []) {
                $previous = $ordered === [] ? null : $ordered[count($ordered) - 1];
                $candidates = $previous === null ? [] : array_filter($remaining, fn (BossInventoryHistoryEntry $entry): bool => $this->follows($previous, $entry));
                if ($candidates === []) {
                    // 直前の行とつながる行がなければ、同じ秒のほかの行の続きになっていない行（つながりの先頭）から選ぶ
                    $candidates = array_filter($remaining, fn (BossInventoryHistoryEntry $entry): bool => ! array_filter(
                        $remaining,
                        fn (BossInventoryHistoryEntry $other): bool => $other !== $entry && $this->follows($other, $entry),
                    ));
                }
                if ($candidates === []) {
                    $candidates = $remaining;
                }
                usort($candidates, fn (BossInventoryHistoryEntry $left, BossInventoryHistoryEntry $right): int => $this->compareNumbers($left, $right));
                $next = $candidates[0];
                $ordered[] = $next;
                $remaining = array_values(array_filter($remaining, fn (BossInventoryHistoryEntry $entry): bool => $entry !== $next));
            }
        }

        return $ordered;
    }

    /** entry の変動前の実在庫と引当済が、previous の変動後とつながるか */
    private function follows(BossInventoryHistoryEntry $previous, BossInventoryHistoryEntry $entry): bool
    {
        return $previous->stockAfter === $entry->stockBefore && $previous->reservedAfter === $entry->reservedBefore;
    }

    private function compareNumbers(BossInventoryHistoryEntry $left, BossInventoryHistoryEntry $right): int
    {
        $byNumber = ctype_digit($left->number) && ctype_digit($right->number)
            ? strlen($left->number) <=> strlen($right->number) ?: strcmp($left->number, $right->number)
            : strcmp($left->number, $right->number);

        return $byNumber ?: $left->rowNumber <=> $right->rowNumber;
    }

    /** 処理日時は「2026/10/20 09:43:52」。月・日・時は1桁のこともある（「2026/10/20 9:50:43」） */
    private function processedAt(string $value, string $name, int $rowNumber): CarbonImmutable
    {
        $value = trim($value);
        $matched = preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2}) (\d{1,2}):(\d{2}):(\d{2})$#', $value, $parts) === 1;
        [, $year, $month, $day, $hour, $minute, $second] = $matched ? array_map('intval', $parts) : [0, 0, 0, 0, 0, 0, 0];
        if (! $matched || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw ValidationException::withMessages(['files' => ["{$name}の{$rowNumber}行目の処理日時を読めません（例: 2026/10/20 09:43:52）。"]]);
        }

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, self::TIMEZONE);
    }

    private function quantity(string $value, string $name, int $rowNumber): int
    {
        $value = trim($value);
        if (! preg_match('/^-?\d+(?:\.0+)?$/', $value)) {
            throw ValidationException::withMessages(['files' => ["{$name}の{$rowNumber}行目の数量が整数ではありません。"]]);
        }

        return (int) $value;
    }
}
