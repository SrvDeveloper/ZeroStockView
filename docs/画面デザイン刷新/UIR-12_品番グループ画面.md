# UIR-12 品番グループ一覧・編集画面

**目的:** 品番グループの一覧（`ImportSettingsListPage.vue`）と編集（`ImportSettingsPage.vue`）を新デザインにする。

**依存:** UIR-03, UIR-04

## 作業

### 一覧

- タイトルの右に主ボタン「新しいグループを作成」。
- 説明文の下に要約を出す。グループ数、取込対象の品番数（重複を除く）、取込対象の SKU 数。
- 表の列は、グループ名、対象品番数（Barlow Condensed の数値と「/ 50」）、対象品番（等幅の札を先頭 5 件と「ほか n 件」）、最終同期、操作（編集、削除のアイコンボタン）。
  - 最終同期は `lastSyncedAt` を使う（[README 決定事項 B](README.md#決定事項2026-10-16-確定)）。値がなければ「—」。
- 空の状態（グループなし）は `BaseEmpty` のまま残す。

### 編集

- 「一覧に戻る」、タイトル「品番グループを編集／新規作成」。
- タイトル行の右に、未保存の変更があるときだけ注意のランプと「未保存の変更があります」を出す。続けて「変更を取り消す」と主ボタン「このグループを保存」。
- 基本情報のカード（グループ名）。
- 2 列の構成。
  - 左「品番を追加」: 検索欄（等幅）と「検索」ボタン（副ボタン）、CrossWalker の説明、候補の一覧（品番は等幅 13px・600、ブランド／カテゴリ／SKU 数、「追加」または無効の「追加済み」）。
  - 右「取込対象品番」: 見出しの右に上限メーター（幅 110px、高さ 5px、角丸 2px）と「n / 50」。一覧はドラッグ用の取っ手、2 桁の順番、品番、SKU 数・ブランド・カテゴリ、削除のアイコンボタン。
- ドラッグでの並べ替え（`cursor-grab`／`cursor-grabbing`）の動作は変えない。

## 対象ファイル

- `src/resources/js/pages/ImportSettingsListPage.vue`
- `src/resources/js/pages/ImportSettingsPage.vue`
- `src/resources/js/pages/ImportSettingsListPage.test.ts`
- `src/resources/js/pages/ImportSettingsPage.test.ts`

## 完了条件

- 一覧の要約（重複を除いた品番数）と、未保存ランプの表示条件を Vitest で確認する。
- 既存の並べ替え、追加、削除、保存のテストが通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/ImportSettingsListPage.test.ts resources/js/pages/ImportSettingsPage.test.ts
npm run check
npm run build
```
