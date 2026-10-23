<script setup lang="ts">
import { computed, nextTick, ref, useId } from "vue";
import type { InventoryScope, InventoryTrendPoint, InventoryTrendSeries } from "@/api/inventoryTrends";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import { warehousesOfScope } from "@/constants/warehouses";
import type { SelectOption } from "@/types";
import { formatMonthDay } from "@/utils/inventoryTrendMetrics";

/** マスの段階。stockout: 確定した 0、l1〜l5: 在庫数の段階、pending: 未確定、none: 調査なし */
export type HeatLevel = "stockout" | "l1" | "l2" | "l3" | "l4" | "l5" | "pending" | "none";
type SortKey = "colorSize" | "stockoutDays" | "latest";

interface SkuRow {
    series: InventoryTrendSeries;
    /** キーボードで移るときの行の番号（SKU の行だけを数える） */
    rowIndex: number;
    latest: number | null;
    stockoutDays: number;
}

type HeatmapRow = { kind: "color"; key: string; label: string } | ({ kind: "sku"; key: string } & SkuRow);

interface HoveredCell {
    series: InventoryTrendSeries;
    point: InventoryTrendPoint;
    x: number;
    y: number;
}

const props = defineProps<{ productCode: string; series: InventoryTrendSeries[]; dates: string[]; scope: InventoryScope }>();
/** 「数値を表で見る」の開閉。在庫推移を読み直しても開いたままにするため、画面側で持つ */
const tableOpen = defineModel<boolean>("tableOpen", { default: false });

/** 段階の区切りは固定（品番ごとに変えると、品番を移ったときに色の意味が変わるため） */
const LEVELS: { level: Exclude<HeatLevel, "stockout" | "pending" | "none">; minimum: number; label: string }[] = [
    { level: "l5", minimum: 40, label: "40+" },
    { level: "l4", minimum: 20, label: "20–39" },
    { level: "l3", minimum: 10, label: "10–19" },
    { level: "l2", minimum: 5, label: "5–9" },
    { level: "l1", minimum: 1, label: "1–4" },
];
const LEVEL_CLASS: Record<HeatLevel, string> = {
    stockout: "bg-signal shadow-[inset_0_0_0_1px_var(--color-signal-border)]",
    l1: "bg-[#e2e6ea]",
    l2: "bg-[#c9d0d6]",
    l3: "bg-[#a8b1b9]",
    l4: "bg-[#7d8893]",
    l5: "bg-[#3a434c]",
    pending: "bg-[repeating-linear-gradient(45deg,rgb(17_22_26/0.35)_0_1.5px,transparent_1.5px_4px)] shadow-[inset_0_0_0_1px_var(--color-line-stronger)]",
    none: "shadow-[inset_0_0_0_1px_rgb(17_22_26/0.12)]",
};
const SORT_OPTIONS: SelectOption[] = [
    { value: "colorSize", label: "カラー・サイズ順" },
    { value: "stockoutDays", label: "欠品日数の多い順" },
    { value: "latest", label: "最新在庫の少ない順" },
];
/** 毎日（1日おき）の日付の見出しを出す最長の日数 */
const DAILY_LABEL_LIMIT = 31;
const LABEL_WIDTH = 168;
const VALUE_WIDTH = 64;
const MINIMUM_CELL = 6;
const GAP = 2;

const sort = ref<SortKey>("colorSize");
const hovered = ref<HoveredCell | null>(null);
const active = ref({ row: 0, column: 0 });
const gridRef = ref<HTMLElement | null>(null);
const tableId = `heatmap-table-${useId()}`;

