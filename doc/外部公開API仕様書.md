# 外部公開API仕様書

## 1. 概要

本APIは、在庫調査またはAmazon在庫取込の日次SKU別在庫数をJSON形式で取得する外部連携用APIです。

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

指定期間内の在庫を、1日・1品番・1 SKUごとに取得します。調査のない品番・日付に成功したAmazon取込履歴があれば、Amazon数量だけの行も返します。

### 3.2 クエリパラメータ

| 名前 | 型 | 必須 | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| `from` | string | はい | `YYYY-MM-DD` | 取得開始日（指定日を含む） |
| `to` | string | はい | `YYYY-MM-DD` | 取得終了日（指定日を含む）。`from` 以降であること |
| `product_code` | string | いいえ | 最大100文字 | 取得対象の品番。省略時は全品番を対象とする。調査履歴または成功したAmazon取込履歴に存在する品番を指定可 |

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
| `date` | string | 在庫調査日またはAmazon取込日。`YYYY-MM-DD` 形式 |
| `productCode` | string | 品番 |
| `brand` | string \| null | ブランド名。Amazon取込だけの行で商品マスタがない場合は `null` |
| `category` | string \| null | カテゴリ名。Amazon取込だけの行で商品マスタがない場合は `null` |
| `sku` | string | SKUコード |
| `size` | string \| null | サイズ。Amazon取込だけの行でSKUマスタがない場合は `null` |
| `amazonOwn` | integer \| null | 調査の保存値またはAmazon取込履歴の自社出荷在庫数。取込待ち・未取得SKUは `null`。確定した0在庫は `0` |
| `amazonFba` | integer \| null | 調査の保存値またはAmazon取込履歴のFBA在庫数。取込待ち・未取得SKUは `null`。確定した0在庫は `0` |
| `bossOwn` | integer \| null | BOSS自社倉庫在庫数（実在庫数 - 引当済数）。Amazon取込だけの行は `null` |
| `bossRfc` | integer \| null | BOSS RFC倉庫在庫数（実在庫数 - 引当済数）。Amazon取込だけの行は `null` |
| `freeStock` | integer \| null | フリー在庫数。Amazon取込だけの行は `null` |
| `ecStock` | integer \| null | ECストック数。Amazon取込だけの行は `null` |

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
- 同じ日に同じ品番の調査があれば、Amazon取込履歴があっても調査SKUの保存値を返します。調査がなく、成功したAmazon取込履歴がある品番は、その日の最新成功分のSKU別Amazon数量を返します。失敗した取込は使いません。最新成功分に含まれない品番に、その日の古い取込値を流用しません。
- Amazon取込だけの行では、`bossOwn`・`bossRfc`・`freeStock`・`ecStock` は `null` です。ブランド・カテゴリ・サイズは現在の商品・SKUマスタから取得し、マスタがなければ `null` です。日別CSVではこれらの `null` を空欄にします。
- 調査も成功したAmazon取込もない日の補間は行わず、行を返しません。
- `data` は日付の昇順、品番の昇順、SKUの登録順で並びます。Amazon取込だけの行でSKUマスタがない場合はSKUコード順です。
- BOSS・ECストック・フリー在庫は調査時点のファイル取込値です。Amazon数量は旧5ファイル調査、新しい3ファイル調査とも調査SKUに保存された値を返します。新しい3ファイル調査では、日本時間の調査日と同日に成功した最新のSP-API結果をSKUコードで照合します。調査が取得より先ならAmazonは `null`（取込待ち）となり、取得成功後に同日の調査SKUが更新されます。同日に複数回成功した場合は最新の成功分に更新します。ASINがない対象外SKUのAmazon数量も `null` のままです。取得済みの0在庫は数値の `0` で返します。
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
