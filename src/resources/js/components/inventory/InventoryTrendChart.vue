<script setup lang="ts">
import { computed, ref } from "vue";

interface TrendPoint {
    date: string;
    quantity: number | null;
}

interface TrendSeries {
    skuCode: string;
    label: string;
    color: string;
    points: TrendPoint[];
}

interface StockoutSkuDetail {
    skuCode: string;
    stockoutDays: number;
}

interface StockoutPoint extends TrendPoint {
    skus?: StockoutSkuDetail[];
    periodStockoutDays?: number;
}

interface StockoutSeries {
    label: string;
    color: string;
    points: StockoutPoint[];
}

interface TooltipEntry {
    series: TrendSeries;
    quantity: number;
    percentage?: number;
}

interface HoveredPoint {
    date: string;
    entries: TooltipEntry[];
    activeColor: string;
    x: number;
    y: number;
    totalQuantity: number | null;
    stockoutCount: number | null;
    stockoutSkus: StockoutSkuDetail[] | null;
    periodStockoutDays: number | null;
    tooltipX: number;
    tooltipY: number;
    usesViewportCoordinates: boolean;
}

interface LineSegment {
    points: string;
    dashed: boolean;
}

interface StackedBar {
    date: string;
    index: number;
    total: number;
    entries: TooltipEntry[];
    segments: { series: TrendSeries; y: number; height: number }[];
}

type ChartMode = "line" | "bar";

const props = withDefaults(defineProps<{ series: TrendSeries[]; displayMode?: ChartMode; stockoutSeries?: StockoutSeries | null }>(), {
    displayMode: "line",
    stockoutSeries: null,
});

const width = 960;
const height = 360;
const plot = { left: 56, right: 56, top: 20, bottom: 48 };
const plotWidth = width - plot.left - plot.right;
const plotHeight = height - plot.top - plot.bottom;
const hovered = ref<HoveredPoint | null>(null);

const dates = computed(() => props.series[0]?.points.map((point) => point.date) ?? []);
const maxQuantity = computed(() => {
    const quantities =
        props.displayMode === "bar"
            ? dates.value.map((date) => props.series.reduce((total, series) => total + (series.points.find((point) => point.date === date)?.quantity ?? 0), 0))
            : props.series.flatMap((item) => item.points.map((point) => point.quantity).filter((quantity): quantity is number => quantity !== null));
    const maximum = Math.max(0, ...quantities);
    return Math.max(20, Math.ceil(maximum / 10) * 10);
});
const yTicks = computed(() => Array.from({ length: 5 }, (_, index) => Math.round((maxQuantity.value * index) / 4)));
const maximumStockoutValue = computed(() => Math.max(0, ...(props.stockoutSeries?.points.map((point) => point.quantity).filter((quantity): quantity is number => quantity !== null) ?? [])));
const stockoutTickInterval = computed(() => Math.max(1, Math.ceil(maximumStockoutValue.value / 4)));
const maxStockoutCount = computed(() => stockoutTickInterval.value * 4);
const stockoutTicks = computed(() => Array.from({ length: 5 }, (_, index) => stockoutTickInterval.value * index));
const xTickIndexes = computed(() => {
    if (dates.value.length <= 1) return [0];
    const count = dates.value.length <= 14 ? 7 : 6;
    return Array.from(new Set(Array.from({ length: count }, (_, index) => Math.round((index * (dates.value.length - 1)) / (count - 1)))));
});
const barWidth = computed(() => {
    const dateWidth = plotWidth / Math.max(1, dates.value.length);
    return Math.max(2, Math.min(dateWidth * 0.64, 24));
});
const chartMinWidth = computed(() => (props.displayMode === "bar" ? Math.max(680, dates.value.length * 14) : 680));
const stackedBars = computed<StackedBar[]>(() =>
    dates.value.flatMap((date, index) => {
        const values = props.series.flatMap((series) => {
            const point = series.points.find((candidate) => candidate.date === date);
            return point?.quantity === null || point === undefined ? [] : [{ series, quantity: point.quantity }];
        });
        if (values.length === 0) return [];

        const total = values.reduce((sum, value) => sum + value.quantity, 0);
        const entries = values.map((value) => ({ ...value, percentage: total === 0 ? 0 : (value.quantity / total) * 100 }));
        let cumulative = 0;
        const segments = values.flatMap((value) => {
            const segmentBottom = cumulative;
            cumulative += value.quantity;
            if (value.quantity === 0) return [];
            return [{ series: value.series, y: pointY(cumulative), height: pointY(segmentBottom) - pointY(cumulative) }];
        });

        return [{ date, index, total, entries, segments }];
    }),
);
const tooltipColumnCount = computed(() => {
    if (props.stockoutSeries) return 1;
    if (hovered.value?.totalQuantity === null || !hovered.value) return 1;
    const columnsThatFit = Math.max(1, Math.floor((window.innerWidth - 16) / 165));
    return Math.min(3, columnsThatFit, Math.ceil(hovered.value.entries.length / 12));
});
const tooltipWidth = computed(() => (Array.isArray(hovered.value?.stockoutSkus) ? 340 : props.stockoutSeries ? 220 : tooltipColumnCount.value === 1 ? 190 : tooltipColumnCount.value * 165));

