# TRD-01 品番一覧の集計 API

**目的:** 品番一覧画面（TRD-03）に出す、品番ごとの最新在庫・前週比・推移・SKUの状況・欠品の集計と、画面上部の全体の数値を返す API を作る。

**依存:** なし

## 作業

### エンドポイント

- `GET /api/inventory-trends/overview` を `routes/web.php` に追加する（`auth`、既存の在庫推移と同じ権限）。`InventoryTrendController` に置くか、集計が大きければ `InventoryTrendOverviewService` に分ける。
- リクエスト（`ListInventoryTrendOverviewRequest`）

| パラメータ | 内容 |
| --- | --- |
| `from`, `to` | 表示期間（`Y-m-d`）。最大365日（`MaximumDateRange`） |
| `scope` | 在庫区分（`mallTotal`／`amazon`／`boss`／`free`／`stock`／`grandTotal`） |
| `group` | 任意。`setting:{id}`、`ungrouped`（グループなし）、`legacy:{名前}` |
| `brand` | 任意 |
| `search` | 任意。品番・SKU・ASIN の部分一致（SKU・ASIN は期間内の調査の SKU で照合） |
| `focus` | 任意。`stockout`（欠品SKUあり）／`increased`（欠品が増えた）／`decreasing`（在庫が10%以上減った）／`long`（30日以上の欠品） |
| `sort` | `stockout`（欠品SKUの多い順、初期値）／`delta`（減少の大きい順）／`latest`（最新在庫の少ない順）／`code`（品番順） |
| `page` | 1始まり。1ページ25件 |

### 応答

```json
{
  "data": [
    {
      "productCode": "1000",
      "brand": "ALPHA",
      "category": "アウター",
      "skuCount": 12,
      "groups": [{ "key": "setting:3", "name": "売上TOP20" }],
      "latest": { "date": "2026-10-23", "quantity": 143 },
      "weekAgo": { "date": "2026-10-16", "quantity": 199 },
      "points": [{ "date": "2026-09-24", "quantity": 231 }],
      "skuStates": ["inStock", "stockout", "pending"],
      "stockoutSkuCount": 3,
      "stockoutSkuCountWeekAgo": 2,
      "stockoutSkuDays": 43,
      "longestStockoutStreak": 26
    }
  ],
  "summary": {
    "productCount": 326,
    "skuCount": 2184,
    "stockoutSkuCount": 148,
    "stockoutSkuCountWeekAgo": 136,
    "longStockoutSkuCount": 37,
    "longStockoutProductCount": 21,
    "totalQuantity": 48210,
    "totalQuantityWeekAgo": 49803,
    "focusCounts": { "all": 326, "stockout": 58, "increased": 9, "decreasing": 14, "long": 21 }
  },
  "latestSurvey": { "date": "2026-10-23", "amazonInventoryStatus": "complete", "amazonBasisAt": "2026-10-23T09:42:00+09:00" },
  "meta": { "page": 1, "perPage": 25, "total": 326 },
  "brands": ["ALPHA", "BRAVO"],
  "groups": [{ "key": "setting:3", "name": "売上TOP20" }, { "key": "ungrouped", "name": "グループなし" }],
  "dateRange": { "from": "2025-11-01", "to": "2026-10-23" }
}
```

- `points` は期間の毎日（調査のない日は `quantity: null`）。品番全体の合計で、1 SKU でも未確定なら `null`（`sumWarehouses()` と同じ規則）。
- `latest` は期間内で最新の調査日。`weekAgo` は README の前提 6 の規則で選ぶ。どちらも見つからなければ `null`。
- `skuStates` は最新の調査日の、SKU の `sort_order` 順の状態。`inStock`（1以上）／`stockout`（確定した0）／`pending`（`NULL`）。
- `stockoutSkuDays` は期間内の「欠品した SKU × 日」の合計。`longestStockoutStreak` は最新の調査日まで続いている欠品の、最も長い連続日数（README の前提 4。調査のない日は連続を切らない）。
- `summary` と `focusCounts` は、`group`・`brand`・`search` で絞ったあと、`focus` で絞る前の全品番について数える。`focus` を変えても上部の数値は変わらない。
- `groups` は、一覧のグループの選択肢。調査結果画面と同じく、どのグループにも入っていない品番があれば「グループなし」を最後に足す。

### 集計の方法と性能

