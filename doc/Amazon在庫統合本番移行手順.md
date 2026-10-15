# Amazon在庫統合の本番移行（AMZSYNC-09）

本手順は XServer の本番環境で、既存履歴を `survey_skus` のAmazon保存値へ移すためのもの。コマンドはアプリケーションの `src/` で、既存の[定期運用手順](Amazon在庫取込の定期運用.md)と同じ PHP 8.3 CLI（例: `/usr/bin/php8.3`）を使う。`php` が旧バージョンを指す環境では使わない。時刻と調査日の照合は `Asia/Tokyo`。

## 事前準備

1. スキーマ・アプリ・ビルド済み画面を同じリリースとして用意する。作業中のAmazon取込と調査を完了させ、XServerパネルの5分間隔Cronを一時停止する。キューの処理中ジョブがないことを確認する。ジョブや履歴は削除しない。
2. DBと `src/storage/app/private/survey-imports` のバックアップを同じ時点で取得する。DBには `surveys`、`survey_products`、`survey_skus`、`survey_files`、`amazon_inventory_imports`、`amazon_inventory_snapshots` を含める。バックアップの保存先、復元方法、取得時刻を作業記録に残す。DBや監査JSONは公開ディレクトリやリポジトリに置かない。
3. Webをメンテナンス状態にし、Cronが停止していることを再確認する。直前の[移行前監査](Amazon在庫統合移行監査手順.md)を実行し、件数・判定不能ID・日別成功分・旧履歴のAmazon数量を保存する。

```sh
/usr/bin/php8.3 artisan down
/usr/bin/php8.3 artisan survey:amazon-audit --json > /private/path/amazon-audit-before.json
```

`/private/path` は公開領域外の、アクセス権を制限した保存先に置き換える。

## スキーマとアプリの配置

対応するコード、依存ライブラリ、ビルド済み `public/build` を同じリリースで配置し、環境変数と `storage` を引き継ぐ。その後、以下を実行する。`survey:amazon-backfill` はスキーマ変更後にだけ動く。

```sh
/usr/bin/php8.3 artisan migrate --force
/usr/bin/php8.3 artisan optimize:clear
/usr/bin/php8.3 artisan migrate:status
/usr/bin/php8.3 artisan schedule:list
```

`2026_10_15_000000_allow_pending_amazon_stock_on_surveys` の適用を確認する。既存の同名カラムがない状態で画面を公開しない。

## dry-run と適用

通常の `survey:amazon-backfill` は読取り専用の dry-run。`--json` は全調査の分類、理由、調査日、SKUの補正前・予定合計、NULL件数、適用済み・最新成功取込ID、旧5ファイル履歴の保護指紋値を返す。`--apply` を付けた場合だけ更新する。

```sh
/usr/bin/php8.3 artisan survey:amazon-backfill --json > /private/path/amazon-backfill-plan.json
/usr/bin/php8.3 artisan survey:amazon-backfill --apply --json > /private/path/amazon-backfill-applied.json
/usr/bin/php8.3 artisan survey:amazon-backfill --json > /private/path/amazon-backfill-after.json
/usr/bin/php8.3 artisan survey:amazon-audit --json > /private/path/amazon-audit-after.json
```

適用前に `summary.legacy_file`・`three_file`・`ambiguous` が監査の分類件数と一致することを確認する。`surveysToChange` と `skusToChange`、各調査の `before`・`expected`、`latestSuccessfulImportId` を記録する。判定不能なIDは作業記録へ移し、原因を調べるまで自動更新しない。差分に想定外の調査や数量があれば適用を止める。

バッチは調査1件ごとにDBトランザクションを使い、監査と同じ判定規則でロック後に再判定する。確実な3ファイル履歴だけ `amazon_source=sp_api` とし、共通の同期サービスで同日の最新成功分を適用する。同日の成功分がなければAmazon自社・FBA数量を `NULL` にし、誤った取込IDを解除する。失敗した取込、前日以前の取込、旧5ファイルと判定不能の履歴はAmazon数量の供給元に使わない。途中で失敗した場合は該当IDで止まり、完了済みの調査は再実行しても同じ値になる。原因を直して同じコマンドを再実行する。

適用後の dry-run で `surveysToChange=0`、`skusToChange=0` を確認する。適用前後の `protectedFingerprint` が旧5ファイルの各調査IDで一致することを確認する。指紋値はSKU別Amazon数量・SKU/品番メモ・保存ファイルのIDと情報から算出する。3ファイルの `pending`・`partial`・`complete` 件数と、複数の調査日・SKUの保存値と日別合計を照合する。判定不能の履歴は変化していないことを確認する。

## 再開後の確認

1. `/usr/bin/php8.3 artisan up` でWebを再開し、XServerパネルで既存の5分間隔Cronを再開する。`storage/logs/scheduler.log` と `storage/logs/laravel.log` に異常がないことを確認する。
2. 調査履歴で旧5ファイルのAmazon値が維持され、3ファイルの取込待ちは「—」、確定した0在庫は「0」と表示されることを確認する。対象日と同日のAmazon取得前後、同日再取得の両順序を検証する。
3. 同じSKUと日付を、調査結果・Excel・在庫推移・日別CSV・外部APIで照合する。Amazon未確定のSKUの合計が0や部分合計になっていないことを確認する。
4. `/usr/bin/php8.3 artisan schedule:list` で9:00の取込と5分間隔の再同期・ワーカーを確認する。取込画面に新しい定期実行が現れない場合はCronとキューを調べる。

失敗時は取込とCronを止め、変更済み調査IDと差分を記録する。補正バッチの一時エラーは原因解消後に再実行する。DBとファイルを戻す必要がある場合は、同じ取得時点のバックアップを組で復元する。NULLと取得方式を保存した履歴に対して `migrate:rollback` を実行しない。

本番データの監査・適用・画面確認は、本番環境への配置後に実施する。