function pointX(index: number): number {
    if (props.displayMode === "bar") return plot.left + ((index + 0.5) / Math.max(1, dates.value.length)) * plotWidth;
    return dates.value.length <= 1 ? plot.left : plot.left + (index / (dates.value.length - 1)) * plotWidth;
}

function pointY(quantity: number): number {
    return plot.top + plotHeight - (quantity / maxQuantity.value) * plotHeight;
}

function stockoutY(quantity: number): number {
    return plot.top + plotHeight - (quantity / maxStockoutCount.value) * plotHeight;
}

function barX(dateIndex: number): number {
    return pointX(dateIndex) - barWidth.value / 2;
}

function barTargetY(total: number): number {
    return total === 0 ? plot.top + plotHeight - 10 : pointY(total);
}

function barTargetHeight(total: number): number {
    return Math.max(10, plot.top + plotHeight - pointY(total));
}

function lineSegments(item: TrendSeries): LineSegment[] {
    const segments: LineSegment[] = [];
    let current: string[] = [];
    let previousAvailable: { x: number; y: number } | null = null;
    let hasGap = false;

    item.points.forEach((point, index) => {
        if (point.quantity === null) {
            if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });
            current = [];
            if (previousAvailable) hasGap = true;
            return;
        }

        const available = { x: pointX(index), y: pointY(point.quantity) };
        if (hasGap && previousAvailable) {
            segments.push({ points: `${previousAvailable.x},${previousAvailable.y} ${available.x},${available.y}`, dashed: true });
        }
        current.push(`${available.x},${available.y}`);
        previousAvailable = available;
        hasGap = false;
    });
    if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });

    return segments;
}

function availablePoints(item: TrendSeries): { point: TrendPoint & { quantity: number }; index: number }[] {
    return item.points.flatMap((point, index) => (point.quantity === null ? [] : [{ point: point as TrendPoint & { quantity: number }, index }]));
}

function stockoutLineSegments(): LineSegment[] {
    const segments: LineSegment[] = [];
    let current: string[] = [];
    let previousAvailable: { x: number; y: number } | null = null;
    let hasGap = false;

    props.stockoutSeries?.points.forEach((point, index) => {
        if (point.quantity === null) {
            if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });
            current = [];
            if (previousAvailable) hasGap = true;
            return;
        }

        const available = { x: pointX(index), y: stockoutY(point.quantity) };
        if (hasGap && previousAvailable) {
            segments.push({ points: `${previousAvailable.x},${previousAvailable.y} ${available.x},${available.y}`, dashed: true });
        }
        current.push(`${available.x},${available.y}`);
        previousAvailable = available;
        hasGap = false;
    });
    if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });

    return segments;
}

function availableStockoutPoints(): { point: TrendPoint & { quantity: number }; index: number }[] {
    return props.stockoutSeries?.points.flatMap((point, index) => (point.quantity === null ? [] : [{ point: point as TrendPoint & { quantity: number }, index }])) ?? [];
}

