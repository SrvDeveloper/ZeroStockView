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
    { id: "basic-operation-amazon", title: "Amazon在庫取得" },
    { id: "notes", title: "注意事項" },
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

                <ManualSection id="basic-operation-amazon" title="Amazon在庫取得" description="Amazon在庫はSP-APIから取得します。ファイルの準備は不要です。" icon="touch_app">
                    <ManualStep :number="1" title="データ取込画面でAmazon在庫を確認する">
                        <p>Amazon在庫の取得状況と最終取得日時を確認します。必要に応じて手動取得を実行してください。</p>
                        <ManualCallout title="調査履歴のAmazon在庫" tone="tip"
                            >調査時点までに取得に成功した最新のSP-API在庫を表示します。Amazon の基準日時は、調査の初回取込日時と異なる場合があります。</ManualCallout
                        >
                    </ManualStep>
                </ManualSection>

                <ManualSection id="notes" title="注意事項" description="操作時の制約や間違えやすい点を記載します。" icon="error">
                    <ManualCallout title="注意" tone="warning">ここに、操作前に確認が必要な注意事項を記載してください。</ManualCallout>
                    <ManualCallout title="操作のヒント" tone="tip">ここに、作業を進めやすくするヒントを記載してください。</ManualCallout>
                </ManualSection>
            </div>
        </div>
    </div>
</template>
