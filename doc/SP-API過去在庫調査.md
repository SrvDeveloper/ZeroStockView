# SPAPI-07 過去在庫取得の実現性調査

調査日: 2026-10-14。対象は日本の出品アカウントと開発環境DB。XServer本番の稼働確認は含まない。

## 結論

**要件上の判断（2026-10-14）:** 求める「過去在庫」を自社発送数とFBA出荷可能数の当時の値とする場合、未記録日の取得はできないものとして扱う。Inventory Ledgerの期末倉庫残高は別指標なので、この要件を満たす代替値として採用しない。下記SPAPI-08は別指標への要望が生じた場合の候補であり、現時点の実装予定にはしない。

| 在庫区分・期間 | 判定 | 理由 |
| --- | --- | --- |
| Amazon自社発送数・FBA出荷可能数の手動調査済み日 | 既存調査から利用可能 | `survey_skus` に当時のASIN・SKU・各数量が保存されている。調査対象外の品番・SKUは欠損として扱う。 |
| Amazon自社発送数・FBA出荷可能数のSP-API取込済み日 | SP-APIから同じ指標で取得済み | `amazon_inventory_snapshots` に成功取込の数量を保存済み。開発環境では2026-10-14の2回のみ。 |
| 未調査・未取込の過去日におけるAmazon自社発送数・FBA出荷可能数 | 取得不可 | 現行レポートは現在値。過去日時を指定して同じ数量を復元する機能は確認できない。現在値に過去の日付を付けない。 |
| FBAの日次期末倉庫残高 | 別指標としてのみ取得可能 | Inventory Ledger Summary Viewの`Ending Warehouse Balance`を日次で取得できる。現行の`afn-fulfillable-quantity`とは定義が異なる。 |
| BOSS自社/RFC・フリー・ECストック | 既存調査から利用可能 | 手動調査済み日の`survey_skus`に保存。未調査日の過去値をAmazon SP-APIから取得する対象ではない。 |
| モール全体・総数 | 既存調査から利用可能 | 同一手動調査内の数量だけを合算する。欠損日に異なる基準時刻のSP-API値を補って合算しない。 |