function formatDate(date: string): string {
    const [, month, day] = date.split("-");
    return `${Number(month)}/${Number(day)}`;
}

function tooltipAnchor(event: Event, fallbackX: number, fallbackY: number): { x: number; y: number; usesViewportCoordinates: boolean } {
    const rect = (event.currentTarget as Element).getBoundingClientRect();
    if (rect.width > 0 || rect.height > 0) {
        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2, usesViewportCoordinates: true };
    }
    return { x: fallbackX, y: fallbackY, usesViewportCoordinates: false };
}

function showTooltip(item: TrendSeries, point: TrendPoint, index: number, event: Event) {
    if (point.quantity === null) return;
    const entries = props.series.flatMap((series) => {
        const matchingPoint = series.points.find((candidate) => candidate.date === point.date);
        return matchingPoint?.quantity === null || matchingPoint === undefined ? [] : [{ series, quantity: matchingPoint.quantity }];
    });
    const x = pointX(index);
    const y = pointY(point.quantity);
    const anchor = tooltipAnchor(event, x, y);
    hovered.value = {
        date: point.date,
        entries,
        activeColor: item.color,
        x,
        y,
        totalQuantity: props.stockoutSeries ? entries.reduce((total, entry) => total + entry.quantity, 0) : null,
        stockoutCount: props.stockoutSeries?.points.find((candidate) => candidate.date === point.date)?.quantity ?? null,
        stockoutSkus: null,
        periodStockoutDays: null,
        tooltipX: anchor.x,
        tooltipY: anchor.y,
        usesViewportCoordinates: anchor.usesViewportCoordinates,
    };
}

function showBarTooltip(bar: StackedBar, event: Event) {
    const x = pointX(bar.index);
    const y = bar.total === 0 ? plot.top + plotHeight : pointY(bar.total);
    const anchor = tooltipAnchor(event, x, y);
    hovered.value = {
        date: bar.date,
        entries: bar.entries,
        activeColor: bar.entries[0]?.series.color ?? "#64748b",
        x,
        y,
        totalQuantity: bar.total,
        stockoutCount: props.stockoutSeries?.points.find((point) => point.date === bar.date)?.quantity ?? null,
        stockoutSkus: null,
        periodStockoutDays: null,
        tooltipX: anchor.x,
        tooltipY: anchor.y,
        usesViewportCoordinates: anchor.usesViewportCoordinates,
    };
}

function showStockoutTooltip(point: TrendPoint, index: number, event: Event) {
    if (point.quantity === null) return;
    const entries = props.series.flatMap((series) => {
        const matchingPoint = series.points.find((candidate) => candidate.date === point.date);
        return matchingPoint?.quantity === null || matchingPoint === undefined ? [] : [{ series, quantity: matchingPoint.quantity }];
    });
    const stockoutPoint = props.stockoutSeries?.points.find((candidate) => candidate.date === point.date);
    const x = pointX(index);
    const y = stockoutY(point.quantity);
    const anchor = tooltipAnchor(event, x, y);
    hovered.value = {
        date: point.date,
        entries,
        activeColor: props.stockoutSeries?.color ?? "#e11d48",
        x,
        y,
        totalQuantity: entries.reduce((total, entry) => total + entry.quantity, 0),
        stockoutCount: point.quantity,
        stockoutSkus: stockoutPoint?.skus ?? [],
        periodStockoutDays: stockoutPoint?.periodStockoutDays ?? null,
        tooltipX: anchor.x,
        tooltipY: anchor.y,
        usesViewportCoordinates: anchor.usesViewportCoordinates,
    };
}

function formatPercentage(percentage: number): string {
    return `${Number(percentage.toFixed(1))}%`;
}

function tooltipTransform(x: number, y: number, usesViewportCoordinates: boolean): string {
    const boundaryWidth = usesViewportCoordinates ? window.innerWidth : width;
    const boundaryHeight = usesViewportCoordinates ? window.innerHeight : height;
    const topBoundary = usesViewportCoordinates ? 0 : plot.top;
    const availableHeight = usesViewportCoordinates ? boundaryHeight : plotHeight;
    const horizontal = usesViewportCoordinates ? "0" : x > boundaryWidth * 0.68 ? "calc(-100% - 14px)" : "14px";
    const vertical = y > topBoundary + availableHeight * 0.7 ? "-100%" : y < topBoundary + availableHeight * 0.3 ? "0" : "-50%";
    return `translate(${horizontal}, ${vertical})`;
}

