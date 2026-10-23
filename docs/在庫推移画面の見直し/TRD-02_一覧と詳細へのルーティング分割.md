# TRD-02 一覧と詳細へのルーティング分割

**目的:** 在庫推移を、品番一覧（`/inventory-trends`）と品番の詳細（`/inventory-trends/:productCode`）の2つの画面に分ける。このチケットでは画面の分け方と品番の受け渡しだけを変え、各画面の中身は TRD-03 以降で作る。

**依存:** なし

## 作業

- `router/index.ts`
  - `inventory-trends`（`/inventory-trends`）は新しい `InventoryTrendListPage.vue` を開く。
  - `inventory-trend`（`/inventory-trends/:productCode`）を追加し、今の `InventoryTrendPage.vue` を開く。`productCode` は props で渡す。現在地は「在庫推移 ＞ {品番}」にする（調査結果画面と同じく、画面側で末尾に品番を足す）。
  - サイドバーの「在庫推移」は、詳細画面を開いているときも選択中にする（`navigation.ts` の `children` に `inventory-trend` を足す）。
- `InventoryTrendListPage.vue`（新規）は、このチケットでは仮の中身にする: 見出し「在庫推移」と、`GET /api/inventory-trends/products` の品番を品番コード順に並べ、品番を押すと詳細を開くだけの一覧。TRD-03 で作り直す。
- `InventoryTrendPage.vue`
  - 品番は route の `productCode` から読む。「対象品番を選択」ダイアログと「変更」ボタン、それに関わる状態（`productPickerOpen`、`pendingProductCode`、`productBrandFilter`、`productQuery` など）を削除する（README の前提 2）。
  - 存在しない品番、または期間内に調査のない品番のときは、空の状態（`BaseEmpty`）に「品番一覧に戻る」ボタンを出す。
  - 表示期間と在庫区分は URL のクエリ（`?scope=&period=&from=&to=`）に持たせ、一覧と詳細を行き来しても保たれるようにする。
- 削除する `data-testid`: `product-picker-button`、`apply-product-selection`、`product-brand-filter`、`selected-product`。ダイアログをなくすため。そのほかの `data-testid` は残す。

## 対象ファイル

- `src/resources/js/router/index.ts`
- `src/resources/js/constants/navigation.ts`
- `src/resources/js/pages/InventoryTrendListPage.vue`（新規）、`InventoryTrendListPage.test.ts`（新規）
- `src/resources/js/pages/InventoryTrendPage.vue`、`InventoryTrendPage.test.ts`

## 完了条件

- Vitest で次を確認する。
  - `/inventory-trends` で一覧が開き、品番を押すと `/inventory-trends/{品番}` に移る。
  - 詳細画面が route の品番で `fetchInventoryTrend` を呼ぶ。品番のダイアログが出ない。
  - 存在しない品番で、空の状態と「品番一覧に戻る」が出る。
  - 在庫区分と表示期間が URL のクエリに反映され、クエリから復元される。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/InventoryTrendPage.test.ts resources/js/pages/InventoryTrendListPage.test.ts
npm run check
npm run build
```

**実装状況（2026-10-23）:** `/inventory-trends` を仮の一覧（`InventoryTrendListPage.vue`、品番コード順の一覧で、品番を押すと詳細を開く）にし、`/inventory-trends/:productCode`（ルート名 `inventory-trend`）で今の在庫推移画面を開くようにした。詳細画面は品番を props で受け取り、「対象品番を選択」ダイアログと「変更」ボタン、それに関わる状態を削除した。代わりに、見出しを品番（等幅）と「ブランド ・ カテゴリ ・ ◯ SKU」にし、左上に「品番一覧に戻る」（`back-to-list`）を置いた（見出しの作り込みは TRD-04）。在庫区分と表示期間は URL のクエリ（`scope`・`period`、期間指定のときだけ `from`・`to`）に持たせ、初期値（モール全体・1か月）は書かない。期間指定でないときに日付を書かないのは、あとで開いたときに最新の調査日までの期間になるようにするため。クエリの読み書きは一覧と詳細で共通の `utils/inventoryTrendQuery.ts` にまとめ、ほかのキー（TRD-03 の一覧の条件）は残す。存在しない品番（調査のない品番）は「この品番の在庫推移はありません」の空の状態にし、期間内にデータがないときの空の状態にも「品番一覧に戻る」を足した。上部バーの現在地は「在庫推移 / {品番}」、サイドバーは詳細でも「在庫推移」を選択中にする。`product-picker-button`、`apply-product-selection`、`product-brand-filter`、`selected-product` の `data-testid` は削除した。
