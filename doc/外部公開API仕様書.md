# 外部公開API仕様書

## 1. 概要

本APIは、在庫調査で取り込んだ日次のSKU別在庫数をJSON形式で取得する外部連携用APIです。

| 項目 | 内容 |
| --- | --- |
| ベースURL | `https://{host}/api/v1` |
| データ形式 | JSON |
| 文字コード | UTF-8 |
| 日付の基準 | 日本標準時（Asia/Tokyo） |
| 認証 | APIキーおよび接続元IPアドレス |
| レート制限 | 接続元IPアドレスごとに60回/分 |

`{host}` は利用環境のホスト名に置き換えてください。

## 2. 認証・アクセス制限

すべてのリクエストに、発行済みのAPIキーを `X-API-Key` ヘッダーで指定します。`Bearer` 認証ではありません。

```http
X-API-Key: zsv_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

リクエストが成功するには、以下の条件をすべて満たす必要があります。

1. 外部APIが有効であること
2. `X-API-Key` の値が有効であること
3. 接続元IPアドレスが、許可済みのIPアドレスまたはCIDRに一致すること

APIキーは機密情報として管理し、URLのクエリパラメータには含めないでください。APIキーが再発行された場合、旧キーは利用できません。

## 3. 日次在庫取得

### 3.1 エンドポイント

```http
GET /api/v1/inventory/daily
```

指定期間内の在庫を、1日・1品番・1 SKUごとに取得します。

### 3.2 クエリパラメータ

| 名前 | 型 | 必須 | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| `from` | string | はい | `YYYY-MM-DD` | 取得開始日（指定日を含む） |
| `to` | string | はい | `YYYY-MM-DD` | 取得終了日（指定日を含む）。`from` 以降であること |
| `product_code` | string | いいえ | 最大100文字 | 取得対象の品番。省略時は全品番を対象とする。調査履歴に存在する品番のみ指定可 |

`from` から `to` までの期間は、両端の日を含め365日以内で指定します。

### 3.3 リクエスト例

```bash
curl --request GET \
  --url 'https://example.com/api/v1/inventory/daily?product_code=A-1001&from=2026-09-27&to=2026-09-28' \
  --header 'Accept: application/json' \
  --header 'X-API-Key: zsv_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
```

### 3.4 成功レスポンス

HTTPステータス: `200 OK`

```json
{
  "data": [
    {
      "date": "2026-09-27",
      "productCode": "A-1001",
      "brand": "ALPHA",
      "category": "トップス",
      "sku": "A-1001-01-M",
      "size": "M",
      "amazonOwn": 10,
      "amazonFba": 11,
      "bossOwn": 12,
      "bossRfc": 13,
      "freeStock": 14,
      "ecStock": 15
    }
  ],
  "meta": {
    "from": "2026-09-27",
    "to": "2026-09-28",
    "productCode": "A-1001",
    "count": 1
  }
}
```

#### `data` の項目

| 名前 | 型 | 説明 |
| --- | --- | --- |
| `date` | string | 在庫調査日。`YYYY-MM-DD` 形式 |
| `productCode` | string | 品番 |
| `brand` | string | ブランド名 |
| `category` | string | カテゴリ名 |
| `sku` | string | SKUコード |
| `size` | string | サイズ |
| `amazonOwn` | integer \| null | Amazon自社出荷在庫数。新しい3ファイル調査では取込元のAmazon数量を持たないため `null` |
| `amazonFba` | integer \| null | Amazon FBA在庫数。新しい3ファイル調査では取込元のAmazon数量を持たないため `null` |
| `bossOwn` | integer | BOSS自社倉庫在庫数（実在庫数 - 引当済数） |
| `bossRfc` | integer | BOSS RFC倉庫在庫数（実在庫数 - 引当済数） |
| `freeStock` | integer | フリー在庫数 |
| `ecStock` | integer | ECストック数 |

#### `meta` の項目

| 名前 | 型 | 説明 |
| --- | --- | --- |
| `from` | string | リクエストで指定した取得開始日 |
| `to` | string | リクエストで指定した取得終了日 |
| `productCode` | string \| null | リクエストで指定した品番。`product_code` 省略時は `null` |
| `count` | integer | `data` の要素数 |

対象データがない場合も `200 OK` となり、`data` は空配列、`meta.count` は `0` になります。

```json
{
  "data": [],
  "meta": {
    "from": "2026-09-27",
    "to": "2026-09-28",
    "productCode": null,
    "count": 0
  }
}
```

### 3.5 データの選択・並び順

- 同じ日に同じ品番の調査が複数ある場合、最も新しい調査結果のみを返します。
- 調査がない日の補間は行われず、その日のデータは返されません。
- `data` は日付の昇順、品番の昇順、SKUの登録順で並びます。
- BOSS・ECストック・フリー在庫は調査時点のファイル取込値です。旧5ファイル調査のAmazon数量も保存済みの値を返します。新しい3ファイル調査のAmazon数量は `null` とし、別管理のSP-API在庫はこのAPIに混ぜません。
- 返却後に行われた取込みや再照合により、同一条件の取得結果が変わる場合があります。

## 4. エラーレスポンス

| HTTPステータス | 発生条件 |
| --- | --- |
| `401 Unauthorized` | APIキーが未指定、または無効 |
| `403 Forbidden` | 接続元IPアドレスが許可対象外 |
| `422 Unprocessable Content` | クエリパラメータが不正 |
| `429 Too Many Requests` | 1分間のリクエスト数が60回を超過 |
| `503 Service Unavailable` | 外部APIが無効 |

#### 401: APIキーエラー

```json
{
  "message": "APIキーが正しくありません。"
}
```

#### 403: 接続元IPエラー

```json
{
  "message": "このIPアドレスからの接続は許可されていません。"
}
```

#### 422: 入力エラー

`errors` のキーは不正なパラメータ名、値はエラーメッセージの配列です。メッセージの文言は環境の言語設定によって異なる場合があるため、判定には `errors` のキーを使用してください。

```json
{
  "message": "対象期間は365日以内で指定してください。",
  "errors": {
    "to": [
      "対象期間は365日以内で指定してください。"
    ]
  }
}
```

#### 429: レート制限エラー

```json
{
  "message": "Too Many Attempts."
}
```

#### 503: API無効エラー

```json
{
  "message": "外部APIは現在無効です。"
}
```