function tooltipLeft(x: number, usesViewportCoordinates: boolean): number {
    if (!usesViewportCoordinates) return x;
    const rightPosition = x + 14;
    if (rightPosition + tooltipWidth.value <= window.innerWidth - 8) return rightPosition;
    const leftPosition = x - tooltipWidth.value - 14;
    if (leftPosition >= 8) return leftPosition;
    return Math.max(8, Math.min(window.innerWidth - tooltipWidth.value - 8, x - tooltipWidth.value / 2));
}
</script>

<template>
    <div class="relative" :style="{ minWidth: `${chartMinWidth}px` }">
        <svg
            class="block w-full"
            :viewBox="`0 0 ${width} ${height}`"
            role="img"
            :aria-label="
                stockoutSeries ? `品番の日次在庫推移と欠品SKU数（${displayMode === 'line' ? '折れ線' : '棒'}グラフ）` : `SKU別の日次在庫推移（${displayMode === 'line' ? '折れ線' : '棒'}グラフ）`
            "
            aria-describedby="inventory-chart-description"
        >
            <desc id="inventory-chart-description">{{ stockoutSeries ? "品番の日別総在庫数と欠品SKU数を表すグラフです。" : "選択されたSKUの日別在庫数を表すグラフです。" }}</desc>

            <g v-for="tick in yTicks" :key="tick">
                <line :x1="plot.left" :x2="width - plot.right" :y1="pointY(tick)" :y2="pointY(tick)" stroke="#e2e8f0" stroke-width="1" />
                <text :x="plot.left - 12" :y="pointY(tick) + 4" text-anchor="end" class="fill-slate-400 text-[11px]">{{ tick }}</text>
            </g>

            <g v-if="stockoutSeries">
                <text :x="width - plot.right + 12" :y="plot.top - 7" text-anchor="start" class="fill-rose-500 text-[10px]">欠品SKU</text>
                <text
                    v-for="tick in stockoutTicks"
                    :key="`stockout-${tick}`"
                    data-stockout-axis-tick
                    :x="width - plot.right + 12"
                    :y="stockoutY(tick) + 4"
                    text-anchor="start"
                    class="fill-rose-500 text-[11px]"
                >
                    {{ tick }}
                </text>
            </g>

            <line :x1="plot.left" :x2="width - plot.right" :y1="plot.top + plotHeight" :y2="plot.top + plotHeight" stroke="#cbd5e1" stroke-width="1" />

            <g v-for="index in xTickIndexes" :key="dates[index]">
                <line :x1="pointX(index)" :x2="pointX(index)" :y1="plot.top + plotHeight" :y2="plot.top + plotHeight + 5" stroke="#94a3b8" />
                <text :x="pointX(index)" :y="height - 17" text-anchor="middle" class="fill-slate-400 text-[11px]">{{ formatDate(dates[index]!) }}</text>
            </g>

            <template v-if="displayMode === 'line'">
                <g v-for="item in series" :key="item.skuCode">
                    <polyline
                        v-for="(segment, segmentIndex) in lineSegments(item)"
                        :key="`${item.skuCode}-line-${segmentIndex}`"
                        :points="segment.points"
                        :data-line-style="segment.dashed ? 'dashed' : 'solid'"
                        fill="none"
                        :stroke="item.color"
                        stroke-width="1.5"
                        :stroke-dasharray="segment.dashed ? '3 5' : undefined"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <circle
                        v-for="entry in availablePoints(item)"
                        :key="`marker-${item.skuCode}-${entry.point.date}`"
                        data-chart-point
                        :cx="pointX(entry.index)"
                        :cy="pointY(entry.point.quantity)"
                        r="2.5"
                        :fill="item.color"
                        stroke="transparent"
                        stroke-width="1"
                        pointer-events="none"
                    />
                    <circle
                        v-for="entry in availablePoints(item)"
                        :key="`target-${item.skuCode}-${entry.point.date}`"
                        :cx="pointX(entry.index)"
                        :cy="pointY(entry.point.quantity)"
                        r="10"
                        fill="transparent"
                        tabindex="0"
                        :aria-label="`${item.label}、${entry.point.date}、在庫${entry.point.quantity}点`"
                        @mouseenter="showTooltip(item, entry.point, entry.index, $event)"
                        @mouseleave="hovered = null"
                        @focus="showTooltip(item, entry.point, entry.index, $event)"
                        @blur="hovered = null"
                    />
                </g>
            </template>
            <template v-else>
                <g v-for="bar in stackedBars" :key="bar.date">
                    <rect
                        v-for="segment in bar.segments"
                        :key="`${bar.date}-${segment.series.skuCode}`"
                        data-chart-bar-segment
                        :x="barX(bar.index)"
                        :y="segment.y"
                        :width="barWidth"
                        :height="segment.height"
                        :fill="segment.series.color"
                        pointer-events="none"
                    />
                    <rect
                        data-chart-bar
                        :x="barX(bar.index)"
                        :y="barTargetY(bar.total)"
                        :width="barWidth"
                        :height="barTargetHeight(bar.total)"
                        fill="transparent"
                        tabindex="0"
                        :aria-label="`${bar.date}、在庫合計${bar.total}点`"
                        @mouseenter="showBarTooltip(bar, $event)"
                        @mouseleave="hovered = null"
                        @focus="showBarTooltip(bar, $event)"
                        @blur="hovered = null"
                    />
                </g>
            </template>

            <g v-if="stockoutSeries">
                <polyline
                    v-for="(segment, segmentIndex) in stockoutLineSegments()"
                    :key="`stockout-line-${segmentIndex}`"
                    :points="segment.points"
                    data-stockout-line
                    :data-line-style="segment.dashed ? 'dashed' : 'solid'"
                    fill="none"
                    :stroke="stockoutSeries.color"
                    stroke-width="2"
                    :stroke-dasharray="segment.dashed ? '3 5' : undefined"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <circle
                    v-for="entry in availableStockoutPoints()"
                    :key="`stockout-marker-${entry.point.date}`"
                    data-stockout-point
                    :cx="pointX(entry.index)"
                    :cy="stockoutY(entry.point.quantity)"
                    r="3"
                    :fill="stockoutSeries.color"
                    stroke="white"
                    stroke-width="1.5"
                    pointer-events="none"
                />
                <circle
                    v-for="entry in availableStockoutPoints()"
                    :key="`stockout-target-${entry.point.date}`"
                    :cx="pointX(entry.index)"
                    :cy="stockoutY(entry.point.quantity)"
                    r="10"
                    fill="transparent"
                    tabindex="0"
                    :aria-label="`${entry.point.date}、欠品SKU ${entry.point.quantity}件`"
                    @mouseenter="showStockoutTooltip(entry.point, entry.index, $event)"
                    @mouseleave="hovered = null"
                    @focus="showStockoutTooltip(entry.point, entry.index, $event)"
                    @blur="hovered = null"
                />
            </g>

            <line
                v-if="hovered && displayMode === 'line'"
                :x1="hovered.x"
                :x2="hovered.x"
                :y1="plot.top"
                :y2="plot.top + plotHeight"
                stroke="#94a3b8"
                stroke-width="1"
                stroke-dasharray="4 4"
                pointer-events="none"
            />
            <circle v-if="hovered && displayMode === 'line'" :cx="hovered.x" :cy="hovered.y" r="5" fill="white" :stroke="hovered.activeColor" stroke-width="3" pointer-events="none" />
        </svg>

        <Transition name="chart-tooltip">
            <div
                v-if="hovered"
                data-chart-tooltip
                class="pointer-events-none fixed z-50 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg"
                :style="{
                    left: `${tooltipLeft(hovered.tooltipX, hovered.usesViewportCoordinates)}px`,
                    top: `${hovered.tooltipY}px`,
                    transform: tooltipTransform(hovered.tooltipX, hovered.tooltipY, hovered.usesViewportCoordinates),
                    width: `${tooltipWidth}px`,
                    maxWidth: 'none',
                    whiteSpace: 'nowrap',
                }"
            >
                <p class="flex items-center justify-between gap-4 text-slate-500">
                    <span>{{ hovered.date.replaceAll("-", "/") }}</span>
                    <span v-if="hovered.totalQuantity !== null && !stockoutSeries" class="font-medium text-slate-700">合計 {{ hovered.totalQuantity }}点</span>
                </p>
                <div v-if="stockoutSeries" class="mt-2 space-y-1.5">
                    <p data-tooltip-total class="flex items-center justify-between gap-5 font-medium text-slate-700">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary-600"></span>総在庫数</span>
                        <span class="text-sm font-semibold text-slate-900"
                            >{{ hovered.totalQuantity ?? "—" }}<span v-if="hovered.totalQuantity !== null" class="ml-0.5 text-xs font-normal text-slate-500">点</span></span
                        >
                    </p>
                    <p data-tooltip-stockout class="flex items-center justify-between gap-5 font-medium text-slate-700">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: stockoutSeries.color }"></span>{{ stockoutSeries.label }}</span>
                        <span class="text-sm font-semibold text-slate-900"
                            >{{ hovered.stockoutCount ?? "—" }}<span v-if="hovered.stockoutCount !== null" class="ml-0.5 text-xs font-normal text-slate-500">SKU</span></span
                        >
                    </p>
                    <div v-if="hovered.stockoutSkus !== null" class="mt-2 border-t border-slate-200 pt-2" data-tooltip-stockout-details>
                        <p class="mb-1.5 font-medium text-slate-500">この日の欠品SKU</p>
                        <div v-if="hovered.stockoutSkus.length > 0" class="max-h-40 space-y-1 overflow-y-auto pr-1">
                            <p v-for="sku in hovered.stockoutSkus" :key="sku.skuCode" data-tooltip-stockout-sku class="flex items-center justify-between gap-4 text-slate-700">
                                <span class="truncate font-medium" :title="sku.skuCode">{{ sku.skuCode }}</span>
                                <span class="shrink-0 text-slate-500"
                                    >期間累計 <span class="font-semibold text-slate-800">{{ sku.stockoutDays }}日</span></span
                                >
                            </p>
                        </div>
                        <p v-else class="text-slate-500">欠品SKUはありません</p>
                        <p
                            v-if="hovered.periodStockoutDays !== null"
                            data-tooltip-period-stockout-days
                            class="mt-2 flex items-center justify-between gap-4 border-t border-slate-100 pt-2 font-medium text-slate-600"
                        >
                            <span>全SKUの期間累計欠品日数</span>
                            <span class="font-semibold text-slate-900">{{ hovered.periodStockoutDays }}日</span>
                        </p>
                    </div>
                </div>
                <div v-else class="mt-1 grid gap-x-4 gap-y-1" :style="{ gridTemplateColumns: `repeat(${tooltipColumnCount}, minmax(0, 1fr))` }">
                    <p v-for="entry in hovered.entries" :key="entry.series.skuCode" data-tooltip-series class="flex items-center justify-between gap-4 font-medium text-slate-700">
                        <span class="flex shrink-0 items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: entry.series.color }"></span>{{ entry.series.label }}</span>
                        <span class="shrink-0 text-sm font-semibold text-slate-900">
                            {{ entry.quantity }}<span class="ml-0.5 text-xs font-normal text-slate-500">点</span>
                            <span v-if="entry.percentage !== undefined" class="ml-2 text-xs font-normal text-slate-500">{{ formatPercentage(entry.percentage) }}</span>
                        </span>
                    </p>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.chart-tooltip-enter-active {
    transition: opacity 140ms ease-out;
}

.chart-tooltip-leave-active {
    transition: opacity 90ms ease-in;
}

.chart-tooltip-enter-from,
.chart-tooltip-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .chart-tooltip-enter-active,
    .chart-tooltip-leave-active {
        transition-duration: 0.01ms;
    }
}
</style>
