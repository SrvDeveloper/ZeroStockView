<script setup lang="ts">
import type { StockState } from "@/utils/stock";

withDefaults(
    defineProps<{
        quantity: number | null;
        state: StockState;
        /** 内訳（自社／FBA、自社／RFC）。在庫があるときだけ数字の下に表示する */
        breakdown?: [number | null, number | null] | null;
        /** 取込待ちを札で示す文言。空なら「—」を表示する */
        pendingLabel?: string;
        /** 全倉庫ゼロの行の中にあるか */
        strong?: boolean;
        total?: boolean;
    }>(),
    { breakdown: null, pendingLabel: "", strong: false, total: false },
);
</script>

<template>
    <span class="inline-flex flex-col items-end" :data-stock-state="state">
        <span v-if="state === 'na'" class="text-[10.5px] font-semibold whitespace-nowrap text-muted">対象外</span>
        <template v-else-if="state === 'pending'">
            <span v-if="pendingLabel" class="rounded-chip border border-dashed border-line-stronger px-2 py-px text-[10.5px] font-semibold whitespace-nowrap text-ink-2">{{ pendingLabel }}</span>
            <span v-else class="font-num text-lg text-muted" title="取込待ち・未取得">—</span>
        </template>
        <span
            v-else-if="state === 'zero'"
            class="inline-block min-w-8 rounded-chip px-2 text-center font-num text-[21px] leading-[1.15] font-semibold"
            :class="strong ? 'bg-signal-chip' : 'bg-hazard-pale shadow-[inset_0_0_0_1px_rgb(185_140_0/0.25)]'"
            >0</span
        >
        <template v-else>
            <span class="font-num text-[21px] leading-[1.15] tabular-nums" :class="[state === 'low' ? 'font-bold text-caution' : 'font-semibold', total ? 'font-bold' : '']">{{ quantity }}</span>
            <span v-if="breakdown" data-breakdown class="font-num text-xs leading-[1.15] tracking-wide text-muted"
                ><span class="sr-only">（内訳 </span>{{ breakdown[0] ?? "—" }} / {{ breakdown[1] ?? "—" }}<span class="sr-only">）</span></span
            >
        </template>
    </span>
</template>
