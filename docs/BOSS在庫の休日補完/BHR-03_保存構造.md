# BHR-03 保存構造

**目的:** 在庫変動履歴の取込記録と、調査ごとの BOSS 在庫の取得方式（レポートか、履歴からの再現か）を保存できるようにする。

**依存:** なし

## 作業

### 在庫変動履歴の取込記録

- `boss_inventory_histories` テーブルとモデル `BossInventoryHistory` を作る。

| 列 | 内容 |
| --- | --- |
| `original_name`、`disk`、`stored_path`、`mime_type`、`size_bytes` | 元のファイル（`SurveyFile` と同じ扱いで `local` ディスクに保存） |
| `period_from`、`period_to` | 履歴の最初と最後の処理日時 |
| `row_count` | 読み込んだ行数（自社倉庫・RFC倉庫の行） |
| `summary` | 照合と補完の結果（JSON。補完した日、日ごとの SKU 数、`NULL` の理由ごとの件数、推定したレポートの時刻） |
| `uploaded_by` | 取り込んだユーザー（`users.id`、NULL 可） |
| `uploaded_at`、`expires_at` | 取込日時と、30日後の期限（README の前提 7） |

- `PruneExpiredSurveyFiles`（`survey-files:prune`）で、期限の来た履歴ファイルも消す。記録の行は残し、ファイルの列だけ空にする（どの日をどの履歴で補完したかを残すため）。

### 調査の BOSS の取得方式

- `surveys` に次の列を足す。
  - `boss_source`（文字列、NULL 可）: `report`（BOSS のレポートを取り込んだ）／`history`（在庫変動履歴から再現した）／`NULL`（BOSS の値がない）
  - `boss_basis_at`（日時、NULL 可）: `history` のときの再現した時点（その日の 9:00）
  - `boss_inventory_history_id`（NULL 可、`boss_inventory_histories` への外部キー、削除時は NULL）
- `Survey` に定数 `BOSS_SOURCE_REPORT`、`BOSS_SOURCE_HISTORY` を足す。
- 既存の調査の `boss_source` は、マイグレーションで、BOSS のレポートの `SurveyFile` がある調査を `report`、それ以外を `NULL` にする。旧5ファイル調査も、BOSS のファイルがあれば `report` にする。
- `InventoryImportService::import()` で BOSS のレポートを取り込んだときは `boss_source = report` にする（BOSS のファイルがない取込では変えない。BHR-05 で詳しく扱う）。
- ファクトリに `history()` の状態を足す。

## 対象ファイル

- `src/database/migrations/`（新規 2本）
- `src/app/Models/BossInventoryHistory.php`（新規）、`src/database/factories/BossInventoryHistoryFactory.php`（新規）
- `src/app/Models/Survey.php`、`src/database/factories/SurveyFactory.php`
- `src/app/Console/Commands/PruneExpiredSurveyFiles.php`
- `src/app/Services/InventoryImportService.php`（`boss_source = report` の記録だけ）
- 関連する PHPUnit

## 完了条件

- PHPUnit で次を確認する。
  - マイグレーション後、BOSS のファイルがある既存の調査が `report`、ない調査が `NULL` になる。
  - BOSS のレポートを取り込むと `boss_source = report` になる。
  - 期限の来た履歴ファイルが消え、記録の行は残る。
- 開発の MySQL でもマイグレーションとロールバックを確かめる。
- Pint が通る。

## 確認コマンド

```sh
cd src
php artisan migrate
php artisan migrate:rollback --step=2 && php artisan migrate
php artisan test --compact --filter=BossInventoryHistory
php artisan test --compact tests/Feature/SurveyHistoryLimitTest.php
vendor/bin/pint --dirty --format agent
```

**実装状況（2026-10-23）:** `boss_inventory_histories` テーブルと `BossInventoryHistory` モデル・ファクトリ、`surveys` の `boss_source`・`boss_basis_at`・`boss_inventory_history_id` と `Survey::BOSS_SOURCE_REPORT`・`BOSS_SOURCE_HISTORY`、ファクトリの `history()` を追加した。BOSS のレポートを取り込むと（再照合を含む）`boss_source = report` にし、`boss_basis_at` と `boss_inventory_history_id` を空にする。`survey-files:prune` は、期限の来た履歴ファイルを消して、記録の行の `disk`・`stored_path` を空にする（`original_name` などは残す）。開発の MySQL でマイグレーション・2段のロールバック・再マイグレーションを確かめた。チケットから変えた点は次のとおり。

- 既存の調査の `boss_source` は、BOSS のレポートの `SurveyFile` がある調査に加えて、BOSS の在庫（`boss_own_stock`・`boss_rfc_stock`）が1つでも保存されている調査も `report` にした。期限の来た取込ファイルは `SurveyFile` の行ごと消しているので、ファイルだけでは古い調査を見分けられないため。
- マイグレーションのテスト（`BossSourceMigrationTest`）は `RefreshDatabase` ではなく `DatabaseMigrations` を使った。SQLite は外部キーの列を足すときに表を作り直し、テストのトランザクションの中では外部キーを止められずに子の行（取込ファイル・調査の品番）が消えるため。トランザクションの外の SQLite のファイルでは子の行が消えないことも確かめた。
- 在庫変動履歴のファイルの保存先（`boss-inventory-histories/`）への保存は、BHR-04 の取込の API で行う。
