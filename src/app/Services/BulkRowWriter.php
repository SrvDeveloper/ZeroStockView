<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 多くの行を、1 行ずつではなくまとめて書き込む（取込で数百行を更新するため）。
 * 更新は「列 = CASE id WHEN ? THEN ? … END」の形で、SQLite と MySQL の両方で動く。
 */
class BulkRowWriter
{
    /** CASE の分岐は行ごとに先頭から比べられるので、1 文の行数を抑える */
    private const UPDATE_CHUNK = 100;

    private const CHUNK = 500;

    /**
     * 行ごとに違う値で更新する。$rows のすべての行は同じ列を持つ。updated_at も書く。
     *
     * @param  array<int, array<string, mixed>>  $rows  id => [列 => 値]
     */
    public function updateById(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $now = now();
        foreach (array_chunk($rows, self::UPDATE_CHUNK, true) as $chunk) {
            $columns = array_keys(reset($chunk));
            $sets = [];
            $bindings = [];
            foreach ($columns as $column) {
                $cases = [];
                foreach ($chunk as $id => $row) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = $row[$column];
                }
                $sets[] = $this->wrap($column).' = CASE '.$this->wrap('id').' '.implode(' ', $cases).' END';
            }
            $sets[] = $this->wrap('updated_at').' = ?';
            $bindings[] = $now;
            $ids = array_keys($chunk);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            DB::update('UPDATE '.$this->wrap($table).' SET '.implode(', ', $sets).' WHERE '.$this->wrap('id')." IN ({$placeholders})", [...$bindings, ...$ids]);
        }
    }

    /**
     * 行をまとめて追加する。created_at・updated_at も書く。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert(array_map(fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    /**
     * 一意制約のある並び順を書き換える前に、対象の行をいったん重ならない値へ退避する。
     * MySQL は一意制約を 1 行ごとに確かめるので、並び順が入れ替わる行をそのまま更新すると途中で重なるため。
     *
     * @param  list<int>  $ids
     */
    public function moveSortOrderAside(string $table, array $ids, int $offset): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table($table)->whereIn('id', $chunk)->increment('sort_order', $offset);
        }
    }

    private function wrap(string $name): string
    {
        return DB::connection()->getQueryGrammar()->wrap($name);
    }
}
