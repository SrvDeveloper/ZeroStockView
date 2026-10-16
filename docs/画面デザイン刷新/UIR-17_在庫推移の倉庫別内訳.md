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