- `survey_skus` を `survey_products`・`surveys` と結合し、日（`survey_date`、無ければ `executed_at` の東京日付）× 品番で SQL で集計する。品番ごとに Eloquent のモデルを作らない。
- `NULL` の判定は `COUNT(列)` と `COUNT(*)` の比較などで行い、未確定を含む合計を作らない。在庫区分ごとの数量の決め方は `InventoryTrendController::quantity()`・`warehouseQuantities()` と同じにし、ロジックを共通化する（PHP と SQL の二重実装にする場合は、同じ結果になることをテストで固定する）。
- 1日に複数の調査がある古い期間は、`show()` と同じく、その日の最後の調査を使う。
- 計測用に `InventoryTrendOverviewBenchmarkTest`（`InventoryTrendExportBenchmarkTest` にならう、通常はスキップ）を追加し、2,000 SKU × 90日の応答時間とクエリ数を記録する。目安は1秒以内。超える場合は、索引の追加か、日×品番の集計テーブルの作成を別チケットとして依頼者に提案する。

## 対象ファイル

- `src/routes/web.php`
- `src/app/Http/Controllers/Api/InventoryTrendController.php`（または新しいサービス）
- `src/app/Http/Requests/ListInventoryTrendOverviewRequest.php`（新規）
- `src/resources/js/api/inventoryTrends.ts`（型と `fetchInventoryTrendOverview()` の追加）
- `src/tests/Feature/InventoryTrendOverviewApiTest.php`（新規）、`InventoryTrendOverviewBenchmarkTest.php`（新規）

## 完了条件

- PHPUnit で次を確認する。
  - 同じ日・同じ品番の `points` の値が、`GET /api/inventory-trends` の `warehouseTotals` から計算した値と、全在庫区分で一致する。
  - 未確定の SKU を含む日は `null` になり、欠品に数えない。ASIN のない SP-API 調査の SKU は Amazon を 0 として扱う。
  - 前週比の比較日の選び方（7日前がある／ない／14日を超える）。
  - `focus` の4種類と `sort` の4種類、`group`（`ungrouped` を含む）・`brand`・`search` の絞り込み、ページ送り。
  - 連続欠品日数が、調査のない日で途切れず、表示期間より前（最大90日）までさかのぼる。
  - `summary` と `focusCounts` が `focus` に影響されない。
  - 未ログインは 401、パラメータの誤りは 422。
- `vendor/bin/pint --dirty --format agent` と `npm run typecheck` が通る。

## 確認コマンド

```sh
cd src
php artisan test --compact tests/Feature/InventoryTrendOverviewApiTest.php
php artisan test --compact tests/Feature/InventoryTrendApiTest.php
vendor/bin/pint --dirty --format agent
npm run typecheck
```

**実装状況（2026-10-23）:** `GET /api/inventory-trends/overview` と `InventoryTrendOverviewService` を追加した。品番 × 日の合計と欠品 SKU 数は、調査 × 品番の `GROUP BY` で SQL で集計し、在庫区分の数量は `InventoryTrendController::quantity()` と同じ規則を SQL の式で書いた（全在庫区分で `GET /api/inventory-trends` の `warehouseTotals` と一致することをテストで固定）。Amazon の区分では、在庫推移の SKU 別と同じく、Amazon 取得の対象外の SKU（ASIN のない SP-API 調査の SKU で、Amazon の値がない）を SKU 数・SKU の状態・欠品から除く。連続欠品日数は「最後に在庫（1以上）があった日から最新の調査日までの日数」とし、さかのぼれる範囲（90日）に在庫のあった日がなければ、範囲内で最初に調査に出てきた日から数える。同じ日に調査が複数ある古い期間では、連続欠品日数だけ「その日のどれかの調査で在庫があれば在庫あり」とみなす（合計・欠品 SKU 数は品番ごとにその日の最後の調査を使う）。推移（`points`）は、表示するページの品番の分だけ作る。応答には、チケットの例に加えて `from`・`to` を入れた。TypeScript の型と `fetchInventoryTrendOverview()` を `resources/js/api/inventoryTrends.ts` に追加した。

計測（`InventoryTrendOverviewBenchmarkTest`、200品番・2,000 SKU・毎日の調査、インメモリ SQLite、在庫区分は総数）:

| 表示期間 | 所要時間 | SQL | SQLの時間 | 応答の大きさ | ピークメモリ |
| --- | ---: | ---: | ---: | ---: | ---: |
| 30日 | 528 ms | 8 | 279 ms | 0.04 MB | +20.0 MB |
| 90日 | 432 ms | 8 | 269 ms | 0.10 MB | +18.8 MB |
| 180日 | 602 ms | 8 | 361 ms | 0.18 MB | +34.9 MB |
