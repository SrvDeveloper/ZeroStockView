<script setup lang="ts">
import { computed } from "vue";
import type { InventoryTrendSkuState } from "@/api/inventoryTrends";

const props = defineProps<{ states: InventoryTrendSkuState[] }>();

const label = computed(() => {
    const stockout = props.states.filter((state) => state === "stockout").length;
    const pending = props.states.filter((state) => state === "pending").length;
    return `${props.states.length}SKUのうち欠品 ${stockout}、未確定 ${pending}`;
});

/** 在庫あり: 墨の中間色、欠品（確定した0）: 黄、未確定: 斜線 */
const stateClass: Record<InventoryTrendSkuState, string> = {
    inStock: "bg-[#7d8893]",
    stockout: "bg-signal shadow-[inset_0_0_0_1px_var(--color-signal-border)]",
    pending: "bg-[repeating-linear-gradient(45deg,rgb(17_22_26/0.35)_0_1.5px,transparent_1.5px_4px)] shadow-[inset_0_0_0_1px_var(--color-line-stronger)]",
};
</script>

<template>
    <div class="flex max-w-[168px] flex-wrap gap-0.5" role="img" :aria-label="label" data-sku-state-strip>
        <span v-for="(state, index) in states" :key="index" class="block size-3 rounded-[2px]" :class="stateClass[state]" :data-sku-state="state"></span>
    </div>
</template>
