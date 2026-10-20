<script setup lang="ts">
import ManualFigure from "@/components/manual/ManualFigure.vue";
import ManualFigureMarker from "@/components/manual/ManualFigureMarker.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
</script>

<template>
    <!-- データ取込画面「Amazon 在庫」欄（DataImportPage.vue）。日時は例 -->
    <ManualFigure
        label="データ取込画面の「Amazon 在庫」欄。右上に状態、中ほどに最新の実行と在庫基準日時、「Amazon 在庫を取得」ボタン、下に直近の取得の一覧があります。"
        caption="日時は例です。「Amazon 在庫を取得」ボタンは、特権管理者にだけ表示されます。"
        :legend="[
            '状態（受付・処理中・成功・失敗）',
            '最新の実行と、Amazon在庫がいつ時点の数か（在庫基準日時）',
            '手動で取り直すボタン（特権管理者のみ）',
            '直近5回の取得の開始日時・起動（定期／手動）・結果',
        ]"
    >
        <div class="w-[380px] rounded-card border border-line bg-surface shadow-card">
            <div class="flex items-start gap-3 border-b border-line-2 px-4 py-2.5">
                <div class="flex-1">
                    <p class="text-[13px] font-bold">Amazon 在庫</p>
                    <p class="text-[11px] text-muted">毎日 9:00 に自動で取得し、同じ日の調査結果に反映します。</p>
                </div>
                <span class="flex items-center gap-1.5"><ManualFigureMarker :number="1" /><StatusLamp state="ok" label="成功" class="text-[11.5px]" /></span>
            </div>
            <div class="flex flex-col gap-2.5 px-4 py-3 text-[11.5px]">
                <div class="flex items-start gap-2">
                    <ManualFigureMarker :number="2" />
                    <div class="flex flex-col gap-1">
                        <p class="text-ink-2">最新の実行: <b class="text-ink">成功</b> ・ 定期実行</p>
                        <p class="grid grid-cols-[auto_1fr] gap-x-3">
                            <span class="text-muted">受付日時</span><span class="font-mono">2026-10-20 09:00</span><span class="text-muted">在庫基準日時</span
                            ><span class="font-mono">2026-10-20 09:04</span>
                        </p>
                    </div>
                </div>
                <span class="flex items-center gap-2">
                    <ManualFigureMarker :number="3" />
                    <span class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-2.5 py-1 font-semibold"
                        ><AppIcon name="refresh" :size="14" />Amazon 在庫を取得</span
                    >
                </span>
            </div>
            <div class="border-t border-line-2 text-[11px]">
                <div class="flex items-center gap-2 bg-surface-2 px-4 py-1.5 text-[10.5px] text-muted">
                    <ManualFigureMarker :number="4" />
                    <span class="w-20">開始</span><span class="w-10">起動</span><span>結果</span>
                </div>
                <div class="flex items-center gap-2 border-t border-line-2 px-4 py-1.5 pl-11">
                    <span class="w-20 font-mono">10-20 09:00</span><span class="w-10">定期</span><StatusLamp state="ok" label="成功" />
                </div>
                <div class="flex items-center gap-2 border-t border-line-2 px-4 py-1.5 pl-11">
                    <span class="w-20 font-mono">10-19 09:00</span><span class="w-10">定期</span><StatusLamp state="ok" label="成功" />
                </div>
            </div>
        </div>
    </ManualFigure>
</template>
