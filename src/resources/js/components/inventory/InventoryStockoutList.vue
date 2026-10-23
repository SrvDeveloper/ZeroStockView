<script setup lang="ts">
import { computed } from "vue";
import type { InventoryTrendSeries } from "@/api/inventoryTrends";
import { formatMonthDay, stockoutStreak } from "@/utils/inventoryTrendMetrics";

const props = defineProps<{ series: InventoryTrendSeries[]; latestDate: string }>();

/** 最新の調査日に欠品している SKU を、連続欠品日数の長い順に（同じ日数なら SKU の並び順に）並べる */
const items = computed(() =>
    props.series
        .flatMap((series) => {
            const streak = stockoutStreak(series, props.latestDate);
            if (!streak) return [];
            const periodDays = series.points.filter((point) => point.source !== null && point.quantity === 0).length;
            return [{ series, streak, periodDays }];
        })
        .sort((left, right) => right.streak.days - left.streak.days),
);
</script>

<template>
    <p v-if="items.length === 0" class="py-6 text-center text-[12.5px] text-muted" data-stockout-empty>最新の調査で欠品しているSKUはありません。</p>
    <ul v-else class="flex flex-col" data-stockout-list>
        <li v-for="item in items" :key="item.series.skuCode" class="flex items-center gap-3 border-t border-line-2 py-2.5" :data-stockout-sku="item.series.skuCode">
            <span
                class="inline-flex h-10 min-w-13 shrink-0 flex-col items-center justify-center rounded-[5px] bg-signal-row px-1 leading-none shadow-[inset_0_0_0_1px_var(--color-signal-border)]"
                data-stockout-streak
                ><span class="font-num text-[20px] font-bold">{{ item.streak.days }}</span
                ><span class="mt-0.5 text-[9.5px] text-ink-2">日目{{ item.streak.openEnded ? "以上" : "" }}</span></span
            >
            <div class="min-w-0 flex-1">
                <p class="truncate">
                    <span class="font-mono text-[12.5px] font-semibold uppercase">{{ item.series.skuCode }}</span>
                    <span class="ml-1.5 text-[11px] text-muted">{{ item.series.color ? `カラー ${item.series.color}` : "カラーなし" }} / {{ item.series.size || "サイズなし" }}</span>
                </p>
                <p class="mt-0.5 text-[11.5px] text-ink-2" data-stockout-since>
                    <template v-if="item.streak.openEnded">{{ formatMonthDay(item.streak.startDate) }}から欠品 · 期間内にそれより前の在庫の記録なし</template>
                    <template v-else
                        >{{ formatMonthDay(item.streak.startDate) }}から欠品 · 直前の在庫 {{ item.streak.previousQuantity }}（{{ formatMonthDay(item.streak.lastInStockDate!) }}）</template
                    >
                </p>
            </div>
            <span class="shrink-0 text-right text-[11px] leading-tight text-ink-2" data-stockout-period
                >期間中<br /><span class="font-num text-[16px] font-semibold text-ink">{{ item.periodDays }}</span> 日</span
            >
        </li>
    </ul>
</template>
