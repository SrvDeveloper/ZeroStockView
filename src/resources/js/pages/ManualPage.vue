<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualSection from "@/components/manual/ManualSection.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTableOfContents from "@/components/manual/ManualTableOfContents.vue";

const sections = [
    { id: "introduction", title: "はじめに" },
    { id: "basic-operation-boss", title: "取込ファイルの準備①：BOSS" },
    { id: "basic-operation-ec", title: "取込ファイルの準備②：ECストック" },
    { id: "basic-operation-free", title: "取込ファイルの準備③：フリー在庫" },
    { id: "data-import", title: "データ取込" },
    { id: "basic-operation-amazon", title: "Amazon在庫の取得" },
    { id: "notes", title: "注意事項" },
];

/** データ取込画面「Amazon 在庫」欄の状態（DataImportPage.vue の表示文言） */
const amazonImportStatuses = [
    { label: "受付", meaning: "取得の依頼を受け付け、開始を待っています。" },
    { label: "処理中", meaning: "Amazonから在庫レポートを取得しています。" },
    { label: "成功", meaning: "取得が終わり、その日の在庫調査に反映しました。" },
    { label: "失敗", meaning: "取得できませんでした。結果の欄に理由が表示されます。" },
];

/** 在庫調査結果画面「Amazon 在庫」の表示（SurveyResultPage.vue の表示文言） */
const amazonSurveyStatuses = [
    { label: "取込済み", meaning: "その日のAmazon在庫を反映済みです。" },
    { label: "取込待ち", meaning: "その日のAmazon在庫がまだ取得できていません。取得に成功すると自動で反映されます。在庫調査結果画面は30秒ごとに自動更新します。" },
    {
        label: "一部未取得",
        meaning: "取得は成功しましたが、Amazonの在庫レポートに含まれていないSKUがあります。そのSKUのAmazon在庫は「—」と表示され、在庫総数は未確定になります。",
    },
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
                    <p class="text-[13px] leading-7 text-ink-2">
                        ZeroStockView（在庫状況調査システム）は、品番グループに登録した品番のSKUごとに、Amazon（自社発送・FBA）、BOSS（自社倉庫・RFC倉庫）、フリー在庫、ECストックの在庫数をまとめて確認するシステムです。在庫数は1日1件の「在庫調査」として保存され、過去の日の在庫を見比べたり、在庫推移として確認したりできます。
                    </p>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">1日の作業の流れ</h3>
                        <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-[13px] leading-7 text-ink-2">
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
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">
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
                    <p class="text-[13px] leading-7 text-ink-2">
                        「データ取込」画面は、編集者と特権管理者が使えます。取込の対象は、すべての品番グループに登録した品番です（同じ品番が複数のグループにあっても、1回だけ取り込みます）。Amazon在庫はファイルでは取り込みません。「Amazon在庫の取得」を参照してください。
                    </p>
                    <ManualStep :number="1" title="データ取込画面を開きます。">
                        <p>メニューの「データ取込」を押下します。</p>
                        <p>品番グループが1件もないときは「品番グループがありません。」と表示されます。先に「品番グループ」画面で対象の品番を登録してください。</p>
                    </ManualStep>
                    <ManualStep :number="2" title="ファイルを添付します。">
                        <p> 「取込ファイルの準備①〜③」でダウンロードしたCSVを、「ここにCSVをドロップ」の枠にドラッグ＆ドロップします。「ファイルを選択」「フォルダを選択」から選ぶこともできます。 </p>
                        <ul class="list-disc space-y-1 pl-5">
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
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">
                            在庫調査は1日1件です。取込を実行した日の調査がすでにあるときは、新しい調査は作られず、その調査が更新されます（初回取込日時は変わりません）。
                        </p>
                        <ul class="mt-1 list-disc space-y-1 pl-5 text-[13px] leading-7 text-ink-2">
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
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">
                            毎朝9:00に、品番グループに登録した品番のAmazon在庫を自動で取得します。Amazon側で在庫レポートを作成するため、完了までに時間がかかることがあります。取得が成功すると、その日の在庫調査に反映されます。
                        </p>
                        <ul class="mt-1 list-disc space-y-1 pl-5 text-[13px] leading-7 text-ink-2">
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
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">
                            「データ取込」画面の「Amazon
                            在庫」欄で確認します。「最新の実行」に状態と、定期実行（毎朝の自動取得）か手動実行かが表示されます。「在庫基準日時」は、Amazon在庫がいつ時点の数かを示します。下の一覧には、直近5回の取得の開始日時・起動（定期／手動）・結果が表示されます。
                        </p>
                        <div class="mt-2 overflow-x-auto rounded-card border border-line">
                            <table class="w-full min-w-100 text-left text-[12.5px]" data-amazon-import-statuses>
                                <thead class="border-b border-line bg-surface-2 text-[11px] text-muted">
                                    <tr>
                                        <th class="w-24 px-3 py-2 font-semibold">状態</th>
                                        <th class="px-3 py-2 font-semibold">意味</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line-2 text-ink-2">
                                    <tr v-for="status in amazonImportStatuses" :key="status.label">
                                        <td class="px-3 py-1.5 font-semibold text-ink">{{ status.label }}</td>
                                        <td class="px-3 py-1.5">{{ status.meaning }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">手動で取得する（特権管理者のみ）</h3>
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">
                            自動取得が失敗したときや、9:00以降に品番グループへ品番を追加したときは、「Amazon 在庫」欄の「Amazon
                            在庫を取得」を押下して取り直します。完了するまで、画面は自動で更新されます。受付・処理中の取得があるあいだは押せません。同じ日に何回取得しても、調査にはその日に成功した最新の在庫が反映されます。
                        </p>
                    </div>
                    <ManualCallout title="取り直しは同じ日のうちに行ってください" tone="warning"
                        >取得した在庫は、取得した日の在庫調査にだけ反映されます。翌日に取得しても、前の日の調査は取込待ちのまま変わりません。</ManualCallout
                    >

                    <div>
                        <h3 class="text-[13.5px] font-semibold text-ink">在庫調査結果での表示</h3>
                        <p class="mt-1 text-[13px] leading-7 text-ink-2">在庫調査結果画面の上部の「Amazon 在庫」に、その調査へのAmazon在庫の反映状況が表示されます。</p>
                        <div class="mt-2 overflow-x-auto rounded-card border border-line">
                            <table class="w-full min-w-100 text-left text-[12.5px]" data-amazon-survey-statuses>
                                <thead class="border-b border-line bg-surface-2 text-[11px] text-muted">
                                    <tr>
                                        <th class="w-24 px-3 py-2 font-semibold">表示</th>
                                        <th class="px-3 py-2 font-semibold">意味</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line-2 text-ink-2">
                                    <tr v-for="status in amazonSurveyStatuses" :key="status.label">
                                        <td class="px-3 py-1.5 font-semibold text-ink">{{ status.label }}</td>
                                        <td class="px-3 py-1.5">{{ status.meaning }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </ManualSection>

                <ManualSection id="notes" title="注意事項" description="操作時の制約や間違えやすい点を記載します。" icon="error">
                    <ManualCallout title="注意" tone="warning">ここに、操作前に確認が必要な注意事項を記載してください。</ManualCallout>
                    <ManualCallout title="操作のヒント" tone="tip">ここに、作業を進めやすくするヒントを記載してください。</ManualCallout>
                </ManualSection>
            </div>
        </div>
    </div>
</template>