const pointsBySku = computed(() => new Map(props.series.map((item) => [item.skuCode, new Map(item.points.map((point) => [point.date, point]))])));
const skuRows = computed<SkuRow[]>(() => {
    const rows = props.series.map((item) => {
        const surveyed = item.points.filter((point) => point.source !== null);
        return { series: item, rowIndex: 0, latest: surveyed.at(-1)?.quantity ?? null, stockoutDays: surveyed.filter((point) => point.quantity === 0).length };
    });
    // 同じ値のときは SKU の並び（カラー・サイズ順）を保つ
    if (sort.value === "stockoutDays") rows.sort((left, right) => right.stockoutDays - left.stockoutDays);
    if (sort.value === "latest") rows.sort((left, right) => (left.latest ?? Number.POSITIVE_INFINITY) - (right.latest ?? Number.POSITIVE_INFINITY));
    return rows.map((row, rowIndex) => ({ ...row, rowIndex }));
});
const rows = computed<HeatmapRow[]>(() => {
    const result: HeatmapRow[] = [];
    let previousColor: string | null | undefined;
    for (const row of skuRows.value) {
        // カラー・サイズ順のときだけ、カラーが変わるところに見出しの行を挟む
        if (sort.value === "colorSize" && row.series.color !== previousColor) {
            result.push({ kind: "color", key: `color-${row.rowIndex}`, label: row.series.color ? `カラー ${row.series.color}` : "カラーなし" });
            previousColor = row.series.color;
        }
        result.push({ kind: "sku", key: row.series.skuCode, ...row });
    }
    return result;
});
const dateLabels = computed(() => {
    const count = props.dates.length;
    const indexes =
        count <= DAILY_LABEL_LIMIT ? props.dates.map((_, index) => index).filter((index) => index % 2 === 0) : Array.from({ length: 8 }, (_, index) => Math.round((index * (count - 1)) / 7));
    const shown = new Set(indexes);
    return props.dates.map((date, index) => {
        if (!shown.has(index)) return { date, label: "", weekday: weekday(date) };
        const [, month, day] = date.split("-").map(Number);
        const label = index === 0 || day === 1 || count > DAILY_LABEL_LIMIT ? `${month}/${day}` : String(day);
        return { date, label, weekday: weekday(date) };
    });
});
const gridStyle = computed(() => ({
    gridTemplateColumns: `${LABEL_WIDTH}px repeat(${Math.max(1, props.dates.length)}, minmax(${MINIMUM_CELL}px, 1fr)) ${VALUE_WIDTH}px ${VALUE_WIDTH}px`,
    // マスが 6px 未満になる幅では、表の部分を横スクロールにする
    minWidth: `${LABEL_WIDTH + VALUE_WIDTH * 2 + props.dates.length * MINIMUM_CELL + (props.dates.length + 2) * GAP}px`,
}));
const stockoutSkuCount = computed(() => skuRows.value.filter((row) => row.stockoutDays > 0).length);
const ariaLabel = computed(() => {
    const period = props.dates.length > 0 ? `${props.dates[0]!.replaceAll("-", "/")}〜${props.dates.at(-1)!.replaceAll("-", "/")}` : "";
    return `${props.productCode}のSKU別の在庫（${period}、${props.series.length}SKU、欠品のあったSKU ${stockoutSkuCount.value}）`;
});
const breakdownWarehouses = computed(() => warehousesOfScope(props.scope));

function weekday(date: string): number {
    const [year, month, day] = date.split("-").map(Number);
    return new Date(Date.UTC(year!, month! - 1, day!)).getUTCDay();
}

function pointOf(series: InventoryTrendSeries, date: string): InventoryTrendPoint {
    return pointsBySku.value.get(series.skuCode)?.get(date) ?? { date, quantity: null, source: null, basisAt: null };
}

/** 在庫数の段階。調査のない日は none、調査はあるが未確定の日は pending */
function heatLevel(point: InventoryTrendPoint): HeatLevel {
    if (point.source === null) return "none";
    if (point.quantity === null) return "pending";
    if (point.quantity === 0) return "stockout";
    return LEVELS.find((item) => point.quantity! >= item.minimum)!.level;
}

function cellText(series: InventoryTrendSeries, point: InventoryTrendPoint): string {
    const quantity = point.source === null ? "調査なし" : point.quantity === null ? "在庫 未確定" : `在庫 ${point.quantity}`;
    return `${formatMonthDay(point.date)} ${series.skuCode} ${quantity}`;
}

function showTooltip(series: InventoryTrendSeries, point: InventoryTrendPoint, event: Event) {
    const rect = (event.currentTarget as Element).getBoundingClientRect();
    hovered.value = { series, point, x: rect.left + rect.width / 2, y: rect.bottom };
}

function focusCell(row: number, column: number) {
    active.value = { row, column };
    void nextTick(() => gridRef.value?.querySelector<HTMLElement>(`[data-heat-cell="${row}-${column}"]`)?.focus());
}

