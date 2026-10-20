<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualSection from "@/components/manual/ManualSection.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTableOfContents from "@/components/manual/ManualTableOfContents.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

const sections = [
    { id: "introduction", title: "はじめに" },
    { id: "basic-operation-boss", title: "取込ファイルの準備①：BOSS" },
    { id: "basic-operation-ec", title: "取込ファイルの準備②：ECストック" },
    { id: "basic-operation-free", title: "取込ファイルの準備③：フリー在庫" },
    { id: "data-import", title: "データ取込" },
    { id: "basic-operation-amazon", title: "Amazon在庫の取得" },
    { id: "survey-history", title: "在庫調査履歴" },
    { id: "survey-result", title: "在庫調査結果" },
    { id: "inventory-trend", title: "在庫推移" },
    { id: "import-settings", title: "品番グループ" },
    { id: "file-management", title: "ファイル管理" },
    { id: "api-settings", title: "API連携設定" },
    { id: "user-management", title: "ユーザー管理" },
    { id: "notes", title: "注意事項" },
];

/** データ取込画面「Amazon 在庫」欄の状態（DataImportPage.vue の表示文言） */
const amazonImportStatuses = [
    { term: "受付", meaning: "取得の依頼を受け付け、開始を待っています。" },
    { term: "処理中", meaning: "Amazonから在庫レポートを取得しています。" },
    { term: "成功", meaning: "取得が終わり、その日の在庫調査に反映しました。" },
    { term: "失敗", meaning: "取得できませんでした。結果の欄に理由が表示されます。" },
];

/** 在庫調査結果画面「Amazon 在庫」の表示（SurveyResultPage.vue の表示文言） */
const amazonSurveyStatuses = [
    { term: "取込済み", meaning: "その日のAmazon在庫を反映済みです。" },
    { term: "取込待ち", meaning: "その日のAmazon在庫がまだ取得できていません。取得に成功すると自動で反映されます。在庫調査結果画面は30秒ごとに自動更新します。" },
    {
        term: "一部未取得",
        meaning: "取得は成功しましたが、Amazonの在庫レポートに含まれていないSKUがあります。そのSKUのAmazon在庫は「取込待ち」と表示され、在庫総数は未確定になります。",
    },
];

/** 在庫調査履歴のカレンダーの印（SurveyCalendar.vue・SurveyHistoryPage.vue の凡例） */
const calendarMarks = [
    { term: "右上の丸", meaning: "Amazon在庫の反映状況です。緑は「Amazon 取込済み」、黄色は「Amazon 未確定」（取込待ち・一部未取得）を表します。" },
    { term: "全ゼロ n", meaning: "全倉庫ゼロのSKU数です。前の調査日より増えた日は、色付きで「▲」が付きます。" },
    { term: "3つの四角", meaning: "取込ファイル数（3種類中）です。塗りつぶしの数が、保存期限内のファイルの種類の数です。" },
    { term: "調査なし", meaning: "調査がない日です（点線の枠）。" },
];

/** 在庫調査結果の在庫表の表示（StockCell.vue・SurveyResultPage.vue の表示） */
const stockDisplays = [
    { term: "数字", meaning: "在庫数です。Amazon・BOSSは、5以下のときに色を変えて表示します。" },
    { term: "0（色付き）", meaning: "取込済みで、在庫が0と確定しています。" },
    { term: "—", meaning: "取込待ちです。その種類のファイルを、その日にまだ取り込んでいません。在庫0ではありません。" },
    { term: "取込待ち（札）", meaning: "Amazon在庫がまだ反映されていないか、Amazonの在庫レポートに含まれていなかったSKUです。" },
    { term: "対象外", meaning: "ASINが登録されていないため、Amazon在庫を取得しないSKUです。" },
    { term: "総数", meaning: "全倉庫の合計です。どれかの倉庫が未確定のときは「—」になり、0としては数えません。" },
    { term: "色付きの行", meaning: "全倉庫ゼロ（総数が0と確定）のSKUです。行の左端にも印が付きます。" },
    { term: "倉庫別の構成", meaning: "倉庫ごとの在庫の割合を色の帯で示します。総数が未確定のときは「取込後に表示」「Amazon 取込後に表示」と表示されます。" },
];

/** 在庫推移の在庫区分（InventoryTrendPage.vue の選択肢と constants/warehouses.ts の倉庫） */
const inventoryScopes = [
    { term: "モール全体", meaning: "Amazon（自社発送・FBA）とBOSS（自社倉庫・RFC倉庫）の合計です。最初はこの区分で表示します。" },
    { term: "Amazon", meaning: "Amazonの自社発送とFBAの合計です。" },
    { term: "BOSS", meaning: "BOSSの自社倉庫とRFC倉庫の合計です。" },
    { term: "フリー", meaning: "フリー在庫です。" },
    { term: "ストック", meaning: "ECストックです。" },
    { term: "総数", meaning: "Amazon・BOSS・フリー在庫・ECストックの合計です。" },
];

