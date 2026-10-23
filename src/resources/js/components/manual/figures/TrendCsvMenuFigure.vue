<script setup lang="ts">
import ManualFigure from "@/components/manual/ManualFigure.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
</script>

<template>
    <!-- 在庫推移の CSV 出力のボタン（InventoryTrendListPage.vue の「日次在庫CSV」と、InventoryTrendPage.vue の「この品番のCSV」） -->
    <ManualFigure
        label="在庫推移の品番一覧の「日次在庫CSV」ボタンと、押すと開くメニューの「全品番の日次CSV」、品番の詳細の「この品番のCSV」ボタン。押す場所が黄色の枠で囲まれています。"
        caption="左が品番一覧の右上、右が品番の詳細の右上です（品番と期間は例です）。黄色の枠が押す場所です。"
    >
        <div class="flex min-w-[520px] flex-wrap items-start justify-between gap-6">
            <div class="flex flex-col items-end gap-1.5">
                <span
                    class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-3 py-1.5 text-[11.5px] font-semibold ring-3 ring-signal ring-offset-2 ring-offset-surface-2"
                    ><AppIcon name="download" :size="14" />日次在庫CSV</span
                >
                <div class="mt-1 w-64 overflow-hidden rounded-card border border-line bg-surface py-1 shadow-float">
                    <p class="mx-1.5 my-0.5 rounded-control px-2.5 py-1.5 ring-3 ring-signal">全品番の日次CSV<span class="ml-2 font-mono text-[10.5px] text-muted">09-24 → 10-23</span></p>
                    <p class="mt-1 border-t border-line-2 px-3.5 pt-1.5 pb-1 text-[10.5px] leading-relaxed text-muted">
                        対象期間の調査日の在庫と、調査がない日のAmazon取得結果を出力します。Amazon取得だけの日は他の在庫区分が空欄です。
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-control border border-line-strong bg-surface px-2.5 py-1.5 text-[11.5px] text-ink-2"
                    >次の品番<span class="ml-1 font-mono text-[10.5px] text-muted">AB-1235</span></span
                >
                <span
                    class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-3 py-1.5 text-[11.5px] font-semibold ring-3 ring-signal ring-offset-2 ring-offset-surface-2"
                    ><AppIcon name="download" :size="14" />この品番のCSV</span
                >
            </div>
        </div>
    </ManualFigure>
</template>
