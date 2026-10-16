# UIR-01 デザイントークンと共通スタイルの定義

**目的:** 新デザインの色・角丸・影・背景を Tailwind v4 のテーマとして定義し、以降のチケットがクラス名で参照できるようにする。

**依存:** なし

## 作業

- `resources/css/app.css` の `@theme` に、[README のデザイン仕様](README.md#デザイン仕様)の色トークンを追加する（墨、地、面、罫線、信号の黄、エラー、ランプ 3 色、サイドバー、倉庫 4 色）。
- 既存の `--color-primary-50`〜`950`（青）を、墨・グラファイト系の階調に置き換える。既存画面の `primary-*` 参照が新しい色調で表示されるようにするためで、各画面チケットで個別に調整する前の暫定の見た目になる。
  - 目安: `primary-600` = `#11161a`（主ボタン・強調）、`primary-50` = `#eef1f4`（選択中の淡い面）、`primary-500` = フォーカスリング用の `#3a434c`。
- 角丸と影をトークン化する（`--radius-card: 6px`、`--radius-control: 5px`、`--radius-chip: 4px`、`--shadow-card` など。値は README の表のとおり）。
- 共通クラスを `@utility` で定義する。
  - `bg-hazard`: 黄黒の斜線
  - `bg-hazard-pale`: 1 倉庫だけの 0 に使う淡い縞
  - `bg-blueprint`: 方眼の背景（28px 間隔。ログインでは `bg-size-[32px_32px]` で上書き）
- `.skip-link` の色を新しいトークンに合わせる。
- `body` の地色を `--color-canvas` にする（`App.vue` の `bg-slate-50` は UIR-06 で置き換える）。

## 対象ファイル

- `src/resources/css/app.css`

## 完了条件

- 追加したトークンとユーティリティがビルド後の CSS に含まれる。
- 既存画面が、青の代わりに墨系の主ボタンでビルドできる（見た目の細部は各画面チケットで直す）。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npm run check
npm run build
```
