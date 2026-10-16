# UIR-16 （任意）CrossWalker 連携ランプ

**目的:** サイドバーの連携ステータスに、CrossWalker への直近の接続結果を表示する。実施するかは、UIR-07 の完了後に判断する。

**依存:** UIR-07

## 作業

- `CrossWalkerClient` の各リクエストの成否と時刻を記録する。
  - 記録先の候補は、キャッシュ（`Cache::forever`）か専用テーブル。履歴が不要なら、キャッシュに最新 1 件だけ持つ方式を推奨する。
  - 失敗時は `CrossWalkerException` の内容のうち、画面に出してよい要約だけを保存する。認証情報や URL のクエリは保存しない。
- セッション認証の JSON エンドポイントを追加する（例: `GET /api/integrations/status`）。応答は camelCase とし、`resources/js/api/` に型と取得関数を追加する。
- UIR-07 の枠に「CrossWalker」の行を足す。最後の成功が正常、最後の失敗が異常、記録なしが注意で、右端に時刻を出す。

## 対象ファイル

- `src/app/Services/CrossWalkerClient.php`
- `src/routes/web.php`、新規コントローラー
- `src/resources/js/api/`、`src/resources/js/components/AppSidebar.vue`
- `src/tests/Feature/`（新規テスト）

## 完了条件

- PHPUnit で、成功、失敗、記録なしの応答と、認証が必要なことを確認する。CrossWalker は `Http::fake` で置き換える。
- `vendor/bin/pint --dirty --format agent` を実行する。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
php artisan test --compact --filter=Integration
vendor/bin/pint --dirty --format agent
npm run check
```
