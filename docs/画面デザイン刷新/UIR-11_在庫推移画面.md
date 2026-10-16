# UIR-11 在庫推移画面

**目的:** 在庫推移画面（`InventoryTrendPage.vue`）と推移グラフの見た目を新デザインにする。データの取り方と集計は変えない（[README の前提 2](README.md#前提推奨案で計画済み変える場合は該当チケットを修正する)）。

**依存:** UIR-04, UIR-05

## 作業

- 条件のカード: 対象品番（選択ボタン＋「変更」）、在庫区分、表示期間（`SegmentedControl`）、期間（等幅の日付）を 1 行に並べる。品番選択モーダルは新しい `BaseModal` の見た目に合わせる。
- 「日次在庫をCSV出力」などの出力ボタンは、タイトル行の右に置く。
- グラフのカード: 見出しの右に「品番全体」「SKU別」の `SegmentedControl` を置く（既存の `trendView` を使う）。
- `InventoryTrendChart.vue` の見た目を変える。
  - 目盛り線は点線（墨 12%）、基準線は墨 35%。軸の文字は等幅 10px の弱い文字色。
  - 棒の上端だけを 3px の角丸にする。積み上げの区切りは 2px の隙間にする。
  - 欠品 SKU 数の系列は、信号の黄（`#f5c400`）の小さな四角と数値にする（現在は `#e11d48`）。
  - ツールチップは角丸 6px、大きい影。数値は Barlow Condensed。
- 系列の色（`SERIES_COLORS`）は、README の倉庫色から始まる落ち着いた色に入れ替える。SKU 別表示では、系列数が 4 を超える場合に備えて 8 色を用意する。黄色と赤は使わない。
- SKU のミニグラフ（`InventoryTrendMiniChart.vue`）は、墨の線と淡い面、終点の丸にする。最新の値が 0 なら、終点を黄色の丸にして墨の縁を付ける。
- SKU 別サマリーの表: 見出しは日本語、数値は Barlow Condensed。欠品日数が 1 以上なら黄色の札にする。

## 対象ファイル

- `src/resources/js/pages/InventoryTrendPage.vue`
- `src/resources/js/components/inventory/InventoryTrendChart.vue`
- `src/resources/js/components/inventory/InventoryTrendMiniChart.vue`
- 関連する `*.test.ts`

## 完了条件

- 欠品系列の色と、最新値 0 の終点の表示を Vitest で確認する。
- 既存の `InventoryTrendPage.test.ts` と `InventoryTrendChart.test.ts`（ツールチップの位置など）が通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/InventoryTrendPage.test.ts resources/js/components/inventory
npm run check
npm run build
```
