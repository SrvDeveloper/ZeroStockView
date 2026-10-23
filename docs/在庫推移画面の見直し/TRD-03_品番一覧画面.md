# TRD-03 品番一覧画面

**目的:** デザイン確認用ページの「01 在庫推移（品番一覧）」のとおりに、全品番の在庫の動きと欠品の状況を見渡せる一覧画面を作る。

**依存:** TRD-01, TRD-02

## 作業

### 見出しと最新の調査

- 見出し「在庫推移」、説明「全品番の在庫の動きと欠品の状況を一覧で確認し、気になる品番を開いて詳しく見ます。」、右に「日次在庫CSV」ボタン（今の CSV メニューを移す。`inventory-trend-export-button`、`inventory-trend-export`、`inventory-trend-export-all` の `data-testid` は残す）。
- 見出しの上の現在地の行の右端に、最新の調査日と Amazon 在庫の状態を出す（例:「最新の調査 10/23（金）・Amazon 取込済み 09:42」）。応答の `latestSurvey` を使い、状態はランプ（`StatusLamp`）と文言で示す。

### 絞り込みのカード

- 1行に、在庫区分（`SegmentedControl`）、期間（2週間／1か月／3か月／期間指定）、品番グループ（`BaseSelect`、初期値「すべてのグループ」）、ブランド（`BaseSelect`）、品番・SKU・ASIN の検索（`BaseInput`、入力が止まってから 300ms 後に検索）を並べる。狭い画面では折り返す。
- 条件は URL のクエリに持たせる（TRD-02 と同じ `scope`・`period`・`from`・`to` に、`group`・`brand`・`search`・`focus`・`sort`・`page` を足す）。

### 全体の状況（4つの数値）

- 対象品番（品番数と SKU 数）、欠品SKU（最新の調査日。前週との差）、30日以上続いている欠品（SKU 数と品番数）、在庫の合計（選んだ在庫区分の合計。前週比）。応答の `summary` を使う。
- 合計に未確定を含むときは数値を「—」にし、補足に「未確定の倉庫があります」と出す。

### 品番一覧

- 見出し「品番一覧」の右に、注目する品番を絞るボタン「すべて」「欠品SKUあり」「欠品が増えた」「在庫が10%以上減った」「30日以上の欠品」を、件数（`focusCounts`）付きで並べる。押したボタンは墨の面にする（`aria-pressed`）。
- 一覧の右上に SKUの状況の凡例を出す。
- 表の列

| 列 | 内容 |
| --- | --- |
| 品番 | 品番（IBM Plex Mono・下線のリンク）と、その下に「ブランド · カテゴリ · ◯ SKU」 |
| 品番グループ | グループのチップ。グループなしは点線の枠のチップ「グループなし」 |
| 最新在庫 | `latest.quantity`。未確定は「—」 |
| 前週比 | 「▲ 4.2%」「▼ 28.1%」「±0.0%」。10%以上減ったときは淡い注意色の札にして太字にする。比べられないときは「—」 |
| 期間の推移 | 墨の折れ線（1.75px）と淡い面、終点の丸。`InventoryTrendMiniChart.vue` の `spark` を使えるなら使う。未確定・調査なしの日は線を切る |
| SKUの状況（最新） | README の「SKUの状況」の四角の並び |
| 欠品SKU | 「3 / 12」。前週より増えたときは下に「前週から +1」 |
| 期間の欠品（SKU・日） | `stockoutSkuDays` |
| （開く） | 右向きの矢印のリンク（`aria-label`「{品番}の在庫推移を開く」） |

- 行全体ではなく、品番と矢印をリンクにする（表の中の文字を選択・コピーできるように）。
- 列見出しの「欠品SKU」「前週比」「最新在庫」「品番」を押すと並び替える（`sort`）。並び替え中の列は `aria-sort` を付け、見出しを墨にして矢印を出す。
- 下に「1〜25件目 / 326品番」とページ送り。
- 詳細を開くときは、今の一覧の条件（クエリ）を詳細に引き継ぎ、前後の品番の順番（TRD-04）に使えるよう、今のページの品番の並びをストアに残す。
- 読み込み中・読み込み失敗（再試行ボタン）・該当なしの状態を出す。

## 対象ファイル

- `src/resources/js/pages/InventoryTrendListPage.vue`、`InventoryTrendListPage.test.ts`
- `src/resources/js/components/inventory/`（SKUの状況の部品 `SkuStateStrip.vue` などを新規）
- `src/resources/js/stores/`（前後の品番の並びを残すストア。新規または既存の拡張）

## 完了条件

- Vitest で次を確認する。
  - 4つの数値と注目のボタンの件数が、応答の `summary` のとおりに出る。未確定の合計は「—」。
  - 注目のボタン・並び替え・絞り込み・ページ送りで、`fetchInventoryTrendOverview` が正しいパラメータで呼ばれ、URL のクエリに反映される。
  - 前週比の表示（増加・減少・10%以上の減少・比べられない）。
  - SKUの状況の四角の数と状態、`aria-label`。
  - 品番のリンクが、条件を引き継いで詳細を開く。
  - 読み込み失敗と該当なしの表示。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/InventoryTrendListPage.test.ts resources/js/components/inventory
npm run check
npm run build
```
