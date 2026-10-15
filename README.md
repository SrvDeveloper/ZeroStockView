# ZeroStockView 開発環境

アプリケーションのコマンドは `src/` ディレクトリで実行します。

## 手動のAmazon在庫取込が「受付」のままの場合

開発環境では、Web画面で受け付けたAmazon在庫取込をキューワーカーが処理します。ワークスペース直下から次を実行してください。

```bash
cd src
php artisan queue:work amazon_inventory
```

`INFO  Processing jobs from the [amazon-inventory] queue.` が表示されたら、ワーカーは起動しています。取込画面で状態が「処理中」から「成功」または「失敗」に変わることを確認してください。ジョブの処理が終わったら `Ctrl+C` でワーカーを停止できます。コマンドは起動したまま、後から受け付けたジョブも処理します。

`amazon_inventory` 接続には `amazon-inventory` キューが設定され、取込ジョブ自身に試行回数1回とタイムアウト4200秒が設定されています。開発環境での手動処理に `--queue`、`--tries`、`--timeout` の指定は不要です。

失敗した場合は取込画面のエラー、`src/storage/logs/laravel.log`、および次のコマンドで失敗ジョブを確認します。

```bash
php artisan queue:failed
```

本番環境のCronとLaravel Schedulerによる自動処理は[Amazon在庫取込の定期運用](doc/Amazon在庫取込の定期運用.md)を参照してください。
