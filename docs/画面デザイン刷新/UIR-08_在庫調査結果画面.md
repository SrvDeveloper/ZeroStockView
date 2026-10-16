# UIR-08 在庫調査結果画面

**目的:** 在庫調査結果画面（`SurveyResultPage.vue`）を新デザインにする。要点を先に見せ、0・取込待ち・対象外を形で区別し、内訳を常に表示する。

**依存:** UIR-03, UIR-04, UIR-05

## 作業

### ヘッダー

- 「調査履歴に戻る」リンク、画面タイトル「在庫調査結果」、在庫基準日（等幅）、「最新」バッジを 1 行に並べる。
- 操作ボタンは既存の 4 つ（最新の在庫を再読込、保存ファイルで再照合、絞込結果をExcel出力、全件をExcel出力）を残す。主ボタンは「全件をExcel出力」だけにする。
- メタ情報（在庫基準日時、結果作成日時、結果更新日時、品番グループ）は弱い文字で 1 行に並べ、日時は等幅にする。
- Amazon の状態説明（取込待ち、一部未取得、取込済み、旧ファイル）の文言は残す。

### 状態のまとめ（新規）

`StatCard` を 4 枚並べる。値は UIR-05 の `summarizeSurvey()` で計算する。

| カード | 値 | 補足 |
| --- | --- | --- |
| 対象 | SKU 数（単位「SKU ／ n 品番」） | 前回比 |
| 全倉庫ゼロ（`alert`） | 総数が 0 の SKU 数 | 前回比（増加は ▲、減少は ▼） |
| 一部の倉庫でゼロ | `isPartialZero` の SKU 数 | 前回比 |
| Amazon 在庫 | `StatusLamp`（取込済み／取込待ち／一部未取得／旧ファイル） | 基準時刻と照合件数 |

- 前回比は、surveys ストアのうち在庫基準日時がこの調査の直前にある調査と比べる。直前の調査がなければ前回比は出さない。
- 総数が未確定（取込待ち）の SKU は、全倉庫ゼロにも一部ゼロにも数えない。

### 絞込

- 1 段目: 品番グループ、ブランド、カテゴリのセレクトと、品番・SKU・ASIN の入力欄（等幅）。
- 2 段目: 「在庫ゼロ」「在庫あり」のチップ（角丸 4px。在庫ゼロの選択中は黄色、在庫ありの選択中は墨）、「結合」の `SegmentedControl`（AND／OR）、該当件数、「条件をクリア」。
- 既存の `data-stock-condition`、`data-condition-operator`、`data-clear-filters` は残す。

### 品番カードと在庫表

- 品番の見出し: 品番は等幅 15px・600 で囲みなし。続けて親 ASIN（等幅・弱い文字）、ブランドとカテゴリのバッジ、右端に品番メモ。
- 表の列は、SKU／ASIN、Amazon（小見出し「自社／FBA」）、BOSS（「自社／RFC」）、フリー在庫、ECストック、総数、倉庫別の構成、メモ。倉庫名の前に倉庫色の小さな四角を置く。
- 各セルは `StockCell` を使い、内訳（`4 / 8`）を数字の下に常に表示する。値が 0 や未確定のときは内訳を出さない。
- 状態の表し方。

  | 状態 | 表示 |
  | --- | --- |
  | 0 | 淡い縞の札 |
  | 取込待ち | 点線の札 |
  | 対象外 | 「対象外」の文字だけ |
  | 未確定の総数 | 「—」 |
  | 全倉庫ゼロの行 | 行全体を黄色の地にし、左端に黄黒斜線の帯と「全倉庫ゼロ」のタグ |
  | 検索に一致した行 | 淡い青灰の地と、左端の墨の帯 |
  | 少数在庫（1〜5） | [README 決定事項 A](README.md#決定事項2026-10-16-確定) の結論に従う |

- 「倉庫別の構成」列は、倉庫色の積み上げバー（幅 112px、高さ 7px、区切り 2px）で示す。総数が未確定なら「Amazon 取込後に表示」、0 なら空のトラックにする。
- 既存の `data-stock-field`、`data-sku-cell`、`v-memo`、`content-visibility` による描画の最適化は残す。

### Ctrl＋ホバーの削除

- `isControlPressed`、キーイベントの登録と解除、`stock-breakdown-enabled` クラス、`.stock-breakdown-tooltip` の CSS と DOM を削除する（[README の前提 3](README.md#前提推奨案で計画済み変える場合は該当チケットを修正する)）。
- 画面の説明文「Ctrlキーを押しながら…」を削除する。
- `SurveyImportSettingDisplay.test.ts` の Ctrl 関連のテストを、内訳が常に表示されることを確かめるテストに置き換える。

## 対象ファイル

- `src/resources/js/pages/SurveyResultPage.vue`
- `src/resources/js/pages/SurveyImportSettingDisplay.test.ts`
- 必要に応じて、在庫表を `components/survey/SurveyProductTable.vue` などに切り出す。

## 完了条件

- Vitest で、次の表示を確認する。
  - 状態のまとめの件数と前回比（直前の調査あり・なし）
  - 全倉庫ゼロの行の属性
  - 取込待ち・対象外・未確定の総数
  - 内訳が常に表示されること
- 再照合モーダル、Excel 出力、メモ保存、30 秒ごとの自動更新の既存テストが通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/SurveyImportSettingDisplay.test.ts
npm run check
npm run build
```

**実装状況（2026-10-16）:** 状態のまとめ（`StatCard` 4 枚、前回比つき）、絞込（チップ、`SegmentedControl` による AND／OR）、品番カード（品番は囲みなしの等幅、品番メモは点線枠）、在庫表（`StockCell` で内訳を常時表示、全倉庫ゼロ行の黄色の地と斜線の帯、検索一致行、倉庫別の構成バー）を実装した。Ctrl＋ホバーの仕組み（キーイベント、`stock-breakdown-enabled`、ツールチップの CSS）は削除した。少数在庫は決定事項 A のとおり、Amazon と BOSS の列で数字を注意色の太字にする（フリー在庫・ECストック・総数は従来どおり強調しない）。日時の表示形式は、他画面と Excel 出力に合わせて `YYYY/MM/DD HH:mm` のまま、値だけを等幅にした。テストは `[data-stock-value]`、`[data-breakdown]`、`aria-checked` で確かめる形に更新し、状態のまとめと前回比のテストを追加した。
