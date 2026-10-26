<script setup lang="ts">
import { computed } from "vue";
import type { InventoryTrendSeries } from "@/api/inventoryTrends";
import { formatStreakDays, stockoutStreak } from "@/utils/inventoryTrendMetrics";

/** stockout: 確定した 0、low: 1〜4、pending: 未確定、missing: その組み合わせの SKU がない */
type CellState = "stockout" | "low" | "inStock" | "pending" | "missing";

interface MatrixCell {
    size: string;
    state: CellState;
    quantity: number | null;
    /** 1週間前との差の文言（+3、-5、±0）。欠品のマスでは「欠品 ◯日目」 */
    note: string;
}

const props = defineProps<{ series: InventoryTrendSeries[]; latestDate: string; weekAgoDate: string | null }>();

/** 在庫が少ないとみなす上限（ヒートマップの最も淡い段階と同じ） */
const LOW_STOCK_MAX = 4;

function quantityOn(series: InventoryTrendSeries, date: string | null): number | null {
    if (!date) return null;
    const point = series.points.find((item) => item.date === date);
    return point && point.source !== null ? point.quantity : null;
}

function formatDifference(value: number): string {
    return value === 0 ? "±0" : `${value > 0 ? "+" : "-"}${Math.abs(value)}`;
}

/** カラーは SKU の並び（sort_order）での出現順、サイズも同じく出現順 */
const sizes = computed(() => [...new Set(props.series.map((item) => item.size))]);
/**
 * 表の最小幅。これより狭いと横スクロールにする。サイズのマス 56px（min-w-14）、カラーの列 80px（w-20）、
 * 計の列 48px（w-12）と、マスの間隔 4px（border-spacing-1）から求める。サイズが5つなら 440px で、
 * 幅 1280px の画面で欠品中のSKUと横に並べても収まる。
 */
const tableMinWidth = computed(() => `${sizes.value.length * (56 + 4) + 80 + 48 + 3 * 4}px`);
const rows = computed(() => {
    const colors = [...new Set(props.series.map((item) => item.color))];
    return colors.map((color) => {
        const cells = sizes.value.map<MatrixCell>((size) => {
            const sku = props.series.find((item) => item.color === color && item.size === size);
            if (!sku) return { size, state: "missing", quantity: null, note: "" };
            const quantity = quantityOn(sku, props.latestDate);
            if (quantity === null) return { size, state: "pending", quantity, note: "" };
            if (quantity === 0) {
                const streak = stockoutStreak(sku, props.latestDate);
                return { size, state: "stockout", quantity, note: streak ? `欠品 ${formatStreakDays(streak)}` : "欠品" };
            }
            const weekAgo = quantityOn(sku, props.weekAgoDate);
            return { size, state: quantity <= LOW_STOCK_MAX ? "low" : "inStock", quantity, note: weekAgo === null ? "" : formatDifference(quantity - weekAgo) };
        });
        const existing = cells.filter((cell) => cell.state !== "missing");
        // 未確定の SKU を含む行は、部分的な合計を出さない
        const total = existing.some((cell) => cell.quantity === null) ? null : existing.reduce((sum, cell) => sum + (cell.quantity ?? 0), 0);
        return { color, label: color ? `カラー ${color}` : "カラーなし", cells, total };
    });
});

const CELL_CLASS: Record<CellState, string> = {
    stockout: "bg-signal-row shadow-[inset_0_0_0_1px_var(--color-signal-border)]",
    low: "shadow-[inset_0_0_0_1px_var(--color-line-stronger)]",
    inStock: "shadow-[inset_0_0_0_1px_var(--color-line)]",
    pending: "shadow-[inset_0_0_0_1px_var(--color-line)]",
    missing: "",
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full table-fixed border-separate border-spacing-1 tabular-nums" :style="{ minWidth: tableMinWidth }" data-color-size-matrix>
            <colgroup>
                <col class="w-20" />
                <col v-for="size in sizes" :key="size" />
                <col class="w-12" />
            </colgroup>
            <thead>
                <tr class="text-[11px] text-muted">
                    <th scope="col" class="px-1 text-left font-semibold"><span class="sr-only">カラー</span></th>
                    <th v-for="size in sizes" :key="size" scope="col" class="px-1 text-center font-semibold" data-matrix-size>{{ size || "サイズなし" }}</th>
                    <th scope="col" class="px-1 text-right font-semibold">計</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.color ?? ''" :data-matrix-color="row.color ?? ''">
                    <th scope="row" class="pr-2 text-left text-[11.5px] font-semibold whitespace-nowrap text-ink-2">{{ row.label }}</th>
                    <td
                        v-for="cell in row.cells"
                        :key="cell.size"
                        class="h-12 min-w-14 rounded-[4px] px-0.5 text-center align-middle"
                        :class="CELL_CLASS[cell.state]"
                        :data-matrix-cell="cell.size"
                        :data-cell-state="cell.state"
                    >
                        <template v-if="cell.state !== 'missing'">
                            <span class="block font-num text-[22px] leading-none font-semibold" :class="cell.state === 'pending' ? 'text-muted' : ''">{{ cell.quantity ?? "—" }}</span>
                            <span
                                v-if="cell.note"
                                class="mt-0.5 block text-[10.5px] leading-tight break-keep"
                                :class="cell.state === 'stockout' ? 'font-semibold text-ink' : 'font-num text-muted'"
                                data-cell-note
                                >{{ cell.note }}</span
                            >
                        </template>
                    </td>
                    <td class="pl-1 text-right font-num text-[17px] font-semibold" :class="row.total === null ? 'text-muted' : ''" data-matrix-total>{{ row.total ?? "—" }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
