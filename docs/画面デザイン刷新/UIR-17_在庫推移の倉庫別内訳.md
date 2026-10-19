# UIR-17 （任意）在庫推移の倉庫別内訳

**目的:** 在庫推移の「品番全体」グラフを、倉庫別（Amazon、BOSS、フリー在庫、ECストック）の積み上げ棒にする。実施するかは、UIR-11 の完了後に判断する。

**依存:** UIR-11

## 作業

- `InventoryTrendController` の応答に、在庫区分が「すべて」のときの日別・倉庫別の数量を追加する。既存の項目は変えず、項目を足す形にする。
  - Amazon の `NULL`（取込待ち・未取得）は 0 として足さない。その日のその倉庫の値は「未確定」として返す。
  - 日別 CSV（`InventoryTrendCsvExporter`）と外部 API（`ExternalInventoryController::daily`）の値と食い違わないよう、同じ `survey_skus` の集計を使う（CLAUDE.md の外部 API の規則）。
- `resources/js/api/inventoryTrends.ts` の型を更新する。
- `InventoryTrendChart.vue` で、倉庫色の固定順（Amazon → BOSS → フリー在庫 → ECストック）で積み上げる。凡例と、ツールチップの倉庫別の数値を付ける。未確定の倉庫がある日は、棒に「未確定」を示す。

## 対象ファイル

- `src/app/Http/Controllers/Api/InventoryTrendController.php`
- `src/resources/js/api/inventoryTrends.ts`
- `src/resources/js/pages/InventoryTrendPage.vue`
- `src/resources/js/components/inventory/InventoryTrendChart.vue`
- `src/tests/Feature/InventoryTrendApiTest.php` と関連する Vitest

## 完了条件

- PHPUnit で、倉庫別の数量、Amazon が `NULL` の日の扱い、既存項目が変わらないことを確認する。
- `ExternalInventoryApiTest.php` が変更なしで通る。
- `vendor/bin/pint --dirty --format agent`、`npm run check`、`npm run build` が通る。

## 確認コマンド

```sh
cd src
php artisan test --compact tests/Feature/InventoryTrendApiTest.php tests/Feature/ExternalInventoryApiTest.php
vendor/bin/pint --dirty --format agent
npm run check
npm run build
```

**実装状況（2026-10-19）:** 在庫推移の API（`GET /api/inventory-trends`）に `warehouseTotals`（日別の `amazon`・`boss`・`free`・`stock`）を足した。既存の項目は変えていない。SKU ごとの倉庫別数量は、SKU 別推移と同じ `warehouseQuantities`（ASIN のない SP-API 調査の SKU は Amazon を 0）から出すため、倉庫別の合計は総数の SKU 別合計と一致する。1 SKU でも未確定の倉庫は、部分合計を出さずに `null` とする。調査がない Amazon 取得日（在庫区分が Amazon のとき）は、Amazon だけを確定値にし、ほかの倉庫は `null` にする。**チケットとの違い:** (1) 在庫区分に「すべて」はなく、4 区分の合計は「総数」（`grandTotal`）だった。積み上げは総数に限らず、在庫区分に含まれる倉庫で行う（総数は 4 倉庫、モール全体は Amazon・BOSS、単独の区分はその倉庫の色の 1 段）。倉庫の並びと色は `constants/warehouses.ts` にまとめた。(2) 未確定の倉庫がある日は、確定した倉庫だけを積み、上に斜線と点線の枠の「未確定」の印を付ける。ツールチップは倉庫ごとの数値（未確定は「未確定」）を出し、総在庫数は「未確定」とする。確定した倉庫がない日も、印とツールチップは出す。欠品 SKU 数の線は従来どおり。テストは `InventoryTrendApiTest.php`（3 件追加）、`InventoryTrendPage.test.ts`、`InventoryTrendChart.test.ts`。`ExternalInventoryApiTest.php` は変更なしで通る。
