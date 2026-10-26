<script setup lang="ts">
import ImportDropzoneFigure from "@/components/manual/figures/ImportDropzoneFigure.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualResult from "@/components/manual/ManualResult.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";
import ManualUi from "@/components/manual/ManualUi.vue";

/** 取込の失敗時に出るメッセージ（InventoryImportService の検証メッセージ）と対処 */
const importErrors = [
    { term: "〜の種類を内容から判定できません。", meaning: "BOSS・ターコイズから出力したCSVを、手を加えずに添付しているか確認してください。" },
    { term: "〜のファイルが複数あります。", meaning: "同じ種類のファイルを2件以上添付しています。1件だけにしてください。" },
    { term: "〜行目の数量が…", meaning: "ファイルの数量の列に、数値でない値やマイナスの値があります。出力し直してください。" },
];

/** 補完内容の確認で表示する項目（BossHistoryImportCard.vue の確認の表示） */
const bossHistoryPreviewItems = [
    { term: "基準の調査", meaning: "履歴の期間内で、BOSSのレポートを取り込んだ日です。推定したレポートの出力時刻と、履歴と合わなかったSKU・倉庫の件数が表示されます。" },
    { term: "補完する日", meaning: "BOSSのレポートを取り込んでいない日です。調査がない日は「新しく作る」、ある日は「既存の調査を更新」と表示されます。" },
    { term: "データなしのSKU", meaning: "値を再現できず「—」（データなし）になるSKUの数です。数字にマウスを重ねると、理由ごとの件数が表示されます。" },
    { term: "合わないSKU", meaning: "基準の調査の値と履歴が合わない、または履歴のつながりが途切れている・マイナスになるSKU・倉庫です。補完する日すべてでデータなしになります。" },
    { term: "補完できない日", meaning: "9:00が履歴の期間の外の日と、旧方式（5ファイル）の調査の日です。" },
];

/** 補完の実行前に出るメッセージと対処 */
const bossHistoryErrors = [
    {
        term: "在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。",
        meaning: "休日前の最後の出勤日の0:00から出力し直すか、期間内の出勤日のBOSSのレポートを先に取り込んでください。",
    },
    { term: "在庫変動履歴が、BOSSのレポートを出力した時刻より後から始まっています。…", meaning: "レポートを出力した日の0:00から出力し直してください。" },
    { term: "補完する日がありません。", meaning: "期間内のすべての日に、BOSSのレポートを取り込み済みです。補完は不要です。" },
    { term: "BOSSの在庫変動履歴のファイルではありません。…", meaning: "BOSSから出力した在庫変動履歴のCSVを、手を加えずに選択してください。" },
];
</script>

