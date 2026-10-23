# BHR-05 既存の取込・再照合との組み合わせ

**目的:** 補完した調査に、あとからファイルを取り込んだり、再照合したりしたときに、再現した BOSS の値が消えたり、実際のレポートより優先されたりしないようにする。

**依存:** BHR-04

## 作業

- **あとから BOSS のレポートを取り込んだとき**（README の前提 6）: 実際のレポートの値で上書きし、`boss_source = report` にして、`boss_basis_at`・`boss_inventory_history_id` を空にする。
- **BOSS 以外のファイル（KEEP一覧表・在庫一覧照会表）だけを取り込んだとき**: 今の `import()` は、その日に BOSS のファイルがないと BOSS を `NULL` にする。`boss_source = history` の調査では、再現した値を残す（取込の前の `survey_skus` の BOSS の値を引き継ぐ）。
- **再照合（`reimport`）**: 保存済みのファイルだけで照合し直すので、`boss_source = history` の調査では、照合し直したあとに、記録した `BossInventoryHistory` のファイルで BOSS を再現し直す。ファイルの期限が過ぎていれば、照合し直す前の BOSS の値を SKU コードで引き継ぐ（品番・SKU が変わって新しく入った SKU は `NULL`）。
  - 補完で作った調査は保存済みの取込ファイルがないため、今の規則では再照合できない（「再照合に使える取込ファイルがないため、再照合できません。」）。この動作は変えない。
- **Amazon の同期**（`syncSurvey`・`syncDate`）: BOSS の値と `boss_source` を変えないことをテストで確かめる。
- **調査を削除したとき**: 補完した調査を削除しても、`BossInventoryHistory` の記録は残る（外部キーは調査側にあるので、そのままでよい）。
- 調査の Resource（`SurveySummaryResource::sharedFields`）に `bossSource`（`report`／`history`／`null`）と `bossBasisAt` を足す。TypeScript の型（`resources/js/types/index.ts`）も更新する。表示は BHR-07 で行う。

## 対象ファイル

- `src/app/Services/InventoryImportService.php`
- `src/app/Services/BossInventoryHistoryImportService.php`
- `src/app/Http/Resources/SurveySummaryResource.php`
- `src/resources/js/types/index.ts`
- 関連する PHPUnit（`ApiBackendTest`、`SurveyDifferentialWriteTest` など）

## 完了条件

- PHPUnit で次を確認する。
  - 補完した日に BOSS のレポートを取り込むと、実際の値で上書きされ `report` になる。
  - 補完した日に KEEP一覧表・在庫一覧照会表だけを取り込んでも、再現した BOSS の値が残る。
  - `history` の調査の再照合で、履歴ファイルがあれば再現し直し、期限切れなら SKU コードで引き継ぐ。
  - Amazon の同期で BOSS の値と `boss_source` が変わらない。
  - 調査の応答に `bossSource`・`bossBasisAt` が入る。
- `npm run typecheck` と Pint が通る。

## 確認コマンド

```sh
cd src
php artisan test --compact tests/Feature/BossInventoryHistoryApiTest.php tests/Feature/ApiBackendTest.php tests/Feature/SurveyDifferentialWriteTest.php
vendor/bin/pint --dirty --format agent
npm run typecheck
```
