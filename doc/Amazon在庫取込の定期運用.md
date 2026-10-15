# Amazon在庫取込の定期運用（XServerレンタルサーバー）

アプリケーションの作業ディレクトリは `src/`。時刻は Asia/Tokyo で記載する。

## 起動設定

XServerレンタルサーバーでは、SSH接続だけを根拠に常駐プロセスを運用しない。[XServerのCron設定](https://www.xserver.ne.jp/manual/man_program_cron.php)から、Laravel の `schedule:run` を5分ごとに起動する。9:00の判定とキュー起動の順序は Laravel Scheduler が管理する。XServerのサーバーパネルで、分を `*/5`、時・日・月・曜日を `*` に設定し、次のコマンドを登録する。アプリのパスは実際の配置先に置き換える。プロジェクトは PHP 8.3 以上が必要なので、[XServerが案内するCLIの明示パス](https://www.xserver.ne.jp/manual/man_program_soft.php)を使う。

```sh
cd /path/to/project/src && /usr/bin/php8.3 artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Scheduler は毎日9:00に `amazon-inventory:queue` を実行し、手動取込と同じ `amazon_inventory` 接続の `amazon-inventory` キューへジョブを登録する。東京日付で同じ日の定期取込を再投入しない。手動取込が実行中なら定期取込を投入せず、アプリケーションログに警告を残す。5分ごとに、2時間以上完了していない取込を失敗として確定する。また、当日のAmazon在庫が未反映の3ファイル調査があれば、取得済みの最新成功分を5分ごとに反映し直す。成功した取得の原本と調査の保存済み数量は、反映エラーで削除されない。

キュージョブがある場合だけ、Scheduler が専用ワーカーを起動する。ワーカーは最大1件を処理し、キューが空なら終了する。次のジョブは次回の5分間隔で処理する。手動取込の開始は最大5分待つ。`withoutOverlapping(90)` と共有キャッシュでワーカーの重複起動を抑止する。XServerでの常駐 `schedule:work`、常駐 `queue:work`、Supervisor は設定しない。[LaravelのScheduler](https://laravel.com/framework/docs/13.x/scheduling)は通常、毎分 `schedule:run` を起動する方式を案内しているが、現行の全タスクは9:00、毎日0:00、5分間隔なので、この運用では5分ごとに起動できる。5分より細かいタスクを追加する場合はCron間隔も変更する。

ワーカーのタイムアウトは4200秒、キューの再実行可能時間は4500秒。取込に必要な `.env` と DB 接続は Web と Cron の PHP CLI で共通にする。キャッシュには排他制御に対応した `database` を設定する。ワーカーは毎回終了するため、更新後の `queue:restart` は不要。長い取込が実行中なら完了してから新しいコードが読み込まれる。

XServer側でジョブの実行時間や負荷による制限を受ける可能性がある。70分のワーカータイムアウトはXServerで保証された実行時間ではない。実際の取込が完走するか本番で検証し、途中で終了する場合はXServerサポートに制限を確認するか、常駐ワーカーを運用できる実行基盤へ移す。[XServerのCronに関するFAQ](https://www.xserver.ne.jp/support/faq/service_server_cron.php)も過度の負荷による利用制限に触れている。

## 稼働確認

1. `/usr/bin/php8.3 artisan schedule:list` に `0 9 * * * php artisan amazon-inventory:queue` と、5分ごとの期限切れ確認・`survey:amazon-reconcile` が表示されることを確認する。
2. XServerのサーバーパネルで5分間隔のCron設定を確認し、`storage/logs/scheduler.log` に実行結果が残ることを確認する。SSHでは `/usr/bin/php8.3 -v` と `/usr/bin/php8.3 -m` でCLIのバージョンと `pcntl` 拡張の有無も確認する。`pcntl` がない場合、Laravelのジョブタイムアウトは効かない。
3. 9:00以降、画面の「Amazon 在庫取込」に「定期実行」の履歴が1件増え、`queued` → `running` → `succeeded` と遷移することを確認する。対象 SKU 件数、基準日時、自社発送と FBA の照合件数も確認する。
4. `storage/logs/laravel.log` と `storage/logs/scheduler.log` を確認する。必要に応じて `php artisan queue:failed` でジョブ側の異常を確認する。ログは運用環境の方針に沿ってローテーションする。

## 失敗時

- `queued` のままならXServerのCronとDBキューを確認する。Schedulerが継続して動いていれば、2時間を超えた履歴は5分ごとの確認で `failed` になる。Cron自体が止まった場合は、画面/APIの履歴参照時にも期限切れを判定する。
- `failed` なら履歴に表示される失敗理由と `storage/logs/laravel.log` を確認する。Amazon のレポート生成や接続の失敗も成功として扱わない。
- 原因を解消した後は画面の「Amazon 在庫を取得」で手動再実行する。定期実行コマンドは同じ東京日付に2回投入しないため、当日の失敗を自動再試行しない。
- 古い失敗ジョブが残っていても、そのジョブの `queue:retry` は使わず画面から新しい取込を開始する。失敗した履歴と正常な履歴を区別して保持できる。
- Amazon取得が `succeeded` なのに同日の3ファイル調査が取込待ちのままなら、`storage/logs/laravel.log` の「調査履歴への反映に失敗」を確認する。Schedulerは当日の未反映分を次の5分間隔で再試行する。日付が変わった後や手動で全件を再照合する場合は、アプリの `src/` で `/usr/bin/php8.3 artisan survey:amazon-reconcile YYYY-MM-DD` を実行する。日付は東京日付で指定し、旧5ファイル履歴は対象外とする。
