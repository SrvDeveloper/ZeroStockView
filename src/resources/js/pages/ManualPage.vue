<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualSection from "@/components/manual/ManualSection.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTableOfContents from "@/components/manual/ManualTableOfContents.vue";

const sections = [
    { id: "introduction", title: "はじめに" },
    { id: "basic-operation", title: "事前準備" },
    { id: "notes", title: "注意事項" },
    { id: "faq", title: "よくある質問" },
];
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">操作マニュアル</h1>
            <p class="mt-1 text-sm text-slate-500">システムの操作方法をご案内します。</p>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div class="lg:sticky lg:top-6">
                <ManualTableOfContents :items="sections" />
            </div>

            <div class="min-w-0 space-y-6">
                <!-- 必要に応じて ManualSection を複製し、sections に同じ id とタイトルを追加してください。 -->
                <ManualSection id="introduction" title="はじめに" description="取込対象の品番を事前に登録してください。" icon="waving_hand">
                    <p class="text-sm leading-7 text-slate-600">照合を行う品番がまだ商品識別子管理システム「Crosswalker」に登録されていない場合は、事前に追加登録してください。</p>
                </ManualSection>

                <ManualSection id="basic-operation" title="事前準備①：Amazon用の取込データ準備" description="Amazonの取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <!-- ManualStep は必要な手順数だけ追加・削除できます。 -->
                    <ManualStep :number="1" title="「Amazon Seller Central」で出品レポート画面を開く">
                        <p>
                            左上の「≡」をクリック。「レポート」＞「出品レポート」をクリックします。ログイン済みの場合は下記URLからアクセス可能です。<br />
                            <a href="https://sellercentral.amazon.co.jp/listing/reports/ref=xx_invreport_dnav_xx" target="_blank" class="text-primary-600 hover:text-primary-500">
                                https://sellercentral.amazon.co.jp/listing/reports/ref=xx_invreport_dnav_xx
                            </a>
                        </p>
                        <ManualScreenshot src="/img/manual/01-1.png" caption="「レポート」の中の「出品レポート」をクリック" />
                    </ManualStep>
                    <div class="border-t border-slate-100"></div>
                    <ManualStep :number="2" title="「出品詳細レポート(カスタム)」をリクエストする">
                        <p>リクエストするレポートの種類で「出品詳細レポート(カスタム)」を選択して、レポートをリクエストをクリックします。</p>
                        <ManualScreenshot src="/img/manual/01-2.png" caption="「出品詳細レポート(カスタム)」をリクエスト" />
                    </ManualStep>
                    <div class="border-t border-slate-100"></div>
                    <ManualStep :number="3" title="「出品詳細レポート(カスタム)」をダウンロードする">
                        <p>リクエスト後、レポートが作成されるとダウンロードボタンが表示されますので、クリックしてダウンロードしてください。</p>
                        <ManualScreenshot src="/img/manual/01-3.png" caption="出品詳細レポートをダウンロード" />
                        <ManualCallout title="操作のヒント" tone="tip">ダウンロードしたファイルは "出品詳細レポート.txt" にリネームすると、後々使いやすくなります。</ManualCallout>
                    </ManualStep>
                    <div class="border-t border-slate-100"></div>
                    <ManualStep :number="4" title="「Amazon Seller Central」でFBA在庫管理レポート画面を開く">
                        <p>
                            左上の「≡」をクリック。「レポート」＞「Fulfillment」をクリックします。その後、FBA在庫管理という項目をクリックします。<br />ログイン済みの場合は下記URLからアクセス可能です。<br />
                            <a href="https://sellercentral.amazon.co.jp/reportcentral/FBA_MYI_UNSUPPRESSED_INVENTORY/1" target="_blank" class="text-primary-600 hover:text-primary-500">
                                https://sellercentral.amazon.co.jp/reportcentral/FBA_MYI_UNSUPPRESSED_INVENTORY/1
                            </a>
                        </p>
                        <ManualScreenshot src="/img/manual/01-4.png" caption="Amazon フルフィルメント レポート画面を開く" />
                        <ManualScreenshot src="/img/manual/01-5.png" caption="FBA在庫管理レポート画面を開く" />
                    </ManualStep>
                    <div class="border-t border-slate-100"></div>
                    <ManualStep :number="5" title="FBA在庫管理レポートをダウンロード">
                        <p>
                            「.csv形式でのダウンロードをリクエスト」をクリックしてレポートをリクエストします。しばらく待つとダウンロードボタンが表示されるので、クリックしてダウンロードしてください。
                        </p>
                        <ManualScreenshot src="/img/manual/01-6.png" caption="FBA在庫管理レポートをダウンロード" />
                        <ManualCallout title="操作のヒント" tone="tip">ダウンロードしたファイルは "FBA在庫管理レポート.csv" にリネームすると、後々使いやすくなります。</ManualCallout>
                    </ManualStep>
                </ManualSection>

                <ManualSection id="basic-operation" title="事前準備②：BOSS用の取込データ準備" description="BOSSの取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <ManualStep :number="1" title="在庫・商品一覧画面で商品一覧を表示します。">
                        <p>
                            BOSSにログインして、在庫・商品一覧画面を開き、検索条件を変更しないで検索ボタンを押下します。<br />
                            ログイン済みの場合は下記URLからアクセス可能です。<br />
                            <a href="https://boss-oms.jp/BOSSWeb/ItemInventoryList" target="_blank" class="text-primary-600 hover:text-primary-500">https://boss-oms.jp/BOSSWeb/ItemInventoryList</a>
                        </p>
                        <ManualScreenshot src="/img/manual/02-1.png" caption="在庫・商品一覧画面を開く" />
                    </ManualStep>
                    <ManualStep :number="2" title="倉庫毎の在庫数レポートをリクエスト">
                        <p>
                            「商品・在庫情報CSV出力」を押下して、出力テンプレートは「倉庫毎の在庫数」を選択します。<br />
                            倉庫選択をすべて選択した状態で、「実行」を押下します。
                        </p>
                        <ManualScreenshot src="/img/manual/02-2.png" caption="在庫・商品一覧画面を開く" />
                    </ManualStep>
                    <ManualStep :number="3" title="倉庫毎の在庫数レポートをダウンロード">
                        <p>処理が完了するとファイル名が表示されるので、押下してダウンロードしてください。</p>
                        <ManualScreenshot src="/img/manual/02-3.png" caption="倉庫毎の在庫数レポートをダウンロード" />
                        <ManualCallout title="操作のヒント" tone="tip">ダウンロードしたファイルは "倉庫毎の在庫数レポート.csv" にリネームすると、後々使いやすくなります。</ManualCallout>
                    </ManualStep>
                </ManualSection>

                <ManualSection id="basic-operation" title="事前準備③：ECストックの取込データ準備" description="ECストック在庫の取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <ManualStep :number="1" title="ターコイズでKEEP一覧表出力画面を開きます。">
                        <p>ターコイズにログインして、「在庫管理」＞「KEEP一覧表」を押下します。</p>
                        <ManualScreenshot src="/img/manual/03-1.png" caption="KEEP一覧表出力画面を開く" />
                    </ManualStep>
                    <ManualStep :number="2" title="ECストック倉庫のKEEP一覧表を出力">
                        <p>得意先に「59」を指定して、CSV出力ボタンを押下します。</p>
                        <ManualScreenshot src="/img/manual/03-2.png" caption="59番倉庫のKEEP一覧表をリクエスト" />
                    </ManualStep>
                    <ManualStep :number="3" title="KEEP一覧表をダウンロード">
                        <p>処理が完了するとCSV出力が表示されるので、出力を実行します。</p>
                        <ManualScreenshot src="/img/manual/03-3.png" caption="倉庫毎の在庫数レポートをダウンロード" />
                        <ManualCallout title="操作のヒント" tone="tip">ダウンロードしたファイルは "KEEP一覧表.csv" にリネームすると、後々使いやすくなります。</ManualCallout>
                    </ManualStep>
                </ManualSection>

                <ManualSection id="basic-operation" title="事前準備④：フリー在庫の取込データ準備" description="フリー在庫の取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <ManualStep :number="1" title="ターコイズで在庫一覧照会画面を開きます。">
                        <p>ターコイズにログインして、「在庫管理」＞「在庫一覧照会」を押下します。</p>
                        <ManualScreenshot src="/img/manual/04-1.png" caption="在庫一覧照会画面を開く" />
                    </ManualStep>
                    <ManualStep :number="2" title="ECストック倉庫のKEEP一覧表を出力">
                        <p>倉庫に「01」を指定して、ゼロ在庫出力のチェックを外した状態で検索開始ボタンを押下します。</p>
                        <ManualScreenshot src="/img/manual/04-2.png" caption="01番倉庫の在庫一覧表をリクエスト" />
                        <ManualCallout title="注意" tone="warning"
                            >ゼロ在庫出力のチェックを外さないと、検索結果の表示にかなりの時間がかかります。<br />チェックを外しても検索結果の表示には時間がかかりますので、しばらくお待ちください。</ManualCallout
                        >
                    </ManualStep>
                    <ManualStep :number="3" title="KEEP一覧表をダウンロード">
                        <p>検索結果が表示されたら、CSV出力ボタンを押下して、在庫一覧照会表をダウンロードしてください。</p>
                        <ManualScreenshot src="/img/manual/04-3.png" caption="倉庫毎の在庫数レポートをダウンロード" />
                        <ManualCallout title="操作のヒント" tone="tip">ダウンロードしたファイルは "在庫一覧照会表.csv" にリネームすると、後々使いやすくなります。</ManualCallout>
                    </ManualStep>
                </ManualSection>

                <ManualSection id="notes" title="注意事項" description="操作時の制約や間違えやすい点を記載します。" icon="error">
                    <ManualCallout title="注意" tone="warning">ここに、操作前に確認が必要な注意事項を記載してください。</ManualCallout>
                    <ManualCallout title="操作のヒント" tone="tip">ここに、作業を進めやすくするヒントを記載してください。</ManualCallout>
                </ManualSection>

                <ManualSection id="faq" title="よくある質問" description="問い合わせの多い内容を質問と回答の形式で記載します。" icon="help">
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        <details class="group p-4" open>
                            <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900 marker:hidden">質問を記載してください</summary>
                            <p class="mt-3 text-sm leading-6 text-slate-600">ここに回答を記載してください。</p>
                        </details>
                        <details class="group p-4">
                            <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900 marker:hidden">質問を追加してください</summary>
                            <p class="mt-3 text-sm leading-6 text-slate-600">ここに回答を記載してください。</p>
                        </details>
                    </div>
                </ManualSection>
            </div>
        </div>
    </div>
</template>