/** 日次在庫CSVの列（InventoryTrendCsvExporter::HEADERS） */
const trendCsvColumns = [
    { term: "date", meaning: "日付" },
    { term: "productCode", meaning: "品番" },
    { term: "brand / category", meaning: "ブランド／カテゴリ" },
    { term: "sku / size", meaning: "SKU／サイズ" },
    { term: "amazonOwn / amazonFba", meaning: "Amazonの自社発送／FBAの在庫数" },
    { term: "bossOwn / bossRfc", meaning: "BOSSの自社倉庫／RFC倉庫の在庫数" },
    { term: "freeStock", meaning: "フリー在庫の在庫数" },
    { term: "ecStock", meaning: "ECストックの在庫数" },
];

/** サイドメニュー下の連携ステータス（IntegrationStatus.vue の表示） */
const integrationStatuses = [
    { term: "Amazon SP-API", meaning: "Amazon在庫の最新の取得結果です。成功は緑で在庫の基準時刻、失敗は赤、取得中・未取得は黄色で表示します。" },
    { term: "CrossWalker", meaning: "CrossWalkerへの最新の接続結果です。成功は緑、失敗は赤で、確認した時刻を表示します。記録がないときは黄色です。失敗の理由は、マウスを重ねると表示されます。" },
    { term: "保存ファイル", meaning: "保存期限まで7日以内の取込ファイルがあると、黄色で「n件 期限間近」と表示します。" },
];

/** 権限ごとにできること（docs/ユーザー管理実装/README.md の確定要件） */
const rolePermissions: { feature: string; viewer: boolean; editor: boolean; admin: boolean }[] = [
    { feature: "在庫調査履歴・在庫調査結果・在庫推移の閲覧", viewer: true, editor: true, admin: true },
    { feature: "Excel・CSVの出力、取込ファイルのダウンロード", viewer: true, editor: true, admin: true },
    { feature: "操作マニュアルの閲覧", viewer: true, editor: true, admin: true },
    { feature: "データ取込、保存ファイルでの再照合", viewer: false, editor: true, admin: true },
    { feature: "品番グループの追加・編集", viewer: false, editor: true, admin: true },
    { feature: "連携ステータスの確認", viewer: false, editor: true, admin: true },
    { feature: "品番グループの削除", viewer: false, editor: false, admin: true },
    { feature: "Amazon在庫の手動取得", viewer: false, editor: false, admin: true },
    { feature: "調査結果のメモの編集、調査履歴・取込ファイルの削除", viewer: false, editor: false, admin: true },
    { feature: "ファイル管理・API連携設定・ユーザー管理", viewer: false, editor: false, admin: true },
];
</script>

