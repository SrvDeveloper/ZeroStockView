# UIR-06 共通レイアウト（サイドバー・上部バー）

**目的:** 上部の横並びナビを、左のサイドバーと上部バーの構成に置き換える。

**依存:** UIR-04

## 作業

- `constants/navigation.ts` の `NAV_ITEMS` にグループを足す。
  - 調査: データ取込、在庫調査履歴、在庫推移
  - 設定: 品番グループ、ファイル管理、API連携設定
  - ヘルプ: 操作マニュアル
  - アイコンは既存の `icon` を使う。英字の略称は持たせない。
- `components/Navbar.vue` を廃止し、次の 2 つに分ける。
  - `AppSidebar.vue`
    - 幅 212px、グラファイトの縦グラデーション、上端に高さ 4px の黄黒斜線
    - ロゴ（黄色の角丸 8px の四角に「0」）と、「ZeroStockView／在庫状況調査」
    - グループ見出しと項目（アイコン＋名前）。選択中の判定は既存の `isActive()` の規則をそのまま使う
    - 下部に連携ステータスを置く領域を用意する（中身は UIR-07）
  - `AppTopBar.vue`
    - 高さ 44px、半透明の白に背景ぼかし
    - 左に現在地（例: 「在庫調査履歴 / 2026-10-16 の調査結果」）
    - 右に現在時刻（`YYYY-MM-DD (曜) HH:mm JST`、1 分ごとに更新）とアカウントメニュー（パスワード変更、ログアウト。既存の Navbar から移す）
- 現在地の文言は、ルートの `meta` に `breadcrumb`（文字列、または `route` を受け取る関数）を足して出す。調査結果画面では、調査の在庫基準日を出す。
- `App.vue` を、サイドバーとメイン（上部バー＋本文）の 2 列のグリッドにする。
  - 全体の地は `bg-canvas` に `bg-blueprint`
  - 本文の余白は上 22px・左右 28px。`max-w-7xl` の中央寄せはやめ、本文の幅いっぱいに使う
- 狭い画面（`lg` 未満）では、サイドバーを閉じておき、上部バーのメニューボタンから引き出せるようにする。開いている間は背景に暗幕を出し、項目を選ぶと閉じる。
- 読み込み中のバーと重ね表示を、新しい色（墨と黄色以外のトークン）に合わせる。
- 「本文へ移動」のスキップリンクは残す。

## 対象ファイル

- `src/resources/js/constants/navigation.ts`
- `src/resources/js/components/Navbar.vue`（削除）
- `src/resources/js/components/AppSidebar.vue`、`AppTopBar.vue`（新規）
- `src/resources/js/App.vue`
- `src/resources/js/router/index.ts`（`meta.breadcrumb` の追加）

## 完了条件

- ログイン画面（`meta.public` のルート）では、サイドバーと上部バーを出さない。
- すべてのルートで、選択中の項目と現在地が正しく出る（Vitest で `isActive` の対応とパンくずを確認する）。
- パスワード変更とログアウトが、上部バーのメニューから実行できる。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/components
npm run check
npm run build
```

**実装状況（2026-10-16）:** `Navbar.vue` を削除し、`AppSidebar.vue` と `AppTopBar.vue` に分けた。`NAV_ITEMS` は `NAV_GROUPS` から作る形にし、子画面のルート名を `children` に持たせた（選択中の判定は `isNavItemActive`）。現在地は `meta.breadcrumb` に定義し、調査結果では在庫基準日（Asia/Tokyo）、品番グループの編集ではグループ名を末尾に足す。時刻は 30 秒ごとに更新する。アイコンの `settings`／`files` は、`category`／`folder_open` に変えた。テストは `components/AppLayout.test.ts`。
