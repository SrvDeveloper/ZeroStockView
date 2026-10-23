# TRD-04 詳細画面の見出しと品番の数値

**目的:** デザイン確認用ページの「02 品番の在庫推移（詳細）」の上部（戻るリンク、品番の見出し、前後の品番、表示の条件、品番の数値）を作る。

**依存:** TRD-02

## 作業

### 見出し

- 左上に「品番一覧に戻る」リンク。一覧から開いたときは、一覧の条件（クエリ）とページに戻る。
- 品番（IBM Plex Mono 26px・600）、その右に「ブランド · カテゴリ · ◯ SKU（◯カラー × ◯サイズ）」。下の行に品番グループのチップ（グループなしは点線のチップ）。
- 右に「前の品番 {品番}」「次の品番 {品番}」をつないだボタン群と、「この品番のCSV」ボタン（今の「選択品番の日次CSV」と同じ出力）。
  - 前後の順番は README の前提 8 のとおり。一覧のストアに並びがなければ、`GET /api/inventory-trends/products` の品番コード順を使う。
  - 先頭・末尾では、そちら側のボタンを無効にする。
  - 前後に移るときは、在庫区分と表示期間を保つ。
- 現在地の行の右端に、最新の調査日と Amazon 在庫の状態（TRD-03 と同じ表示）を出す。

### 表示の条件

- 在庫区分（`SegmentedControl`、`inventory-scope-select` の `data-testid` は付け替えて残す）と期間（2週間／1か月／3か月／期間指定、`period-select` を残す）を1行に並べ、右端に対象期間（例「2026/09/24 〜 2026/10/23（30日）」）を等幅で出す。期間指定の日付欄は今のものを使う。

### 品番の数値（4つ）

| カード | 数値 | 補足 |
| --- | --- | --- |
| 在庫総数（{最新の調査日}） | 最新の調査日の、選んだ在庫区分の合計 | 前週比と比べた日の数（例「前週比 ▼ 28.1%（199点から）」） |
| 期間の平均 | 確定した日の合計の平均（小数は四捨五入） | 「確定した ◯日の平均」 |
| 欠品SKU（{最新の調査日}） | 最新の調査日の欠品 SKU 数「3 / 12 SKU」 | 「1週間前は ◯ SKU」 |
| 期間中の欠品 | 欠品した SKU × 日の合計「43 SKU・日」 | 「欠品したSKU ◯件」 |

- いずれも今の `GET /api/inventory-trends` の応答（`warehouseTotals`、`series`）から画面側で計算する。前週比の比べる日は README の前提 6 と同じ規則にし、TRD-01 と同じ結果になる共通の関数（`utils/` に新規）にする。
- 未確定を含む日しかないときは「—」と「未確定の倉庫があります」を出す。

### API の小変更

- `GET /api/inventory-trends` の応答の `product` に `groups`（期間内で最新の調査に保存された品番グループの所属。`SurveyResource` と同じ形で、所属がなければ「グループなし」）と `skuCount`、`colorCount`、`sizeCount` を足す。

## 対象ファイル

- `src/resources/js/pages/InventoryTrendPage.vue`、`InventoryTrendPage.test.ts`
- `src/resources/js/utils/`（前週比の比べる日と、品番の数値の計算。新規）と、そのテスト
- `src/app/Http/Controllers/Api/InventoryTrendController.php`、`src/tests/Feature/InventoryTrendApiTest.php`
- `src/resources/js/api/inventoryTrends.ts`

## 完了条件

- Vitest で次を確認する。
  - 4つの数値が、`warehouseTotals` と `series` から正しく計算される（未確定の日を除く、0 は欠品に数える、`NULL` は数えない）。
  - 前週比の比べる日の選び方（7日前がある／ない／14日を超える）。
  - 前後の品番ボタンの移動先と、先頭・末尾での無効化。在庫区分と表示期間が保たれる。
  - 「品番一覧に戻る」が一覧の条件に戻る。
- PHPUnit で、`product.groups`（グループなしを含む）と SKU・カラー・サイズの数を確認する。
- `npm run check`、`npm run build`、変更範囲の PHPUnit、Pint が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/InventoryTrendPage.test.ts resources/js/utils
php artisan test --compact tests/Feature/InventoryTrendApiTest.php
vendor/bin/pint --dirty --format agent
npm run check
npm run build
```