/** マスの間は矢印キーで移る（Tab で止まるのは1マスだけ） */
function onCellKeydown(event: KeyboardEvent, row: number, column: number) {
    const lastRow = skuRows.value.length - 1;
    const lastColumn = props.dates.length - 1;
    const moves: Record<string, [number, number]> = {
        ArrowLeft: [row, Math.max(0, column - 1)],
        ArrowRight: [row, Math.min(lastColumn, column + 1)],
        ArrowUp: [Math.max(0, row - 1), column],
        ArrowDown: [Math.min(lastRow, row + 1), column],
        Home: [row, 0],
        End: [row, lastColumn],
    };
    const target = moves[event.key];
    if (!target) return;
    event.preventDefault();
    focusCell(target[0], target[1]);
}

function updateSort(value: string | number | null) {
    if (value === "colorSize" || value === "stockoutDays" || value === "latest") sort.value = value;
}

function tooltipLeft(x: number): number {
    const width = 220;
    return Math.max(8, Math.min(x - width / 2, window.innerWidth - width - 8));
}
</script>

<template>
    <section class="flex flex-col gap-3" data-heatmap>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-[13.5px] font-semibold">SKU別の在庫（ヒートマップ）</h2>
                <p class="mt-0.5 text-[11.5px] text-muted">1マスが1日です。色が濃いほど在庫が多く、黄色は欠品（0）。マスにカーソルを合わせると数量を表示します。</p>
            </div>
            <div class="w-48">
                <BaseSelect data-testid="heatmap-sort" :model-value="sort" :options="SORT_OPTIONS" label="並び順" size="sm" :show-placeholder="false" @update:model-value="updateSort" />
            </div>
        </div>

        <div class="overflow-x-auto pb-1">
            <div ref="gridRef" class="grid gap-0.5" :style="gridStyle" role="group" :aria-label="ariaLabel" data-heatmap-grid>
                <div class="pb-1 text-[10.5px] font-semibold text-muted">SKU</div>
                <div
                    v-for="item in dateLabels"
                    :key="`date-${item.date}`"
                    data-heatmap-date
                    class="overflow-visible pb-1 text-center font-mono text-[10px] whitespace-nowrap"
                    :class="item.weekday === 0 ? 'text-sunday' : item.weekday === 6 ? 'text-saturday' : 'text-muted'"
                >
                    {{ item.label }}
                </div>
                <div class="pb-1 text-right text-[10.5px] font-semibold text-muted">最新</div>
                <div class="pb-1 text-right text-[10.5px] font-semibold text-muted">欠品日数</div>
                <!-- 日付の行の下の線。並び順に関係なく引く -->
                <div class="col-span-full h-px bg-line-2" data-heatmap-header-rule aria-hidden="true"></div>

                <template v-for="row in rows" :key="row.key">
                    <div
                        v-if="row.kind === 'color'"
                        data-heatmap-color
                        class="col-span-full pt-1.5 pb-0.5 text-[11px] font-semibold text-ink-2"
                        :class="row.key === rows[0]?.key ? '' : 'mt-1 border-t border-line-2 pt-2.5'"
                    >
                        {{ row.label }}
                    </div>
                    <template v-else>
                        <div class="flex min-w-0 items-center gap-2 pr-2" :data-heatmap-row="row.series.skuCode">
                            <span class="truncate font-mono text-[11.5px] font-medium uppercase" :title="row.series.skuCode">{{ row.series.skuCode }}</span>
                            <span v-if="row.series.size" class="shrink-0 text-[10.5px] text-muted">{{ row.series.size }}</span>
                        </div>
                        <div
                            v-for="(date, column) in dates"
                            :key="`${row.series.skuCode}-${date}`"
                            class="h-5 rounded-[2px] outline-offset-1 focus-visible:outline-2 focus-visible:outline-ink"
                            :class="LEVEL_CLASS[heatLevel(pointOf(row.series, date))]"
                            :data-heat-cell="`${row.rowIndex}-${column}`"
                            :data-heat-level="heatLevel(pointOf(row.series, date))"
                            :tabindex="active.row === row.rowIndex && active.column === column ? 0 : -1"
                            :aria-label="cellText(row.series, pointOf(row.series, date))"
                            @mouseenter="showTooltip(row.series, pointOf(row.series, date), $event)"
                            @mouseleave="hovered = null"
                            @focus="
                                active = { row: row.rowIndex, column };
                                showTooltip(row.series, pointOf(row.series, date), $event);
                            "
                            @blur="hovered = null"
                            @keydown="onCellKeydown($event, row.rowIndex, column)"
                        ></div>
                        <div data-heatmap-latest class="flex items-center justify-end font-num text-[15px] font-semibold tabular-nums" :class="row.latest === null ? 'text-muted' : ''">
                            {{ row.latest ?? "—" }}
                        </div>
                        <div data-heatmap-stockout-days class="flex items-center justify-end font-num text-[15px] font-semibold tabular-nums">
                            <span v-if="row.stockoutDays > 0" class="rounded-chip bg-signal px-1.5 leading-[1.4]">{{ row.stockoutDays }}</span>
                            <span v-else class="text-muted">—</span>
                        </div>
                    </template>
                </template>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 border-t border-line-2 pt-3 text-[11px] text-ink-2" data-heatmap-legend>
            <span class="inline-flex items-center gap-1.5"
                ><span class="h-3 w-3.5 rounded-[2px]" :class="LEVEL_CLASS.stockout"></span><span class="font-num text-[12px] font-semibold">0</span>（欠品）</span
            >
            <span v-for="item in [...LEVELS].reverse()" :key="item.level" class="inline-flex items-center gap-1.5"
                ><span class="h-3 w-3.5 rounded-[2px]" :class="LEVEL_CLASS[item.level]"></span><span class="font-num text-[12px] font-semibold">{{ item.label }}</span></span
            >
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3.5 rounded-[2px]" :class="LEVEL_CLASS.pending"></span>未確定</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3.5 rounded-[2px]" :class="LEVEL_CLASS.none"></span>調査なし</span>
            <BaseButton
                v-if="$slots.table"
                data-testid="heatmap-table-toggle"
                class="sm:ml-auto"
                variant="ghost"
                size="sm"
                :icon="tableOpen ? 'expand_less' : 'table_view'"
                :aria-expanded="tableOpen"
                :aria-controls="tableId"
                @click="tableOpen = !tableOpen"
            >
                {{ tableOpen ? "表を閉じる" : "数値を表で見る" }}
            </BaseButton>
        </div>

        <div v-if="tableOpen" :id="tableId" class="-mx-[18px] -mb-3.5 border-t border-line-2">
            <slot name="table" />
        </div>

        <Transition name="heatmap-tooltip">
            <div
                v-if="hovered"
                data-heatmap-tooltip
                class="pointer-events-none fixed z-50 w-[220px] rounded-card border border-line bg-surface px-3 py-2 text-[11px] whitespace-nowrap text-ink shadow-float"
                :style="{ left: `${tooltipLeft(hovered.x)}px`, top: `${hovered.y + 8}px` }"
            >
                <p class="font-mono text-[11px] font-medium">{{ formatMonthDay(hovered.point.date) }}</p>
                <p class="mt-0.5 flex items-center justify-between gap-3">
                    <span class="truncate font-mono font-medium text-ink-2 uppercase">{{ hovered.series.skuCode }}</span>
                    <span v-if="hovered.point.source === null" class="text-[10.5px] text-muted" data-tooltip-quantity>調査なし</span>
                    <span v-else-if="hovered.point.quantity === null" class="text-[10.5px] text-muted" data-tooltip-quantity>未確定</span>
                    <span v-else class="font-num text-[13px] font-semibold" data-tooltip-quantity
                        >在庫 {{ hovered.point.quantity }}<span class="ml-0.5 font-sans text-[10.5px] font-normal text-muted">点</span></span
                    >
                </p>
                <div v-if="hovered.point.warehouses" class="mt-1 space-y-0.5 border-t border-line pt-1" data-tooltip-breakdown>
                    <p v-for="warehouse in breakdownWarehouses" :key="warehouse.key" data-tooltip-breakdown-row class="flex items-center justify-between gap-4 text-ink-2">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-xs" :style="{ backgroundColor: warehouse.color }"></span>{{ warehouse.label }}</span>
                        <span v-if="hovered.point.warehouses[warehouse.key] == null" class="text-[10.5px] text-muted">未確定</span>
                        <span v-else class="font-num text-[13px] font-semibold text-ink"
                            >{{ hovered.point.warehouses[warehouse.key] }}<span class="ml-0.5 font-sans text-[10.5px] font-normal text-muted">点</span></span
                        >
                    </p>
                </div>
            </div>
        </Transition>
    </section>
</template>

<style scoped>
.heatmap-tooltip-enter-active {
    transition: opacity 140ms ease-out;
}

.heatmap-tooltip-leave-active {
    transition: opacity 90ms ease-in;
}

.heatmap-tooltip-enter-from,
.heatmap-tooltip-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .heatmap-tooltip-enter-active,
    .heatmap-tooltip-leave-active {
        transition-duration: 0.01ms;
    }
}
</style>