Amazonの[Inventory Report](https://developer-docs.amazon/sp-api/docs/report-type-values-inventory)はSKUごとの出品数量を示す現在の一覧で、過去日の残高を示すレポートとは説明されていない。[FBA Manage Inventory](https://developer-docs.amazon/sp-api/docs/report-type-values-fba)も現在の在庫詳細をほぼリアルタイムで示す。Reports APIの`dataStartTime`と`dataEndTime`はレポート種別によって意味が異なり、全レポートの過去スナップショット指定ではない（[createReport](https://developer-docs.amazon/sp-api/reference/createreport)）。FBA Inventory APIの`startDateTime`も「その時点の残高」ではなく、その時刻以降に**変更されたSKUの現在のサマリー**を選ぶ条件である（[getInventorySummaries](https://developer-docs.amazon/sp-api/reference/getinventorysummaries)）。したがって未取得日の同じ指標を復元できない、という判定はこれらの仕様からの推論であり、Amazonの全データ製品について不存在を証明したものではない。

## 開発環境に残る履歴

- 2026-10-14時点で手動調査は18回、8日分。日付は2026-09-16、09-18、09-24、09-25、09-28、09-29、09-30、10-01。累計26品番・276 SKUで、各日の対象は24〜25品番・256〜268 SKU。SKUごとの記録日は1〜8日で、連続した日次系列ではない。
- 既存調査は`surveys.executed_at`と`survey_skus`の各倉庫数量・SKU・`child_asin`を保持する。新規手動取込の`executed_at`は取込時刻であり、元ファイルごとの在庫抽出時刻を別々には保持しない。したがって「その日の任意時刻の厳密な在庫」として扱わない。
- 履歴中にASINが欠けるSKUは8件、同一SKUのASINが変化したものは2件。履歴全体では同じASINが複数の社内SKUに結び付いた例が2件あるが、同一日付内の衝突は今回の集計では0件。過去の対応付けには**対象日時点の履歴**を使い、現行のマスタだけで上書きしない。
- 成功したSP-API取込は2026-10-14の2回で、同日以外のSP-APIスナップショットは開発環境DBにない。レポート本文は保存していない。上記の件数と範囲は開発環境の観測結果であり、本番データの範囲を保証しない。
- 対象日・品番・SKUの行がない場合は欠損。数量0が保存された行と区別する。

## FBA Inventory Ledgerの実アカウント検証

[Amazon公式のFBA Reports仕様](https://developer-docs.amazon/sp-api/docs/report-type-values-fba)によると、`GET_LEDGER_SUMMARY_VIEW_DATA`は`aggregatedByTimePeriod=DAILY`と`aggregateByLocation=COUNTRY`を指定でき、`Date`、`FNSKU`、`ASIN`、`MSKU`、`Disposition`、`Ending Warehouse Balance`、`In Transit Between Warehouses`などを返す。日次の期末**倉庫残高**は、現行FBA Manage Inventoryに別列で存在する**出荷可能数**と同一の指標ではない。`SELLABLE`区分だけを選んでも、期末残高を出荷可能数と呼び替えない。

具体的な違いは次のとおり。

| 項目 | FBA出荷可能数（`afn-fulfillable-quantity`） | Ledger期末倉庫残高（`Ending Warehouse Balance`） |
| --- | --- | --- |
| 基準時刻 | レポート生成時点に近い現在値 | 指定した日次期間の終了時点 |
| 数量の意味 | 注文のためにピッキング・梱包・発送できる数量 | 期末にAmazonのフルフィルメントセンターに残る数量。`Disposition`（`SELLABLE`、破損など）ごとに集計される |
| 予約・移動中 | 予約・処理中など、直ちに出荷できない数量は別の区分 | 注文向けに予約された在庫もLedger側に含まれ得る。倉庫間移動中は別列`In Transit Between Warehouses`を確認する |

Amazonは「Fulfillable」をピッキング・梱包・発送できる在庫、「Reserved」を注文処理中または内部処理中の在庫と定義している（[FBA Inventory API](https://developer-docs.amazon/sp-api/docs/fba-inventory-api)）。`Disposition=SELLABLE`は商品の**状態**であり、「今すぐ新規注文に使える」という意味ではない。Amazonの[Ledger項目定義](https://sellercentral.amazon.com/help/hub/reference/external/GEQ8JEWH5KJC5QCG?locale=en-EN)では期末倉庫残高と倉庫間移動中を別項目とし、[Amazon担当者の説明](https://sellercentral.amazon.com/seller-forums/discussions/t/049699ca-8a05-4c03-9ea2-168705fbdfd0)では予約在庫もLedgerに反映されるとしている。たとえば期末に販売可能な状態の10個が倉庫にあって3個が注文用に確保されていれば、期末倉庫残高と新規注文に使える数量は異なり得る。これは概念例で、両レポートから常に差分計算で復元できるという意味ではない。

開発環境の既存接続で日本Marketplaceに対し、日次・国別、1日分ずつレポート生成とダウンロードを実行した。認証情報、レポートID、本文、個別のSKUと数量は記録していない。

| 指定期間 | 戻った`Date` | 行数 | 歴史上の社内SKUと一致した`MSKU`行 / ASIN行 |
| --- | --- | ---: | ---: |
| 2026-09-29 00:00〜23:59:59 UTC | 09/29、09/30 | 859 | 0 / 441 |
| 2026-09-29 00:00〜23:59:59 日本時間 | 09/29のみ | 430 | 0 / 221 |
| 2026-09-16 00:00〜23:59:59 日本時間 | 09/16のみ | 448 | 0 / 228 |

どちらの日本時間指定でも必要な列があり、観測行の`Disposition`は`SELLABLE`だった。行数はFNSKU・MSKU・ASIN等の組合せによるレポート行数であり、社内SKU数ではない。表の照合件数は全履歴に存在するSKU/ASINとの単純一致で、対象日の対応確定数ではない。`MSKU`と社内SKUコードは一致せず、ASINを対象日の履歴に照合する必要がある。UTC指定では日本の2日付が混在したため、実装では日本時間の境界を指定した上で、返却された`Date`も検証・絞り込みする。

この実アカウントでは**少なくとも調査時点の28日前（2026-09-16）**の日次期末残高を取得できた。それ以前の最古取得日、まとめて要求できる最大期間、欠損率は未測定。[Ledger Detailed View](https://developer-docs.amazon/sp-api/docs/report-type-values-fba)には移動履歴を18か月閲覧できる旨があるが、**Summary Viewの期末残高にも同じ保持期間があるとは扱わない**。生成済み文書のダウンロード保持期間も、元データを過去へ遡れる期間とは別である（[Amazonの文書保持方針](https://developer-docs.amazon/sp-api/changelog/report-document-retention-policy-is-changing-to-a-maximum-of-30-days)）。

## 実装案と費用

追加チケット候補: **SPAPI-08 FBA Ledger日次期末残高の別指標表示**。

- 最初の対象期間は実アカウントで確認した2026-09-16以降から実行日前日までとし、各日について生成可否と`Date`を確認する。9月16日より古い日は、先に実データのある日で遡及限界を検証してから拡張する。欠損日は「データなし」と表示し、0を補完しない。
- `GET_LEDGER_SUMMARY_VIEW_DATA`を`DAILY`・`COUNTRY`で取得し、日本時間で区切る。`Date`、ASIN、MSKU、FNSKU、`Disposition`、`Location`、`Ending Warehouse Balance`、取得日時、レポート種別を別の履歴テーブルに保存する。同一日・同一識別子の再取込は冪等にする。
- レポートの`SELLABLE`とその他の区分を区別し、倉庫間移動中在庫を期末倉庫残高に暗黙に加えない。画面では「FBA倉庫期末残高」として現行の「FBA出荷可能数」から分離し、Amazon区分やモール全体・総数に自動合算しない。
- 対象日時点の`survey_skus.child_asin`などで照合し、ASIN未設定・重複・対応変更を要確認として残す。レポートの行不在、数量0、対応不明を別状態で保持する。
- 受入条件: 9月16日・29日の`Date`と代表ASINの期末残高が取得文書に一致すること、日本時間以外の余分な日を混ぜないこと、照合できない行を見落とさないこと、再実行で二重計上しないこと、既存の出荷可能数・全倉庫CSV・総数が変わらないこと。期間上限と日次更新の遅延も実アカウントで確認して運用値を決める。

概算は既存の認証・レポート取得基盤とLaravel Schedulerを再利用する前提で**実装・テスト4〜7人日**。対象期間の遡及限界調査、ASIN曖昧対応の手作業、Amazon側のレポート待ち時間は別途。現行の自社発送数・FBA出荷可能数の未取得日を同じ指標で埋める実装は対象にしない。
