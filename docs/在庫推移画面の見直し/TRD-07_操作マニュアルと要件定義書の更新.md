# TRD-07 操作マニュアルと要件定義書の更新

**目的:** 在庫推移の画面構成の変更（一覧と詳細の2画面、ヒートマップなど）を、操作マニュアルと要件定義書に反映する。

**依存:** TRD-01〜06

## 作業

### 操作マニュアル

- `components/manual/sections/InventoryTrendSection.vue` を、一覧と詳細の2画面の説明に書き直す。
  - 品番一覧: 全体の状況の4つの数値、注目する品番のボタン、表の各列（SKUの状況の四角の見方を含む）、並び替え、絞り込み。
  - 品番の詳細: 前後の品番への移動、品番の数値、倉庫別の棒グラフ、ヒートマップの色の見方と並び順、「数値を表で見る」、カラー × サイズの在庫、欠品中の SKU。
  - 「品番全体／SKU別」の切り替えと「対象品番を選択」ダイアログの説明を削除する。
- 図（`components/manual/figures/`）で在庫推移の画面を描いているもの（`TrendCsvMenuFigure.vue` など）を、新しい構成に合わせる。
- `constants/manual.ts` の在庫推移の節の要点・キーワード・小見出し（ヒートマップ、欠品、前週比など）を更新する。
- `ManualPage.test.ts`、`utils/manualSearch.test.ts` の期待値を合わせる。

### 要件定義書（`doc/要件定義書.md`）

- 3章の画面一覧に「在庫推移（品番一覧）」と「在庫推移（品番の詳細）」を書き分ける。
- 5章の在庫推移の記述に、一覧の集計（前週比の比べ方、欠品の数え方、連続欠品日数の数え方）と、詳細のヒートマップ・カラー × サイズの在庫・欠品中の SKU を加える。README の前提 1〜8 のうち、仕様として残すものを書く。

## 対象ファイル

- `src/resources/js/components/manual/sections/InventoryTrendSection.vue`
- `src/resources/js/components/manual/figures/`（在庫推移の図）
- `src/resources/js/constants/manual.ts`
- `src/resources/js/pages/ManualPage.test.ts`、`src/resources/js/utils/manualSearch.test.ts`
- `doc/要件定義書.md`

## 完了条件

- マニュアルと要件定義書に、削除した機能（品番の選択ダイアログ、品番全体／SKU別の切り替え、SKU別のカード一覧）の説明が残っていない。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/ManualPage.test.ts resources/js/utils/manualSearch.test.ts
npm run check
npm run build
```
