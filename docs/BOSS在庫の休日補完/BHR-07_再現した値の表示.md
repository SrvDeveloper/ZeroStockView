# BHR-07 再現した値の表示

**目的:** 在庫変動履歴から再現した BOSS 在庫を、実際のレポートの値と見分けられるようにする（README の前提 5）。

**依存:** BHR-03（応答の `bossSource`・`bossBasisAt` は BHR-05 で足す。先に着手する場合は BHR-05 のその部分を先に行う）

## 作業

- **調査結果画面**: `bossSource = history` の調査では、見出しの下の基準日時の並び（Amazon の基準日時と同じ所）に「BOSS 履歴から再現（10/17 9:00時点）」を出す。BOSS の列の見出しに小さな印（`history` を表すアイコンと `title`「在庫変動履歴から再現した値です」）を付ける。数値の表示（`StockCell`）そのものは変えない。
- **調査履歴画面（カレンダー）**: `history` の日の札に「BOSS 再現」の小さな印を付ける。凡例にも足す。
- **在庫推移**: 倉庫別の棒グラフのツールチップで、`history` の日は BOSS の行に「（履歴から再現）」を添える。そのために `GET /api/inventory-trends` の `dailySources` に `bossSource` を足す（在庫推移画面の見直し `TRD-xx` と同時に進む場合は、そちらの画面に合わせる）。
- **Excel 出力**: 調査の情報の行に「BOSS在庫: 在庫変動履歴から再現（2026-10-17 09:00時点）」を足す（`SurveyExcelExporter`）。
- 日次在庫CSV・外部公開API は変えない（README の前提 5）。

## 対象ファイル

- `src/resources/js/pages/SurveyResultPage.vue`、関連する `*.test.ts`
- `src/resources/js/pages/SurveyHistoryPage.vue`、`SurveyHistoryPage.test.ts`
- `src/resources/js/components/inventory/InventoryTrendChart.vue`、`InventoryTrendChart.test.ts`
- `src/app/Http/Controllers/Api/InventoryTrendController.php`、`src/tests/Feature/InventoryTrendApiTest.php`
- `src/app/Services/SurveyExcelExporter.php`、関連する PHPUnit
- `src/resources/js/api/inventoryTrends.ts`

## 完了条件

- Vitest で、調査結果・調査履歴・在庫推移のツールチップに、`history` のときだけ印と文言が出ることを確認する。
- PHPUnit で、`dailySources[].bossSource` と、Excel の情報の行を確認する。
- `npm run check`、`npm run build`、変更範囲の PHPUnit、Pint が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages resources/js/components/inventory
php artisan test --compact tests/Feature/InventoryTrendApiTest.php --filter=boss
vendor/bin/pint --dirty --format agent
npm run check
npm run build
```
