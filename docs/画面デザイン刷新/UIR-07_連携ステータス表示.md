# UIR-07 連携ステータス表示

**目的:** サイドバーの下部に、Amazon 在庫の取得状態と保存ファイルの期限をランプで示す。既存のデータだけで作る。

**依存:** UIR-05, UIR-06

## 作業

- `AppSidebar.vue` の下部に「連携ステータス」の枠を置き、`StatusLamp` を縦に並べる。各行の右端に、時刻または件数を弱い文字で添える。
- **Amazon SP-API**: 既存の `fetchAmazonInventoryImports()` の最新 1 件から出す。

  | 最新の状態 | ランプ | 右端の表示 |
  | --- | --- | --- |
  | `succeeded` | 正常 | 在庫基準の時刻（`basisAt`） |
  | `queued`／`running` | 注意 | 「取得中」 |
  | `failed` | 異常 | 失敗した時刻 |
  | 履歴なし | 注意 | 「未取得」 |

  - 画面を開いたときと、5 分ごとに取り直す。取得に失敗したときはランプを出さず、「状態を取得できません」と表示する。
- **保存ファイル**: surveys ストアの各調査の `files[].uploadedAt` から期限（アップロードから 30 日）を計算する。期限まで 7 日以内のファイルがあれば注意にし、「n 件 期限間近」と出す。なければ正常にし、「期限間近なし」と出す。
  - 30 日の定数は `FileManagementPage.vue` の `FILE_RETENTION_DAYS` を `constants/` に移して共有する。
- CrossWalker の行は出さない（[README の前提 1](README.md#前提推奨案で計画済み変える場合は該当チケットを修正する)）。
- ランプは常時の死活監視ではないので、必ず時刻か件数を添えて「いつの情報か」がわかるようにする。

## 対象ファイル

- `src/resources/js/components/AppSidebar.vue`
- `src/resources/js/components/IntegrationStatus.vue`（新規、任意で分割）
- `src/resources/js/constants/`（保存期限の定数）
- `src/resources/js/pages/FileManagementPage.vue`（定数の参照先の変更のみ）

## 完了条件

- Vitest で、Amazon の 4 つの状態と取得失敗、保存ファイルの期限ありとなしの表示を確認する。
- 5 分ごとの再取得が、画面を離れたときに止まる。
- `npm run check` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/components
npm run check
```
