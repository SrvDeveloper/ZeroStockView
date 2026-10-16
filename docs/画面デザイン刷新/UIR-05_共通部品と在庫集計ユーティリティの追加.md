# UIR-05 共通部品と在庫集計ユーティリティの追加

**目的:** 複数の画面で使う新しい部品と、在庫状態の判定・集計をまとめる。判定ロジックを 1 か所にし、調査結果・調査履歴・連携ステータスで結果が食い違わないようにする。

**依存:** UIR-01

## 作業

### 在庫集計ユーティリティ（`resources/js/utils/stock.ts`）

`SurveyResultPage.vue` にある `amazonStockTotal`、`bossStockTotal`、`stockTotal` の判定を移し、次の関数を用意する。

- `amazonTotal(stock)`、`bossTotal(stock)`: どちらかの値が `null` なら `null`
- `skuTotal(stock, asin, amazonSource)`: 既存の `stockTotal` と同じ規則（ASIN なしの `sp_api` 調査は Amazon を 0 として足す。それ以外で `null` があれば `null`）
- `stockState(quantity)`: `"has" | "low" | "zero" | "pending"`（低在庫は 1〜5。UIR-03 の `data-stock-state` と同じ区分）
- `isAllZero(sku, survey)`: 総数が 0（`null` は含めない）
- `isPartialZero(sku, survey)`: 総数が 1 以上で、値が確定している倉庫のどれかが 0
- `summarizeSurvey(survey)`: `{ skuCount, productCount, allZeroCount, partialZeroCount }`

`SurveyResultPage.vue` は、移したあともこのユーティリティを使うように差し替える（見た目は変えない）。

### 部品（`resources/js/components/ui/`）

| 部品 | 内容 |
| --- | --- |
| `StatusLamp.vue` | 状態（`ok | warn | error`）の点＋文字。点は 7px の丸に、同じ色の淡いリングを付ける。色だけで状態を伝えないよう、文字を必須にする |
| `StatCard.vue` | 状態まとめのカード。見出し、数値（Barlow Condensed 36px）、単位、補足（前回比など）。`alert` 指定で下端に黄黒斜線の帯 |
| `StockCell.vue` | 在庫表のセルの中身。数値・内訳（`4 / 8` の形式）・状態（0 は縞の札、取込待ちは点線の札、対象外は文字だけ、未確定の総数は「—」）。`data-stock-state` を付ける |
| `SegmentedControl.vue` | 切り替えボタン群（AND／OR、2週間／1か月、カレンダー／リストなど）。墨 6% のトラックに、選択中は白い面と小さい影。`v-model` 対応、`role="radiogroup"` |
| `HazardStripe.vue`（任意） | 黄黒斜線の帯。`bg-hazard` ユーティリティで足りる場合は作らない |

## 対象ファイル

- `src/resources/js/utils/stock.ts`（新規）と `stock.test.ts`（新規）
- `src/resources/js/components/ui/StatusLamp.vue`、`StatCard.vue`、`StockCell.vue`、`SegmentedControl.vue`（新規）
- `src/resources/js/pages/SurveyResultPage.vue`（判定関数の差し替えのみ）

## 完了条件

- `stock.test.ts` で、次の場合の判定を確認する。
  - `null` を含む（取込待ち）
  - ASIN なし（対象外）
  - 旧ファイル調査（`legacy_file`）
  - 全倉庫ゼロ、一部ゼロ、低在庫
- `StockCell`、`SegmentedControl` のテストで、状態属性と `v-model` の動作を確認する。
- 既存の `SurveyImportSettingDisplay.test.ts` が変更なしで通る。
- `npm run check` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/utils/stock.test.ts resources/js/components/ui
npx vitest run resources/js/pages/SurveyImportSettingDisplay.test.ts
npm run check
```
