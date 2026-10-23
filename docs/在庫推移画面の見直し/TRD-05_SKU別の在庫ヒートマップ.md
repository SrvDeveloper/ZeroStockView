# TRD-05 SKU別の在庫ヒートマップ

**目的:** 見づらかった SKU別の小さなグラフのカード一覧を、SKU × 日のヒートマップに置き換える。どの SKU がいつから欠品しているか、在庫がどう減っているかを一目で分かるようにする。

**依存:** TRD-02

## 作業

### 画面構成

- 「品番全体／SKU別」の切り替えをやめる（README の前提 3）。倉庫別の棒グラフのカード（`InventoryTrendChart.vue`、今のまま）の下に、「SKU別の在庫（ヒートマップ）」のカードを置く。
  - 棒グラフのカードの見出しは「倉庫別の在庫（日別）」、説明は「棒の高さが品番全体の在庫数。下の黄色の札は、その日に欠品していたSKUの数です。」にする。合算区分の注意書き（`survey-scope-note`）は残す。
- SKU別の小さなグラフのカード一覧（`sku-chart-grid`）を削除する。`trend-view-product`・`trend-view-sku` の `data-testid` も切り替えとともに削除する。

### ヒートマップ（`InventoryTrendHeatmap.vue` を新規）

- 見出し「SKU別の在庫（ヒートマップ）」、説明「1マスが1日です。色が濃いほど在庫が多く、黄色は欠品（0）。マスにカーソルを合わせると数量を表示します。」。
- 見出しの右に並び順の `BaseSelect`:「カラー・サイズ順」（初期値。SKU の `sort_order`）、「欠品日数の多い順」、「最新在庫の少ない順」。カラー・サイズ順のときだけ、カラーの見出し行（「カラー 01」）を挟む。
- 各行の左は SKU コード（等幅）とサイズ、右に「最新」（在庫数）と「欠品日数」（0 なら「—」）。
- マスの色と大きさは README のデザイン仕様のとおり。CSS グリッドで描く（列 = `168px repeat(日数, minmax(0, 1fr)) 64px 64px`）。期間が長くマスが 6px 未満になるときは、表の部分を横スクロールにし、マスの最小幅を 6px に保つ。
- マスにマウスを置く、またはキーボードで移ると、ツールチップに「10/19（月） 1000-01-M 在庫 12」を出す（倉庫別の内訳がある在庫区分では内訳も）。ツールチップの作りは `InventoryTrendChart.vue` にそろえる。
- 下に凡例（0（欠品）、在庫数の5段階、未確定、調査なし）。凡例の段階の数字は Barlow Condensed。
- 凡例の右に「数値を表で見る」ボタン。押すと、今の「SKU別サマリー」の表（`sku-summary-table`）をカードの中に開く（初期は閉じる。開閉は `aria-expanded`）。色だけに頼らずに数値を確かめる手段として残す。
- 図全体に `role="img"` と、品番・期間・SKU 数・欠品のあった SKU 数をまとめた `aria-label` を付ける。

### API の小変更

- `GET /api/inventory-trends` の `series` の各 SKU に `color`（`survey_skus.tq_color_no`）を足す。並び順は今のまま `sort_order`。

## 対象ファイル

- `src/resources/js/components/inventory/InventoryTrendHeatmap.vue`（新規）、`InventoryTrendHeatmap.test.ts`（新規）
- `src/resources/js/pages/InventoryTrendPage.vue`、`InventoryTrendPage.test.ts`
- `src/resources/js/components/inventory/InventoryTrendMiniChart.vue`（カード用の表示を消し、表の `spark` だけ残す）
- `src/app/Http/Controllers/Api/InventoryTrendController.php`、`src/tests/Feature/InventoryTrendApiTest.php`
- `src/resources/js/api/inventoryTrends.ts`

## 完了条件

- Vitest で次を確認する。
  - 在庫数ごとのマスの色の段階（0、1〜4、5〜9、10〜19、20〜39、40 以上、`NULL`、調査なし）。
  - 3つの並び順と、カラー・サイズ順のときだけカラーの見出し行が出ること。
  - ツールチップの内容（マウスとキーボード）。
  - 「数値を表で見る」で SKU別サマリーの表が開閉すること。
  - 「品番全体／SKU別」の切り替えと SKU別のカード一覧が出ないこと。
- PHPUnit で `series[].color` を確認する。
- `npm run check`、`npm run build`、変更範囲の PHPUnit、Pint が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/components/inventory resources/js/pages/InventoryTrendPage.test.ts
php artisan test --compact tests/Feature/InventoryTrendApiTest.php
vendor/bin/pint --dirty --format agent
npm run check
npm run build
```

**実装状況（2026-10-23）:** 「品番全体／SKU別」の切り替えと SKU別の小さなグラフのカードを削除し、倉庫別の棒グラフのカード（見出し「倉庫別の在庫（日別）」）の下に、`InventoryTrendHeatmap.vue` のカードを置いた。`GET /api/inventory-trends` の `series` に `color`（TQ のカラー番号）を足した。`InventoryTrendMiniChart.vue` は表の折れ線だけにし、`label`・`variant` の指定をなくした。チケットから変えた点・足した点は次のとおり。

- ツールチップの倉庫別の内訳を出すため、モール全体・総数の在庫区分のときだけ、`series[].points[]` に `warehouses`（調査のある日の倉庫別の数量。`null` は取込待ち・未取得）を足した（チケットの API の小変更は `color` だけだった）。
- 図の全体は `role="img"` ではなく `role="group"` にし、品番・期間・SKU 数・欠品のあった SKU 数の `aria-label` を付けた。マスにキーボードで移れるようにしたため（`role="img"` の中の要素は読み上げで扱われない）。マスごとに「10/19（月） 1000-01-M 在庫 12」の `aria-label` を付けた。
- キーボードでは、Tab で止まるのはヒートマップの中の1マスだけにし、矢印キー（上下左右）と Home・End でマスを移る。
- 「数値を表で見る」で開く表（SKU別サマリー）の上に、対象期間を出した。表の開閉は画面側で持ち、在庫区分を変えたり再読込したりしても開いたままにした。
- 日付の見出しは、31日以内は1日おき（月の始めと先頭は「10/1」の形）、それを超えるときは8か所に間引いて「月/日」で出す。
- 日付の見出しの行の下に線（墨 6%）を引き、2つ目以降のカラーの見出しの上と凡例の上にも同じ線を引いた（2026-10-23、依頼者の指摘でデザインに合わせた）。