<template>
    <p>
        「データ取込」画面は、編集者と特権管理者が使えます。取込の対象は、CrossWalkerで有効な全品番です（品番グループに入れる必要はありません）。Amazon在庫はファイルでは取り込みません。「Amazon在庫の取得」を参照してください。
    </p>

    <div class="space-y-6">
        <ManualHeading id="steps">手順</ManualHeading>
        <ManualStep :number="1" title="データ取込画面を開きます。">
            <p>メニューの<ManualUi variant="menu">データ取込</ManualUi>を押下します。</p>
        </ManualStep>
        <ManualStep :number="2" title="ファイルを添付します。">
            <p>
                「取込ファイルの準備①〜③」でダウンロードしたCSVを、「ここにCSVをドロップ」の枠にドラッグ＆ドロップします。<ManualUi icon="upload_file">ファイルを選択</ManualUi
                ><ManualUi icon="folder_open">フォルダを選択</ManualUi>から選ぶこともできます。
            </p>
            <ImportDropzoneFigure />
            <ul class="list-disc space-y-1 pl-5">
                <li>1回に添付できるのは1〜3件です。3種類がそろっていなくても取り込めます。</li>
                <li>ファイルの種類（BOSS・ECストック・フリー在庫）は、CSVの1行目の見出しから自動で判定します。ファイル名を変える必要はありません。</li>
                <li>間違えて添付したファイルは、ファイル名の右の<ManualUi>×</ManualUi>で外せます。</li>
            </ul>
        </ManualStep>
        <ManualStep :number="3" title="取込を実行します。">
            <p><ManualUi variant="primary" icon="play_arrow">取込・調査を実行</ManualUi>を押下します。</p>
            <ManualResult>「取込・調査が完了しました」と表示され、在庫調査結果画面が開きます。</ManualResult>
        </ManualStep>
    </div>

    <ManualCallout id="import-errors" class="scroll-mt-32 lg:scroll-mt-20" title="取込に失敗したとき" note="在庫調査は作成も更新もされません" tone="warning">
        <p>「取込に失敗しました」と、原因が表示されます。原因を直してから、もう一度実行してください。</p>
        <ManualTermTable class="mt-3" :rows="importErrors" term-label="表示されるメッセージ" meaning-label="どうするか" mono-term data-import-errors />
    </ManualCallout>

    <div>
        <ManualHeading id="same-day">同じ日に追加で取り込むとき</ManualHeading>
        <p class="mt-3">在庫調査は1日1件です。取込を実行した日の調査がすでにあるときは、新しい調査は作られず、その調査が更新されます（初回取込日時は変わりません）。</p>
        <figure class="mt-4 grid grid-cols-1 overflow-hidden rounded-card border border-line sm:grid-cols-3" aria-label="同じ日に2回に分けて取り込む例" data-same-day-example>
            <div class="flex flex-col gap-1.5 bg-surface-2 px-4 py-3.5">
                <span class="text-[12px] font-bold text-muted">午前の取込</span>
                <b class="text-[14.5px] leading-snug">BOSSだけを添付</b>
                <span class="flex items-center gap-1" aria-label="取込ファイル 1/3">
                    <i class="block h-3 w-3 rounded-[3px] bg-ink"></i><i class="block h-3 w-3 rounded-[3px] shadow-[inset_0_0_0_1.5px_var(--color-line-stronger)]"></i
                    ><i class="block h-3 w-3 rounded-[3px] shadow-[inset_0_0_0_1.5px_var(--color-line-stronger)]"></i>
                    <span class="ml-1.5 font-mono text-[12px]">1/3</span>
                </span>
                <span class="text-[12.5px] leading-[1.6] text-ink-2">ECストック・フリー在庫は「—」（取込待ち）</span>
            </div>
            <div class="flex flex-col gap-1.5 border-t border-line-2 bg-surface-2 px-4 py-3.5 sm:border-t-0 sm:border-l">
                <span class="text-[12px] font-bold text-muted">午後の取込</span>
                <b class="text-[14.5px] leading-snug">ECストックとフリー在庫を添付</b>
                <span class="text-[12.5px] leading-[1.6] text-ink-2">BOSSは午前のファイルをそのまま使います。</span>
            </div>
            <div class="flex flex-col gap-1.5 bg-ink px-4 py-3.5 text-sidebar-strong">
                <span class="text-[12px] font-bold text-signal">その日の調査</span>
                <b class="text-[14.5px] leading-snug">3種類がそろう</b>
                <span class="flex items-center gap-1" aria-label="取込ファイル 3/3">
                    <i class="block h-3 w-3 rounded-[3px] bg-sidebar-strong"></i><i class="block h-3 w-3 rounded-[3px] bg-sidebar-strong"></i
                    ><i class="block h-3 w-3 rounded-[3px] bg-sidebar-strong"></i>
                    <span class="ml-1.5 font-mono text-[12px]">3/3</span>
                </span>
                <span class="text-[12.5px] leading-[1.6] text-primary-200">初回取込日時は変わりません。</span>
            </div>
        </figure>
        <ul class="mt-4 list-disc space-y-1 pl-5">
            <li>添付した種類は、新しいファイルの内容に置き換わります。</li>
            <li>添付しなかった種類は、その日に前回取り込んだファイルをそのまま使います。</li>
            <li>その日に一度も取り込んでいない種類は「取込待ち」になり、在庫表では「—」と表示されます。取込待ちは在庫0ではなく、まだ数がわからない状態です。</li>
            <li
                >BOSSのファイルに載っていないSKUも、在庫表では「—」（データなし）と表示されます。在庫0とはみなしません。KEEP一覧表（ECストック）と在庫一覧照会表（フリー在庫）は、載っていないSKUを0とします。</li
            >
        </ul>
    </div>

    <div class="space-y-6">
        <ManualHeading id="boss-history">休日のBOSS在庫を補完する</ManualHeading>
        <p>
            BOSSのレポート（倉庫毎の在庫数レポート）を出力しなかった休日の
            BOSS在庫を、BOSSの「在庫変動履歴」から9:00時点の値で再現して、その日の調査に保存します。休日明けの出勤日に、その日のBOSSのレポートを取り込んだあとで行います。
        </p>
        <ul class="list-disc space-y-1 pl-5">
            <li>補完するのはBOSS（自社倉庫・RFC倉庫）の在庫だけです。フリー在庫・ECストックは補完されず、取込待ちのままです。Amazon在庫は、毎朝の自動取得の結果がその日の調査に入ります。</li>
            <li>その日の調査がなければ、新しく作ります。BOSSのレポートを取り込んだ日と、旧方式（5ファイル）の調査の日は変えません。</li>
        </ul>
        <ManualStep :number="1" title="BOSSで在庫変動履歴を出力します。">
            <ul class="list-disc space-y-1 pl-5">
                <li>期間は、休日前の最後の出勤日の0:00から、休日明けにBOSSのレポートを出力したあとまでにします（例: 金曜0:00〜月曜のレポートの出力後）。</li>
                <li>処理種別や倉庫は絞らず、すべてを出力します。</li>
            </ul>
        </ManualStep>
        <ManualStep :number="2" title="在庫変動履歴を選択します。">
            <p>
                データ取込画面の「休日のBOSS在庫を補完」で、<ManualUi icon="upload_file">在庫変動履歴を選択</ManualUi
                >を押下し、出力したCSVを1件選びます。どの日をどう補完するかが表示されます（この時点では保存されません）。
            </p>
            <ManualTermTable class="mt-3" :rows="bossHistoryPreviewItems" term-label="項目" data-boss-history-preview-items />
        </ManualStep>
        <ManualStep :number="3" title="内容を確かめて、補完を実行します。">
            <p>
                合わないSKUがないか、補完する日が想定どおりかを確かめ、<ManualUi variant="primary" icon="play_arrow">補完を実行</ManualUi
                >を押下します。やめるときは<ManualUi>キャンセル</ManualUi>を押下します。
            </p>
            <ManualResult>「◯日分のBOSS在庫を補完しました」と表示され、補完した日の一覧（調査結果へのリンク）が表示されます。</ManualResult>
        </ManualStep>
    </div>

    <ManualCallout id="boss-history-errors" class="scroll-mt-32 lg:scroll-mt-20" title="在庫変動履歴を取り込めないとき" note="調査は変わりません" tone="warning">
        <ManualTermTable :rows="bossHistoryErrors" term-label="表示されるメッセージ" meaning-label="どうするか" mono-term data-boss-history-errors />
    </ManualCallout>
</template>
