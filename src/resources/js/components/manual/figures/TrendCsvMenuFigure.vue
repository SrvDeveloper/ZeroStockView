<script setup lang="ts">
import ManualFigure from "@/components/manual/ManualFigure.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
</script>

<template>
    <!-- 在庫推移画面「日次在庫をCSV出力」のメニュー（InventoryTrendPage.vue） -->
    <ManualFigure
        label="在庫推移画面の「日次在庫をCSV出力」ボタンと、押すと開くメニュー。ボタンと、メニューの「選択品番の日次CSV」「全品番の日次CSV」が黄色の枠で囲まれています。"
        caption="在庫推移画面の右上（品番は例です）。黄色の枠が押す場所です。ボタンを押すとメニューが開くので、出力する範囲を選びます。"
    >
        <div class="flex min-w-[340px] flex-col items-end gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-control bg-ink px-3 py-1.5 text-[11.5px] font-semibold text-white ring-3 ring-signal ring-offset-2 ring-offset-surface-2"
                ><AppIcon name="download" :size="14" />日次在庫をCSV出力</span
            >
            <div class="mt-1 w-72 overflow-hidden rounded-card border border-line bg-surface py-1 shadow-float">
                <div class="mx-1.5 my-0.5 rounded-control ring-3 ring-signal">
                    <p class="px-2.5 py-1.5">選択品番の日次CSV<span class="ml-2 font-mono text-[10.5px] text-muted">AB-1234</span></p>
                    <p class="border-t border-line-2 px-2.5 py-1.5">全品番の日次CSV</p>
                </div>
                <p class="mt-1 border-t border-line-2 px-3.5 pt-1.5 pb-1 text-[10.5px] leading-relaxed text-muted">
                    対象期間の調査日の在庫と、調査がない日のAmazon取得結果を出力します。Amazon取得だけの日は他の在庫区分が空欄です。
                </p>
            </div>
        </div>
    </ManualFigure>
</template>
