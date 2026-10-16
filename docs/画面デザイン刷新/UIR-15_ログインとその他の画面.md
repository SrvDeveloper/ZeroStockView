# UIR-15 ログイン・パスワード変更・404・操作マニュアル

**目的:** 残りの画面を新デザインに揃える。

**依存:** UIR-04

## 作業

- **ログイン（`LoginPage.vue`）**
  - 画面全体を、方眼（32px 間隔）を敷いたスチールグレーにする。
  - 中央に幅 360px のパネルを置く（角丸 8px、大きい影）。
  - パネルの上端に高さ 6px の黄黒斜線を入れる。
  - ヘッダーに、黄色のロゴ、「ZeroStockView」、「在庫状況調査」を置く。
  - ログイン ID（等幅）、パスワード、幅いっぱいの主ボタン「ログイン」を並べる。
  - 下端に補助面の帯を置き、既存のフッター表記をそこに移す。
- **パスワード変更（`PasswordChangePage.vue`）**: 新しいカードと入力欄に置き換える。
- **404（`NotFoundPage.vue`）**: `BaseEmpty` の見た目に揃え、在庫調査履歴へ戻るボタンを置く。
- **操作マニュアル（`ManualPage.vue`、`components/manual/*`）**
  - 見出し、目次、注意枠（`ManualCallout`）、手順（`ManualStep`）を新しいトークンに合わせる。
  - 画像枠（`ManualScreenshot`）は角丸 6px と標準の影にする。
  - マニュアル本文の画面説明に、旧デザイン固有の記述（「上部のメニュー」「Ctrl キーで内訳」など）があれば、新しい画面に合わせて書き換える。
  - 画面キャプチャの撮り直しは範囲外（README の作業範囲を参照）。

## 対象ファイル

- `src/resources/js/pages/LoginPage.vue`
- `src/resources/js/pages/PasswordChangePage.vue`
- `src/resources/js/pages/NotFoundPage.vue`
- `src/resources/js/pages/ManualPage.vue`
- `src/resources/js/components/manual/*.vue`
- `src/resources/js/pages/ManualPage.test.ts`

## 完了条件

- マニュアル本文に、Ctrl ホバーや上部ナビなど、旧画面の操作の記述が残っていない。
- 既存の `ManualPage.test.ts` が通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
grep -nE "Ctrl|上部のメニュー|ナビゲーションバー" resources/js/pages/ManualPage.vue resources/js/components/manual/*.vue || echo "旧記述なし"
npx vitest run resources/js/pages/ManualPage.test.ts
npm run check
npm run build
```
