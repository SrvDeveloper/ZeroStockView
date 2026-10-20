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
</script>

<template>
    <p>
        「データ取込」画面は、編集者と特権管理者が使えます。取込の対象は、すべての品番グループに登録した品番です（同じ品番が複数のグループにあっても、1回だけ取り込みます）。Amazon在庫はファイルでは取り込みません。「Amazon在庫の取得」を参照してください。
    </p>

    <div class="space-y-6">
        <ManualHeading id="steps">手順</ManualHeading>
        <ManualStep :number="1" title="データ取込画面を開きます。">
            <p>メニューの<ManualUi variant="menu">データ取込</ManualUi>を押下します。</p>
            <p>品番グループが1件もないときは「品番グループがありません。」と表示されます。先に「品番グループ」画面で対象の品番を登録してください。</p>
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
        </ul>
    </div>
</template>
