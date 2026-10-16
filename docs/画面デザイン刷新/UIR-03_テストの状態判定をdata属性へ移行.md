# UIR-03 テストの状態判定を data 属性へ移行

**目的:** Vitest が色のクラス名（`bg-amber-50` など）で表示状態を確かめている箇所を、状態を表す `data-*` 属性で確かめる形に変える。以降の見た目の変更でテストが壊れないようにする。見た目は変えない。

**依存:** なし

## 作業

1. 現在の画面に状態属性を足す（見た目は変えない）。
   - `SurveyResultPage.vue` の在庫セル: `data-stock-state="has | low | zero | pending | na"`（`na` は ASIN 未登録で対象外）
   - 同じく行: `data-row-state="all-zero | matched"`（該当しない行は属性なし）
   - `DataImportPage.vue` のドロップゾーン: ドラッグ中に `data-dragging="true"`
   - `ImportSettingsPage.vue` の候補行: 追加済みのとき `data-added="true"`
2. 次のテストの `classes()` による判定を、上の属性による判定に置き換える。
   - `SurveyImportSettingDisplay.test.ts`: `bg-amber-50`、`bg-rose-50`、`bg-sky-50`、`font-semibold`、`text-slate-300`、`group-hover:*` などの判定
   - `DataImportPage.test.ts`: `border-primary-500`
   - `ImportSettingsPage.test.ts`: `bg-slate-100`、`text-slate-500`
3. 次の判定は動作を表しているので残す。
   - `ImportSettingsPage.test.ts` の `cursor-grab`／`cursor-grabbing`
   - `InventoryTrendChart.test.ts` の `fixed`
   - `SurveyImportSettingDisplay.test.ts` の `stock-breakdown-enabled`（Ctrl ホバー）。これは UIR-08 で仕組みごと削除する。

## 対象ファイル

- `src/resources/js/pages/SurveyResultPage.vue`
- `src/resources/js/pages/DataImportPage.vue`
- `src/resources/js/pages/ImportSettingsPage.vue`
- `src/resources/js/pages/SurveyImportSettingDisplay.test.ts`
- `src/resources/js/pages/DataImportPage.test.ts`
- `src/resources/js/pages/ImportSettingsPage.test.ts`

## 完了条件

- 置き換え対象のテストに、色・背景・文字色のクラス名による判定が残っていない。
- 状態属性の値が、置き換え前のクラス判定と同じ条件で付く（テストの期待値の意味を変えない）。
- `npm run check` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/SurveyImportSettingDisplay.test.ts resources/js/pages/DataImportPage.test.ts resources/js/pages/ImportSettingsPage.test.ts
grep -nE "classes\(\)\)\.(not\.)?toContain\(\"(bg-|text-|border-|font-|group-hover)" resources/js -r --include=*.test.ts || echo "残りなし"
npm run check
```
