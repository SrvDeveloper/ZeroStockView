<script setup lang="ts">
import ManualFigure from "@/components/manual/ManualFigure.vue";
import ManualFigureMarker from "@/components/manual/ManualFigureMarker.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
</script>

<template>
    <!-- 在庫調査結果画面の上部（SurveyResultPage.vue）。日時は例 -->
    <ManualFigure
        label="在庫調査結果画面の上部。右側に、最新の在庫を再読込・保存ファイルで再照合・絞込結果をExcel出力・全件をExcel出力の4つのボタンが並び、見出しの下に日時と取込ファイルの一括ダウンロードがあります。"
        caption="日時は例です。「保存ファイルで再照合」は、編集者と特権管理者にだけ表示されます。"
        :legend="[
            '最新の在庫を再読込：この調査を読み込み直します',
            '保存ファイルで再照合：保存中のファイルで照合し直します（編集者・特権管理者）',
            'Excel出力：絞り込みで該当したSKUだけ、またはすべてのSKUを出力します',
            '一括ダウンロード：保存中の取込ファイルをまとめてダウンロードします',
        ]"
    >
        <div class="flex w-max max-w-full min-w-min flex-col gap-2 rounded-card border border-line bg-surface px-4 py-3 shadow-card">
            <span class="inline-flex items-center gap-0.5 text-[11px] text-muted"><AppIcon name="chevron_left" :size="14" />調査履歴に戻る</span>
            <div class="flex flex-col gap-2">
                <span class="flex items-center gap-2.5">
                    <b class="text-[17px] font-extrabold">在庫調査結果</b>
                    <span class="font-mono text-[11px] text-muted">2026-10-20</span>
                </span>
                <span class="flex items-center gap-1 text-[11px] font-semibold whitespace-nowrap">
                    <ManualFigureMarker :number="1" />
                    <span class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-1.5 py-1"><AppIcon name="refresh" :size="13" />最新の在庫を再読込</span>
                    <ManualFigureMarker :number="2" class="ml-0.5" />
                    <span class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-1.5 py-1"
                        ><AppIcon name="compare_arrows" :size="13" />保存ファイルで再照合</span
                    >
                    <ManualFigureMarker :number="3" class="ml-0.5" />
                    <span class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-1.5 py-1"><AppIcon name="table_view" :size="13" />絞込結果をExcel出力</span>
                    <span class="inline-flex items-center gap-1 rounded-control bg-ink px-1.5 py-1 text-white"><AppIcon name="download" :size="13" />全件をExcel出力</span>
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted">
                <span>初回取込日時: <span class="font-mono text-ink-2">2026-10-20 10:15</span></span>
                <span>結果更新日時: <span class="font-mono text-ink-2">2026-10-20 13:40</span></span>
                <span class="inline-flex items-center gap-1.5"
                    >取込ファイル: <span class="font-mono text-ink-2">3/3</span><ManualFigureMarker :number="4" class="ml-1" /><span
                        class="inline-flex items-center gap-1 rounded-control px-1.5 py-0.5 font-semibold text-ink"
                        ><AppIcon name="folder_zip" :size="13" />一括ダウンロード</span
                    ></span
                >
            </div>
        </div>
    </ManualFigure>
</template>
