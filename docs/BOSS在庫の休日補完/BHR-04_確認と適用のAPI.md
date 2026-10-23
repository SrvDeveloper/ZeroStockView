# BHR-04 確認と適用の API

**目的:** 在庫変動履歴をアップロードして、どの日をどう補完するかを確認する API と、確認した内容で調査に保存する API を作る。

**依存:** BHR-02, BHR-03

## 作業

### エンドポイント

- `POST /api/boss-inventory-histories/preview`: ファイル（`file`）を受け取り、BHR-01・02 の結果を返す。データベースには書き込まない。
- `POST /api/boss-inventory-histories`: ファイル（`file`）を受け取り、もう一度同じ計算をしてから保存する（確認のあとに調査が変わっていても、保存する時点の内容で計算する）。
- どちらも `routes/web.php` の `auth` の中に置き、権限はデータ取込と同じ（`role:admin,editor`）。`RoleAuthorizationTest` の一覧にも足す。

### 補完する日の決め方（README の前提 2・3）

- 履歴の期間に含まれる日（`period_from` の日 〜 `period_to` の日）のうち、次のどちらかの日。
  - その日の調査がない
  - その日の調査が `boss_source` が `NULL` または `history`（前回の補完をやり直す）
- `boss_source = report` の日は補完しない。旧5ファイル調査の日も補完しない。
- 期間内に `boss_source = report` の調査が1日もなければ、「在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。」のエラー（422）にする。
- 基準の調査がすべて BHR-02 で使えない（レポートの出力が履歴の始まりより前）ときは、「在庫変動履歴が、BOSSのレポートを出力した時刻より後から始まっています。レポートを出力した日の0:00から出力してください。」のエラー（422）にする。
- 補完する日が1日もなければ、「補完する日がありません。」のエラー（422）にする。

### 確認の応答

```json
{
  "data": {
    "periodFrom": "2026-10-16T00:07:17+09:00",
    "periodTo": "2026-10-20T22:34:43+09:00",
    "rowCount": 701,
    "typeCounts": { "API注文引当": 280, "在庫調整": 84 },
    "baseSurveys": [{ "date": "2026-10-16", "estimatedReportAt": "2026-10-16T07:37:14+09:00", "mismatchCount": 0 }],
    "days": [
      { "date": "2026-10-17", "basisAt": "2026-10-17T09:00:00+09:00", "surveyExists": true, "filledSkuCount": 2180, "nullSkuCount": 4, "nullReasons": { "mismatch": 2, "chainBreak": 1, "negative": 1 } }
    ],
    "unfillableDates": [],
    "mismatches": [{ "skuCode": "twig2-02r", "warehouse": "自社倉庫", "reason": "mismatch" }]
  }
}
```

### 適用

- 1つのトランザクションで、次を行う。
  1. ファイルを `local` ディスクに保存し、`BossInventoryHistory` を作る（`summary` に確認と同じ内容を入れる）。
  2. 補完する日ごとに、調査があれば `survey_skus.boss_own_stock`・`boss_rfc_stock` を再現した値で更新する。調査がなければ、`createFromAmazonImport()` と同じ形で調査を作る（`executed_at` はその日の 9:00、`amazon_source = sp_api`、BOSS 以外の在庫は `NULL`）。
  3. 調査の `boss_source = history`、`boss_basis_at`、`boss_inventory_history_id` を設定する。
- 調査を作ったあと、`SurveyAmazonInventorySyncService::syncSurvey()` で、その日の Amazon 在庫を写す（取込と同じく、コミットの後にもう一度呼ぶ）。
- 調査を作ったときは、調査履歴の上限（500件）の処理（`SurveyHistoryPruner`）も呼ぶ。
- 調査の行のロックは、取込（`import()`）と同じ順で取る（同じ日に取込と補完が同時に走っても壊れないように）。
- 応答は、補完した調査の一覧（`SurveySummaryResource`）と `summary`。

## 対象ファイル

- `src/routes/web.php`
- `src/app/Http/Controllers/Api/BossInventoryHistoryController.php`（新規）、`src/app/Http/Requests/`（新規）
- `src/app/Services/BossInventoryHistoryImportService.php`（新規。BHR-01・02 を呼び、保存する）
- `src/tests/Feature/BossInventoryHistoryApiTest.php`（新規）、`src/tests/Feature/RoleAuthorizationTest.php`

## 完了条件

- PHPUnit で次を確認する。
  - 確認の API が書き込まず、応答が上の形になる。
  - 適用で、調査のある日・ない日（Amazon の取得結果がある・ない）の両方を補完し、`boss_source = history` になる。
  - `boss_source = report` の日と旧5ファイル調査の日を変えない。前回 `history` で補完した日はやり直す。
  - 期間内に基準の調査がないとき・補完する日がないときの 422。
  - 照合で合わない SKU が `NULL` になる。
  - 閲覧者は 403、未ログインは 401。
  - 在庫推移（`GET /api/inventory-trends`）と日次在庫CSV が、補完した日の BOSS の値を返す。
- Pint が通る。

## 確認コマンド

```sh
cd src
php artisan test --compact tests/Feature/BossInventoryHistoryApiTest.php
php artisan test --compact tests/Feature/RoleAuthorizationTest.php
vendor/bin/pint --dirty --format agent
```
