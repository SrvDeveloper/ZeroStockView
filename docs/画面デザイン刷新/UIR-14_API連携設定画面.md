# UIR-14 API連携設定画面

**目的:** API連携設定画面（`ApiConnectionSettingsPage.vue`）を新デザインの 2 列構成にする。

**依存:** UIR-04, UIR-05

## 作業

- タイトル行の右に主ボタン「保存」（既存の `data-testid="save-api-settings"`）。
- 左の列。
  - **API有効状態**: 見出しの右に `StatusLamp`（有効／無効）。本文は `BaseToggle` と説明文。
  - **APIキー**: キーの表示枠（等幅、補助面の地）、発行日時、「発行／再発行」ボタン（既存の `data-testid="rotate-api-key"`）。発行直後にだけ平文を表示し、控えるよう促す注意文を出す既存の動作は変えない。
  - **接続情報**（新規・表示のみ）: メソッド `GET`、パス `/api/v1/inventory/daily`、認証 `X-API-Key ヘッダー`、回数制限 `60 回 / 分`、既存の「APIリファレンス」モーダルを開くボタン。
    - 値は `doc/外部公開API仕様書.md` と `routes/web.php` の内容に合わせ、画面側の定数として持つ。
- 右の列。
  - **許可IPアドレス / CIDR**: 追加欄（IP / CIDR は等幅、メモ、「追加」）と一覧（IP／CIDR の札、アドレス（等幅）、メモ、削除）。
- 読込エラーと入力エラーの `BaseAlert` の表示は残す。

## 対象ファイル

- `src/resources/js/pages/ApiConnectionSettingsPage.vue`
- `src/resources/js/pages/ApiConnectionSettingsPage.test.ts`

## 完了条件

- 接続情報の値が仕様書と一致していることを確認する（目視で照合し、レビューで確認する）。
- 既存のキー発行、許可ネットワークの追加・削除、保存のテストが通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
npx vitest run resources/js/pages/ApiConnectionSettingsPage.test.ts
grep -n "inventory/daily\|throttle" routes/web.php
npm run check
npm run build
```

**実装状況（2026-10-16）:** 左列に「API有効状態」（ランプ）、「APIキー」、「接続情報」（新規・表示のみ）、右列に「許可IPアドレス / CIDR」を置く 2 列構成にした。「保存」はタイトル行の右へ移した。接続情報の値（`GET /api/v1/inventory/daily`、`X-API-Key`、60 回 / 分）は `routes/web.php` の定義（`throttle:60,1`）と照合し、画面側の定数 `API_ENDPOINT` に持たせた。既存のテストは変更なしで通る。