<template>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">操作マニュアル</h1>
            <p class="mt-1 text-[12.5px] text-ink-2">システムの操作方法をご案内します。</p>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div class="lg:sticky lg:top-16">
                <ManualTableOfContents :items="sections" />
            </div>

            <div class="min-w-0 space-y-4">
                <ManualSection id="introduction" title="はじめに" description="このシステムでできることと、1日の作業の流れを説明します。" icon="waving_hand">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        ZeroStockView（在庫状況調査システム）は、品番グループに登録した品番のSKUごとに、Amazon（自社発送・FBA）、BOSS（自社倉庫・RFC倉庫）、フリー在庫、ECストックの在庫数をまとめて確認するシステムです。在庫数は1日1件の「在庫調査」として保存され、過去の日の在庫を見比べたり、在庫推移として確認したりできます。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">1日の作業の流れ</h3>
                        <ol class="mt-2 list-decimal space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>毎朝9:00に、Amazon在庫がSP-APIから自動で取得されます。操作は不要です。</li>
                            <li>
                                BOSSとターコイズから、取込に使う3種類のファイル（倉庫毎の在庫数レポート、KEEP一覧表、在庫一覧照会表）をダウンロードします。手順は「取込ファイルの準備①〜③」を参照してください。
                            </li>
                            <li>「データ取込」画面でファイルを添付して取り込みます。その日の在庫調査が作成され、同じ日に2回目以降に取り込んだときは同じ調査が更新されます。</li>
                            <li>「在庫調査履歴」から調査結果を開き、在庫ゼロのSKUなどを確認します。必要に応じてExcelに出力します。</li>
                        </ol>
                    </div>
                    <ManualCallout title="取込の順番" tone="tip">Amazon在庫の取得とファイルの取込は、どちらが先でもかまいません。同じ日の在庫調査にまとめて反映されます。</ManualCallout>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">権限ごとにできること</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            ユーザーには「閲覧者」「編集者」「特権管理者」のいずれかの権限が設定されています。使えない操作のボタンやメニューは表示されません。権限の変更は特権管理者に依頼してください。
                        </p>
                        <div class="mt-2 overflow-x-auto rounded-card border border-line">
                            <table class="w-full min-w-120 text-left text-[12.5px]" data-role-permissions>
                                <thead class="border-b border-line bg-surface-2 text-[11px] text-muted">
                                    <tr>
                                        <th class="px-3 py-2 font-semibold">できること</th>
                                        <th class="w-20 px-3 py-2 text-center font-semibold">閲覧者</th>
                                        <th class="w-20 px-3 py-2 text-center font-semibold">編集者</th>
                                        <th class="w-24 px-3 py-2 text-center font-semibold">特権管理者</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line-2 text-ink-2">
                                    <tr v-for="row in rolePermissions" :key="row.feature">
                                        <td class="px-3 py-1.5">{{ row.feature }}</td>
                                        <td v-for="allowed in [row.viewer, row.editor, row.admin]" :key="String(allowed) + row.feature" class="px-3 py-1.5 text-center">
                                            <span v-if="allowed" class="font-semibold text-ink">○</span>
                                            <span v-else class="text-faint">－</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <ManualCallout title="品番は先にCrossWalkerへ登録してください" tone="warning"
                        >取込対象の品番は、商品識別子管理システム「CrossWalker」に登録されている必要があります。登録されていない品番は品番グループに追加できず、取込もできません。新しい品番を調べるときは、先にCrossWalkerへ登録してください。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="basic-operation-boss" title="取込ファイルの準備①：BOSS" description="BOSSの取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <ManualStep :number="1" title="在庫・商品一覧画面で商品一覧を表示します。">
                        <p>
                            BOSSにログインして、在庫・商品一覧画面を開き、検索条件を変更しないで検索ボタンを押下します。<br />
                            ログイン済みの場合は下記URLからアクセス可能です。<br />
                            <a href="https://boss-oms.jp/BOSSWeb/ItemInventoryList" target="_blank" class="font-mono text-xs text-ink underline underline-offset-2 hover:text-ink-2"
                                >https://boss-oms.jp/BOSSWeb/ItemInventoryList</a
                            >
                        </p>
                        <ManualScreenshot src="/img/manual/02-1.png" caption="在庫・商品一覧画面を開く" />
                    </ManualStep>
                    <ManualStep :number="2" title="倉庫毎の在庫数レポートをリクエスト">
                        <p>
                            「商品・在庫情報CSV出力」を押下して、出力テンプレートは「倉庫毎の在庫数」を選択します。<br />
                            倉庫選択をすべて選択した状態で、「実行」を押下します。
                        </p>
                        <ManualScreenshot src="/img/manual/02-2.png" caption="倉庫毎の在庫数レポートをリクエスト" />
                    </ManualStep>
                    <ManualStep :number="3" title="倉庫毎の在庫数レポートをダウンロード">
                        <p>処理が完了するとファイル名が表示されるので、押下してダウンロードしてください。</p>
                        <ManualScreenshot src="/img/manual/02-3.png" caption="倉庫毎の在庫数レポートをダウンロード" />
                    </ManualStep>
                </ManualSection>

                <ManualSection id="basic-operation-ec" title="取込ファイルの準備②：ECストック" description="ECストック在庫の取込に使用するデータは下記の手順で取得します。" icon="touch_app">
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
                        <ManualScreenshot src="/img/manual/03-3.png" caption="KEEP一覧表をダウンロード" />
                    </ManualStep>
                </ManualSection>

                <ManualSection id="basic-operation-free" title="取込ファイルの準備③：フリー在庫" description="フリー在庫の取込に使用するデータは下記の手順で取得します。" icon="touch_app">
                    <ManualStep :number="1" title="ターコイズで在庫一覧照会画面を開きます。">
                        <p>ターコイズにログインして、「在庫管理」＞「在庫一覧照会」を押下します。</p>
                        <ManualScreenshot src="/img/manual/04-1.png" caption="在庫一覧照会画面を開く" />
                    </ManualStep>
                    <ManualStep :number="2" title="01番倉庫の在庫一覧表をリクエスト">
                        <p>倉庫に「01」を指定して、ゼロ在庫出力のチェックを外した状態で検索開始ボタンを押下します。</p>
                        <ManualScreenshot src="/img/manual/04-2.png" caption="01番倉庫の在庫一覧表をリクエスト" />
                        <ManualCallout title="注意" tone="warning"
                            >ゼロ在庫出力のチェックを外さないと、検索結果の表示にかなりの時間がかかります。<br />チェックを外しても検索結果の表示には時間がかかりますので、しばらくお待ちください。</ManualCallout
                        >
                    </ManualStep>
                    <ManualStep :number="3" title="在庫一覧照会表をダウンロード">
                        <p>検索結果が表示されたら、CSV出力ボタンを押下して、在庫一覧照会表をダウンロードしてください。</p>
                        <ManualScreenshot src="/img/manual/04-3.png" caption="在庫一覧照会表をダウンロード" />
                    </ManualStep>
                </ManualSection>

                <ManualSection id="data-import" title="データ取込" description="準備したファイルを取り込んで、その日の在庫調査を作成・更新します。" icon="upload">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        「データ取込」画面は、編集者と特権管理者が使えます。取込の対象は、すべての品番グループに登録した品番です（同じ品番が複数のグループにあっても、1回だけ取り込みます）。Amazon在庫はファイルでは取り込みません。「Amazon在庫の取得」を参照してください。
                    </p>
                    <ManualStep :number="1" title="データ取込画面を開きます。">
                        <p>メニューの「データ取込」を押下します。</p>
                        <p>品番グループが1件もないときは「品番グループがありません。」と表示されます。先に「品番グループ」画面で対象の品番を登録してください。</p>
                    </ManualStep>
                    <ManualStep :number="2" title="ファイルを添付します。">
                        <p> 「取込ファイルの準備①〜③」でダウンロードしたCSVを、「ここにCSVをドロップ」の枠にドラッグ＆ドロップします。「ファイルを選択」「フォルダを選択」から選ぶこともできます。 </p>
                        <ul class="list-disc space-y-0.5 pl-5">
                            <li>1回に添付できるのは1〜3件です。3種類がそろっていなくても取り込めます。</li>
                            <li>ファイルの種類（BOSS・ECストック・フリー在庫）は、CSVの1行目の見出しから自動で判定します。ファイル名を変える必要はありません。</li>
                            <li>間違えて添付したファイルは、ファイル名の右の「×」で外せます。</li>
                        </ul>
                    </ManualStep>
                    <ManualStep :number="3" title="取込を実行します。">
                        <p>「取込・調査を実行」を押下します。完了すると「取込・調査が完了しました」と表示され、在庫調査結果画面が開きます。</p>
                        <ManualCallout title="取込に失敗したとき" tone="warning">
                            「取込に失敗しました」と、原因が表示されます。このとき、在庫調査は作成も更新もされません。原因を直してから、もう一度実行してください。
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                <li>「〜の種類を内容から判定できません。」：BOSS・ターコイズから出力したCSVを、手を加えずに添付しているか確認してください。</li>
                                <li>「〜のファイルが複数あります。」：同じ種類のファイルを2件以上添付しています。1件だけにしてください。</li>
                                <li>「〜行目の数量が…」：ファイルの数量の列に、数値でない値やマイナスの値があります。出力し直してください。</li>
                            </ul>
                        </ManualCallout>
                    </ManualStep>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">同じ日に追加で取り込むとき</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            在庫調査は1日1件です。取込を実行した日の調査がすでにあるときは、新しい調査は作られず、その調査が更新されます（初回取込日時は変わりません）。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>添付した種類は、新しいファイルの内容に置き換わります。</li>
                            <li>
                                添付しなかった種類は、その日に前回取り込んだファイルをそのまま使います。たとえば、午前にBOSSだけを取り込み、午後にECストックとフリー在庫を取り込むと、3種類がそろった調査になります。
                            </li>
                            <li>その日に一度も取り込んでいない種類は「取込待ち」になり、在庫表では「—」と表示されます。取込待ちは在庫0ではなく、まだ数がわからない状態です。</li>
                        </ul>
                    </div>
                </ManualSection>

                <ManualSection
                    id="basic-operation-amazon"
                    title="Amazon在庫の取得"
                    description="Amazon在庫（自社発送・FBA）は、AmazonのSP-APIから自動で取得します。ファイルの準備は不要です。"
                    icon="cloud_download"
                >
                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">毎朝9:00の自動取得</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            毎朝9:00に、品番グループに登録した品番のAmazon在庫を自動で取得します。Amazon側で在庫レポートを作成するため、完了までに時間がかかることがあります。取得が成功すると、その日の在庫調査に反映されます。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>その日の在庫調査がすでにあるときは、その調査に反映します。</li>
                            <li>
                                その日の在庫調査がまだないときは、Amazon在庫だけが入った調査を作成します。BOSS・ECストック・フリー在庫は取込待ちになり、あとでデータ取込を行うと同じ調査に追加されます。
                            </li>
                            <li>取得より先にデータ取込を行ったときは、調査のAmazon在庫は取込待ちになり、取得が成功した時点で反映されます。</li>
                            <li>ASINが登録されていないSKUは、Amazon在庫の取得対象外です。在庫表では「対象外」と表示されます。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">取得の状態を確認する</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            「データ取込」画面の「Amazon
                            在庫」欄で確認します。「最新の実行」に状態と、定期実行（毎朝の自動取得）か手動実行かが表示されます。「在庫基準日時」は、Amazon在庫がいつ時点の数かを示します。下の一覧には、直近5回の取得の開始日時・起動（定期／手動）・結果が表示されます。
                        </p>
                        <ManualTermTable class="mt-2" :rows="amazonImportStatuses" term-label="状態" data-amazon-import-statuses />
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">手動で取得する（特権管理者のみ）</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            自動取得が失敗したときや、9:00以降に品番グループへ品番を追加したときは、「Amazon 在庫」欄の「Amazon
                            在庫を取得」を押下して取り直します。完了するまで、画面は自動で更新されます。受付・処理中の取得があるあいだは押せません。同じ日に何回取得しても、調査にはその日に成功した最新の在庫が反映されます。
                        </p>
                    </div>
                    <ManualCallout title="取り直しは同じ日のうちに行ってください" tone="warning"
                        >取得した在庫は、取得した日の在庫調査にだけ反映されます。翌日に取得しても、前の日の調査は取込待ちのまま変わりません。</ManualCallout
                    >

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">在庫調査結果での表示</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">在庫調査結果画面の上部の「Amazon 在庫」に、その調査へのAmazon在庫の反映状況が表示されます。</p>
                        <ManualTermTable class="mt-2" :rows="amazonSurveyStatuses" data-amazon-survey-statuses />
                    </div>
                </ManualSection>

                <ManualSection id="survey-history" title="在庫調査履歴" description="これまでの在庫調査を、日付ごとに確認します。" icon="history">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「在庫調査履歴」を押下します。すべての権限で使えます。在庫調査は1日1件で、削除するまで保持されます。右上の「カレンダー」「リスト」で表示を切り替えます。「最新の在庫を再読込」を押下すると、履歴を最新の状態に更新します。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">カレンダーで見る</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            「＜」「＞」で月を移動します。月の横の「調査 n 日 ／ 経過 n
                            日」は、その月に調査がある日数と、今日までの日数です。調査のある日のマスには、次の印が表示されます。印の意味は、カレンダーの下の凡例でも確認できます。
                        </p>
                        <ManualTermTable class="mt-2" :rows="calendarMarks" term-label="印" data-calendar-marks />
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">日を選んで詳細を見る</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">調査のある日を押下すると、右側に「○月○日（○）の調査」の詳細が表示されます。最初は最新の調査が選ばれています。</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>全倉庫ゼロ・一部の倉庫でゼロのSKU数（前日比）と、品番数・SKU数</li>
                            <li> 初回取込日時（その日に最初に取り込んだ日時）、結果作成日時、結果更新日時（追加取込やAmazon在庫の反映で更新された日時）、Amazon 在庫の反映状況 </li>
                            <li>
                                取込ファイル：BOSS・ECストック・フリー在庫ごとに、ファイル名か「ファイルなし」「保存期限切れ」が表示されます。右端のボタンで1件ずつ、「一括ダウンロード」で保存中のファイルをまとめて（ZIP形式）ダウンロードできます。
                            </li>
                            <li>「結果を開く」で在庫調査結果画面を開き、「Excel出力」でその日の調査結果をExcelファイルでダウンロードします。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">リストで見る</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            調査を新しい順に10件ずつ表示します。「調査期間」に開始日と終了日を指定すると、その期間の調査だけを表示します（「クリア」で解除）。一覧には、初回取込日時・品番数・SKU数・取込ファイル数・Amazon
                            在庫の反映状況・全倉庫ゼロのSKU数が表示されます。全倉庫ゼロが前の調査日より増えた日は、数字が色付きで表示されます。右端の「結果を開く」と、表のアイコンの「Excel出力」は、カレンダーの詳細欄と同じです。
                        </p>
                    </div>

                    <ManualCallout title="調査の削除（特権管理者のみ）" tone="warning"
                        >詳細欄の「この日の調査を削除」か、リストのごみ箱のアイコンで削除します。削除すると、その日の調査結果に加えて、メモと取込ファイルも削除され、元に戻せません。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="survey-result" title="在庫調査結果" description="1日分の在庫調査を、品番・SKUごとに確認します。" icon="fact_check">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        在庫調査履歴の「結果を開く」を押下するか、データ取込が完了すると開きます。すべての権限で使えます。「調査履歴に戻る」で在庫調査履歴に戻ります。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">画面上部のまとめ</h3>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>
                                見出しの下に、初回取込日時・結果作成日時・結果更新日時と、取込ファイル（保存期限内のファイルの種類の数）が表示されます。「一括ダウンロード」で、保存中の取込ファイルをまとめてダウンロードできます。
                            </li>
                            <li>「対象」は調査したSKU数と品番数、「全倉庫ゼロ」「一部の倉庫でゼロ」はそれぞれのSKU数です。「前回比」は、直前の調査との差です。</li>
                            <li>
                                「Amazon 在庫」は、Amazon在庫の反映状況です（意味は「Amazon在庫の取得」の表を参照）。下の「基準 hh:mm ・ 照合
                                n/n」は、Amazon在庫の基準時刻と、Amazon在庫を取得できたSKU数／ASINのあるSKU数です。
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">在庫表の見方</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            品番ごとに、SKUの在庫を倉庫別（Amazon・BOSS・フリー在庫・ECストック）に表示します。Amazonは自社発送とFBA、BOSSは自社倉庫とRFC倉庫の合計です。「全倉庫ゼロ」は総数が0と確定したSKU、「一部の倉庫でゼロ」は総数が1以上で、0の倉庫があるSKUです。
                        </p>
                        <ManualTermTable class="mt-2" :rows="stockDisplays" data-stock-displays />
                    </div>
                    <ManualCallout title="AmazonとBOSSの内訳を見る" tone="tip"
                        >キーボードのCtrlキーを押しながら、Amazon・BOSSの数字にマウスを重ねると、内訳（Amazonは自社／FBA、BOSSは自社／RFC）が表示されます。</ManualCallout
                    >

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">絞り込む</h3>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>品番グループ・ブランド・カテゴリを選ぶか、品番・SKU・ASINの一部を入力して絞り込みます。SKUやASINで絞り込むと、該当するSKUの行に印が付きます。</li>
                            <li>
                                「在庫ゼロ」「在庫あり」のボタン（Amazon・BOSS・フリー・ECストック）を押下すると、在庫の条件で絞り込みます。複数押下したときは、「結合」で「AND」（すべて満たす）か「OR」（どれかを満たす）を選びます。取込待ち（「—」）の倉庫は、在庫ゼロの条件に該当しません。
                            </li>
                            <li>該当したSKU数は「該当 n ／ n SKU」に表示されます。「条件をクリア」で、すべての条件を解除します。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">Excelに出力する</h3>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>「全件をExcel出力」：調査のすべてのSKUを出力します。品番グループを選んでいるときは、そのグループのSKUを出力します。</li>
                            <li>「絞込結果をExcel出力」：絞り込みで該当したSKUだけを出力します。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">メモを書く（特権管理者のみ）</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            品番の見出しの右にある「品番のメモを追加」と、SKUの行の「メモを追加」に入力します。入力欄から離れると自動で保存されます。メモは調査（日）ごとに保存され、同じ日の追加取込や再照合では残りますが、別の日の調査には引き継がれません。特権管理者以外は、メモを読むことだけができます。
                        </p>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">最新の在庫を再読込</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            「最新の在庫を再読込」を押下すると、この調査を最新の状態に読み込み直します。この画面は30秒ごとに自動で更新されますが、Amazon在庫が反映されたかをすぐ確かめたいときなどに使います。
                        </p>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">保存ファイルで再照合（編集者・特権管理者）</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            その日に取り込んで保存されているファイルを使い、現在の品番グループの品番で照合し直して、この調査を更新します。ファイルを添付し直す必要はありません。次のようなときに使います。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>品番グループに品番を追加・削除したあと、取込済みの日の結果にも反映したいとき</li>
                            <li>CrossWalkerでSKUの情報（ASIN、ターコイズの品番・カラー・サイズ）を直したあと、結果に反映したいとき</li>
                        </ul>
                    </div>
                    <ManualStep :number="1" title="「保存ファイルで再照合」を押下します。">
                        <p>「差異照合メニュー」が開きます。</p>
                    </ManualStep>
                    <ManualStep :number="2" title="表示する品番グループを選んで、再照合を実行します。">
                        <p>
                            「再照合後に表示する品番グループ」を選び、「再照合を実行」を押下します。照合の対象は、選んだグループに関係なく、すべての品番グループの品番です。完了すると「調査結果を再照合しました」と表示され、選んだグループの結果が表示されます。
                        </p>
                        <p> 初回取込日時は変わりません。保存期限（30日）を過ぎたファイルの種類は、取込待ちになります。Amazon在庫は、その日に取得した最新の在庫で反映し直します。 </p>
                    </ManualStep>
                    <ManualCallout title="再照合できない調査" tone="warning">
                        次の調査では「保存ファイルで再照合」を押せず、画面上部に理由が表示されます。
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            <li>Amazon在庫をファイルで取り込んでいた旧方式（5ファイル）の調査。保存されているAmazon在庫を守るため、再照合できません。</li>
                            <li>保存期限が過ぎるなどして、再照合に使える取込ファイルが1件もない調査。</li>
                        </ul>
                    </ManualCallout>
                </ManualSection>

                <ManualSection id="inventory-trend" title="在庫推移" description="品番ごとに、日々の在庫数の移り変わりをグラフで確認します。" icon="show_chart">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「在庫推移」を押下します。すべての権限で使えます。各日の在庫調査（その日の最新の状態）を集計して表示します。調査がない日でも、Amazon在庫を取得した日はAmazon在庫だけを表示します。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">表示する内容を選ぶ</h3>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>
                                対象品番：「変更」を押下すると「対象品番を選択」が開きます。ブランドや検索（品番・ブランド・カテゴリ）で候補を絞り込み、品番を選んで「この品番を表示」を押下します。候補は、これまでの在庫調査かAmazon在庫の取得に含まれた品番です。
                            </li>
                            <li>在庫区分：どの倉庫の在庫を表示するかを選びます（下の表を参照）。</li>
                            <li>表示期間：「2週間」「1か月」「3か月」は、データがある最新の日からさかのぼった期間です。最初は「1か月」で表示します。</li>
                            <li>期間：開始日と終了日を直接指定します（最大365日間）。指定すると、表示期間は「期間指定」になります。</li>
                        </ul>
                        <ManualTermTable class="mt-2" :rows="inventoryScopes" term-label="在庫区分" data-inventory-scopes />
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">品番全体のグラフ</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            グラフの右上の「品番全体」「SKU別」で表示を切り替えます。「品番全体」では、品番のすべてのSKUの在庫を日ごとに合計し、在庫区分に含まれる倉庫ごとに色分けして積み上げます。色と倉庫の対応は、グラフの上の凡例で確認できます。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>未確定：取込待ち・未取得の倉庫がある日は、確定した倉庫だけを積み上げ、上に「未確定」の印（点線の枠）を付けます。合計は出しません。</li>
                            <li>欠品：日付の下の黄色の四角と数字は、その日に在庫区分の在庫が0だったSKUの数です。未確定のSKUがある日は数えません。</li>
                            <li>
                                棒にマウスを重ねると、倉庫ごとの在庫数、総在庫数、欠品SKU数が表示されます。欠品SKUがある日は、そのSKUと期間中に欠品した日数（期間累計）、全SKUの期間累計欠品日数も表示されます。
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">SKU別のグラフ</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            「SKU別」では、SKUごとに在庫区分の在庫数の折れ線を表示します。右上の札は「欠品
                            n日」（期間中に在庫0の日がある）、「在庫あり」、「データなし」のいずれかです。その下に、最新の在庫数と期間平均が表示されます。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>点にマウスを重ねると、その日の日付と在庫数が表示されます。</li>
                            <li>点線の部分は、データのない日をまたいでつないだところです。</li>
                            <li>最後の点が黄色のときは、最新の在庫数が0です。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">SKU別サマリー</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            グラフの下に、SKUごとの数字を表にまとめます。見出しの横に、表示している期間と日数が表示されます。数字は在庫区分の在庫数で、データのない日は計算に含めません（値がないときは「—」）。
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>推移：在庫数の小さな折れ線です。</li>
                            <li>最新在庫：期間中、最後にデータがある日の在庫数です。</li>
                            <li>期間平均・期間最小・期間最大：期間中の在庫数の平均・最小・最大です。</li>
                            <li>欠品日数：期間中に在庫が0だった日数です。1日以上あると黄色で表示します。</li>
                        </ul>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">グラフの右上の「再読込」を押下すると、最新のデータで表示し直します。</p>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">日次在庫をCSV出力</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            右上の「日次在庫をCSV出力」を押下し、「選択品番の日次CSV」（表示中の品番）か「全品番の日次CSV」を選びます。期間で指定した開始日から終了日までについて、1日・1SKUを1行として出力します。在庫区分に関係なく、すべての倉庫の在庫数を出力します。調査がない日でもAmazon在庫を取得した日は出力し、その日のAmazon以外の列は空欄になります。
                        </p>
                        <ManualTermTable class="mt-2" :rows="trendCsvColumns" term-label="列" meaning-label="内容" data-trend-csv-columns />
                    </div>
                    <ManualCallout title="CSVの空欄と0の違い" tone="tip"
                        >空欄は、取込待ち・未取得で在庫数がわからないことを表します。0は、在庫が0と確定していることを表します。集計するときは、空欄を0として扱わないでください。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="import-settings" title="品番グループ" description="取込の対象にする品番を、用途ごとのグループにまとめます。" icon="category">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「品番グループ」を押下します。編集者と特権管理者が使えます。すべてのグループに登録した品番が取込の対象になり、同じ品番が複数のグループにあっても1回だけ取り込みます。グループは、在庫調査結果で品番を絞り込むときにも使います。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">一覧の見方</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            見出しの下に、グループ数、取込対象の品番数（重複を除く）、取込対象のSKU数が表示されます。一覧には、グループ名、対象品番数（1グループ50件まで）、対象品番（先頭の5件）、最終同期（品番の情報を最後にCrossWalkerから取り込んだ日時）が表示されます。
                        </p>
                    </div>

                    <ManualStep :number="1" title="グループを作成・編集します。">
                        <p>「新しいグループを作成」を押下するか、一覧のグループ名か「編集」を押下します。「グループ名」を入力します。</p>
                    </ManualStep>
                    <ManualStep :number="2" title="品番を追加します。">
                        <p>
                            「品番を追加」の検索欄に、品番・SKU・ASIN・ターコイズの情報を入力して「検索」を押下します。CrossWalkerに登録されている品番が候補に表示されるので、「追加」を押下します。追加した品番は「追加済み」になり、「取込対象品番」に並びます。
                        </p>
                    </ManualStep>
                    <ManualStep :number="3" title="並び順を整えて保存します。">
                        <p>
                            「取込対象品番」の行をドラッグすると、並び順を変えられます。この順番は、在庫調査結果でグループを選んだときの表示順になります。対象から外すときは、行の右端のごみ箱のアイコンを押下します。
                        </p>
                        <p> 最後に「このグループを保存」を押下します。保存していない変更があるあいだは「未保存の変更があります」と表示され、「変更を取り消す」で元に戻せます。 </p>
                    </ManualStep>

                    <ManualCallout title="グループの削除（特権管理者のみ）" tone="warning"
                        >一覧のごみ箱のアイコンで削除します。削除したグループの品番は、ほかのグループに登録されていなければ、次の取込から対象外になります。過去の在庫調査に保存されたグループの情報は残ります。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="file-management" title="ファイル管理" description="保存されている取込ファイルを、調査ごとに確認・ダウンロード・削除します。" icon="folder_open">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「ファイル管理」を押下します。特権管理者だけが使えます。取り込んだファイルは、アップロードから30日間保存され、過ぎると自動で削除されます。ファイルが削除されても、調査結果とメモは残ります。
                    </p>
                    <ul class="list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                        <li>見出しの下に、保存中のファイル数、合計サイズ、保存期限まで7日以内のファイル数が表示されます。</li>
                        <li>
                            調査（初回取込日時）ごとに、ファイルの種類・ファイル名・アップロード日時・サイズ・保存期限までの残り日数が表示されます。残りが7日以内のファイルは、黄色で表示されます。
                        </li>
                        <li>「調査結果を見る」で、その日の在庫調査結果を開きます。</li>
                        <li>ファイルの右端のボタンで1件ずつダウンロード・削除できます。「一括ダウンロード」「一括削除」は、その調査のファイルをまとめて扱います。</li>
                    </ul>
                    <ManualCallout title="ファイルを削除すると" tone="warning"
                        >削除したファイルは元に戻せません。その日の調査で、ダウンロードと「保存ファイルで再照合」ができなくなります。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="api-settings" title="API連携設定" description="外部システムが日次在庫データを取得するためのAPIを設定します。" icon="api">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「API連携設定」を押下します。特権管理者だけが使えます。外部システムは、このAPIで、在庫推移の日次在庫CSVと同じ項目をJSON形式で取得します。設定を変えたら、右上の「保存」を押下してください。
                    </p>
                    <ul class="list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                        <li> API有効状態：「外部APIを有効にする」で、APIを使えるかどうかを切り替えます。有効にするには、APIキーの発行と、許可IPアドレスまたはCIDRの登録（1件以上）が必要です。 </li>
                        <li>APIキー：「発行」（2回目以降は「再発行」）を押下すると、新しいキーが表示されます。再発行すると、それまでのキーは使えなくなります。</li>
                        <li>
                            許可IPアドレス / CIDR：APIに接続できる接続元を登録します。「IP /
                            CIDR」とメモを入力して「追加」を押下し、「保存」で反映します。接続元のIPアドレスが登録に一致し、APIキーが正しいときだけ接続できます。
                        </li>
                        <li>接続情報：外部システムの担当者に伝える、パスや認証の方法、回数制限です。「APIリファレンス」で、指定できるパラメータを確認できます。</li>
                    </ul>
                    <ManualCallout title="APIキーは発行直後に1回だけ表示されます" tone="warning"
                        >画面を離れると、キーの値は二度と表示されません。表示されたらすぐに安全な場所へ控え、外部システムの担当者に伝えてください。控え忘れたときは、再発行して新しいキーを伝え直します。</ManualCallout
                    >
                </ManualSection>

                <ManualSection id="user-management" title="ユーザー管理" description="ログインできるユーザーと、その権限を管理します。" icon="manage_accounts">
                    <p class="text-[13px] leading-relaxed text-ink-2">
                        メニューの「ユーザー管理」を押下します。特権管理者だけが使えます。権限ごとにできることは、「はじめに」の表を参照してください。
                    </p>
                    <ul class="list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                        <li>追加：「ユーザーを追加」を押下し、ログインID・権限・初期パスワードを入力して「ユーザーを作成」を押下します。</li>
                        <li>編集：一覧の鉛筆のアイコンを押下します。ログインIDと権限を変えて「変更を保存」を押下します。</li>
                        <li> パスワードの再設定：編集の画面で「パスワードを再設定する」を押下し、新しいパスワードを入力します。パスワードは半角英数字記号のみで12文字以上です。 </li>
                        <li>削除：一覧のごみ箱のアイコンを押下し、「削除する」を押下します。削除したユーザーは、すぐにログアウトされます。</li>
                    </ul>
                    <ManualCallout title="権限の変更と削除の注意" tone="warning">
                        <ul class="list-disc space-y-0.5 pl-5">
                            <li>権限を変更すると、そのユーザーはすぐにログアウトされ、再ログインが必要になります。自分の権限を変更したときは、ログイン画面に戻ります。</li>
                            <li>特権管理者が1人だけのときは、その人を降格・削除できません。先に別の特権管理者を作成してください。</li>
                            <li>自分自身は削除できません。</li>
                        </ul>
                    </ManualCallout>
                    <p class="text-[13px] leading-relaxed text-ink-2"> 自分のパスワードは、権限に関係なく、画面右上のログインIDを押下して「パスワード変更」から変更できます。 </p>
                </ManualSection>

                <ManualSection id="notes" title="注意事項" description="操作時に間違えやすい点をまとめます。" icon="error">
                    <ManualCallout title="取込待ち（—）は在庫0ではありません" tone="warning"
                        >「—」や「取込待ち」は、まだ数がわからない状態です。在庫が0と確定したときは「0」と表示されます。取込待ちの倉庫があるSKUは、在庫総数が未確定になり、全倉庫ゼロ・一部の倉庫でゼロにも数えません。日次在庫CSVやExcelで集計するときも、空欄を0として扱わないでください。</ManualCallout
                    >
                    <ManualCallout title="取込ファイルの保存期限は30日です" tone="warning"
                        >アップロードから30日を過ぎたファイルは自動で削除され、ダウンロードと「保存ファイルで再照合」ができなくなります。調査結果・メモ・Excel出力は引き続き使えます。必要なファイルは、期限までにダウンロードしておいてください。</ManualCallout
                    >

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">調査の日付とAmazon在庫の反映</h3>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-[13px] leading-relaxed text-ink-2">
                            <li>在庫調査の日付は、取込を実行した日（日本時間）です。0時を過ぎてから取り込むと、翌日の調査になります。</li>
                            <li>
                                Amazon在庫は、取得した日の調査にだけ反映されます。毎朝9:00の自動取得が失敗したときは、その日のうちに特権管理者が取り直してください。翌日に取得しても、前の日の調査には反映されません。
                            </li>
                            <li>9:00以降に品番グループへ追加した品番は、次にAmazon在庫を取得するまで、Amazon在庫が「取込待ち」になります。</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">旧方式（5ファイル）の調査</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">
                            Amazon在庫をSP-APIで自動取得するようになる前は、Amazon在庫もファイル（在庫商品レポート・FBA在庫管理レポート）で取り込んでいました。この方式の調査は、Amazon在庫が取り込んだときの値のまま残り、在庫調査結果の「Amazon
                            在庫」に「旧ファイル」と表示されます。保存されているAmazon在庫を守るため、「保存ファイルで再照合」はできません。
                        </p>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">連携ステータス（編集者・特権管理者）</h3>
                        <p class="mt-1 text-[13px] leading-relaxed text-ink-2">サイドメニューの下に、外部システムとの連携の状態が表示されます。5分ごとに自動で更新されます。</p>
                        <ManualTermTable class="mt-2" :rows="integrationStatuses" term-label="項目" data-integration-statuses />
                    </div>
                </ManualSection>
            </div>
        </div>
    </div>
</template>
