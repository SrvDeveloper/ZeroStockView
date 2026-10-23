<script setup lang="ts">
import { computed, ref, useId } from "vue";

interface TrendPoint {
    date: string;
    quantity: number | null;
}

/** 積み上げる 1 段（倉庫）。配列の先頭が棒のいちばん下になる */
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

interface BarSegment {
    key: string;
    color: string;
    /** いちばん上の段は上端を角丸にした path で描く */
    path: string | null;
    y: number;
    height: number;
}

interface StackedBar {
    date: string;
    index: number;
    total: number;
    /** 未確定の段がある日。確定した段だけを積み、上に「未確定」の印を付ける */
    pending: boolean;
    segments: BarSegment[];
}

interface BreakdownEntry {
    series: TrendSeries;
    quantity: number | null;
}

interface HoveredBar {
    date: string;
    index: number;
    pending: boolean;
    totalQuantity: number | null;
    breakdown: BreakdownEntry[];
    stockout: StockoutPoint | null;
    x: number;
    y: number;
}

const props = withDefaults(
    defineProps<{
        series: TrendSeries[];
        stockoutSeries?: StockoutSeries | null;
        pendingDates?: string[];
        /** 段（skuCode） => 日付 => ツールチップでその段に添える注記（例: 履歴から再現） */
        seriesNotes?: Record<string, Record<string, string>>;
    }>(),
    {
        stockoutSeries: null,
        pendingDates: () => [],
        seriesNotes: () => ({}),
    },
);

const width = 960;
const height = 300;
const plot = { left: 48, right: 12, top: 12, bottom: 58 };
const plotWidth = width - plot.left - plot.right;
const plotHeight = height - plot.top - plot.bottom;
const plotBottom = plot.top + plotHeight;
/** 毎日の日付の目盛りを出す最長の日数 */
const DAILY_TICK_LIMIT = 31;
const SEGMENT_GAP = 2;
const TOP_RADIUS = 3;
const PENDING_CAP_HEIGHT = 12;
const pendingPatternId = `pending-hatch-${useId()}`;
const hovered = ref<HoveredBar | null>(null);

const dates = computed(() => props.series[0]?.points.map((point) => point.date) ?? []);
const pendingDateSet = computed(() => new Set(props.pendingDates));
const stockoutByDate = computed(() => new Map(props.stockoutSeries?.points.map((point) => [point.date, point]) ?? []));
const band = computed(() => plotWidth / Math.max(1, dates.value.length));
const barWidth = computed(() => Math.max(1, Math.min(32, Math.round(band.value * 0.5))));
const segmentGap = computed(() => (barWidth.value >= 6 ? SEGMENT_GAP : 0));
const chartMinWidth = computed(() => Math.max(680, dates.value.length * 6));

function quantityOn(series: TrendSeries, date: string): number | null {
    return series.points.find((point) => point.date === date)?.quantity ?? null;
}

const maxQuantity = computed(() => {
    const totals = dates.value.map((date) => props.series.reduce((total, series) => total + (quantityOn(series, date) ?? 0), 0));
    return Math.max(20, Math.ceil(Math.max(0, ...totals) / 10) * 10);
});
const yTicks = computed(() => Array.from({ length: 5 }, (_, index) => Math.round((maxQuantity.value * index) / 4)));

const xTicks = computed(() => {
    const count = dates.value.length;
    if (count === 0) return [];
    if (count <= DAILY_TICK_LIMIT) {
        return dates.value.map((date, index) => ({ index, label: index === 0 || date.endsWith("-01") ? date.slice(5) : date.slice(8) }));
    }
    const indexes = new Set(Array.from({ length: 6 }, (_, index) => Math.round((index * (count - 1)) / 5)));
    return [...indexes].map((index) => ({ index, label: dates.value[index]!.slice(5) }));
});

const stackedBars = computed<StackedBar[]>(() =>
    dates.value.flatMap((date, index) => {
        const values = props.series.flatMap((series) => {
            const quantity = quantityOn(series, date);
            return quantity === null ? [] : [{ series, quantity }];
        });
        const pending = pendingDateSet.value.has(date);
        if (values.length === 0 && !pending) return [];

        const total = values.reduce((sum, value) => sum + value.quantity, 0);
        const visible = values.filter((value) => value.quantity > 0);
        const x = barX(index);
        let cumulative = 0;
        const segments = visible.flatMap((value, segmentIndex) => {
            const bottom = pointY(cumulative);
            cumulative += value.quantity;
            const isTop = segmentIndex === visible.length - 1;
            const top = pointY(cumulative) + (isTop ? 0 : segmentGap.value);
            const segmentHeight = bottom - top;
            if (segmentHeight <= 0) return [];
            const radius = Math.min(TOP_RADIUS, barWidth.value / 2, segmentHeight);
            return [
                {
                    key: value.series.skuCode,
                    color: value.series.color,
                    path: isTop ? roundedTopPath(x, top, barWidth.value, segmentHeight, radius) : null,
                    y: top,
                    height: segmentHeight,
                },
            ];
        });

        return [{ date, index, total, pending, segments }];
    }),
);

function columnX(index: number): number {
    return plot.left + index * band.value;
}

function barX(index: number): number {
    return columnX(index) + (band.value - barWidth.value) / 2;
}

function barCenter(index: number): number {
    return columnX(index) + band.value / 2;
}

function pointY(quantity: number): number {
    return plotBottom - (quantity / maxQuantity.value) * plotHeight;
}

function roundedTopPath(x: number, y: number, barWidth: number, barHeight: number, radius: number): string {
    const bottom = y + barHeight;
    return `M${x},${bottom} V${y + radius} Q${x},${y} ${x + radius},${y} H${x + barWidth - radius} Q${x + barWidth},${y} ${x + barWidth},${y + radius} V${bottom} Z`;
}

function pendingCapY(total: number): number {
    return pointY(total) - PENDING_CAP_HEIGHT - (total > 0 ? SEGMENT_GAP : 0);
}

function stockoutCount(date: string): number {
    return stockoutByDate.value.get(date)?.quantity ?? 0;
}

function barLabel(bar: StackedBar): string {
    const stockout = stockoutCount(bar.date);
    const total = bar.pending ? "在庫合計は未確定" : `在庫合計${bar.total}点`;
    return stockout > 0 ? `${bar.date}、${total}、欠品SKU ${stockout}件` : `${bar.date}、${total}`;
}

function showTooltip(bar: StackedBar, event: Event) {
    const rect = (event.currentTarget as Element).getBoundingClientRect();
    const usesViewport = rect.width > 0 || rect.height > 0;
    hovered.value = {
        date: bar.date,
        index: bar.index,
        pending: bar.pending,
        totalQuantity: bar.pending ? null : bar.total,
        breakdown: props.series.map((series) => ({ series, quantity: quantityOn(series, bar.date) })),
        stockout: stockoutByDate.value.get(bar.date) ?? null,
        x: usesViewport ? rect.left + rect.width / 2 : barCenter(bar.index),
        y: usesViewport ? rect.top + rect.height / 3 : pointY(bar.total),
    };
}

const tooltipWidth = computed(() => ((hovered.value?.stockout?.skus?.length ?? 0) > 0 ? 300 : 210));

function tooltipLeft(x: number): number {
    const right = x + 14;
    if (right + tooltipWidth.value <= window.innerWidth - 8) return right;
    return Math.max(8, x - tooltipWidth.value - 14);
}

function tooltipTop(y: number): number {
    return Math.max(8, Math.min(y, window.innerHeight - 280));
}
</script>

<template>
    <div class="relative" :style="{ minWidth: `${chartMinWidth}px` }">
        <svg class="block w-full" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="品番の日別在庫（倉庫別の積み上げ棒グラフ）と欠品SKU数" aria-describedby="inventory-chart-description">
            <desc id="inventory-chart-description">品番の日別在庫数を倉庫別に積み上げ、日付の下に欠品SKU数を示すグラフです。</desc>
            <defs>
                <pattern :id="pendingPatternId" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="1.5" height="4" fill="rgb(17 22 26 / 0.35)" />
                </pattern>
            </defs>

            <template v-for="tick in yTicks" :key="tick">
                <line v-if="tick > 0" :x1="plot.left" :x2="width - plot.right" :y1="pointY(tick)" :y2="pointY(tick)" stroke="rgb(17 22 26 / 0.12)" stroke-width="1" stroke-dasharray="2 4" />
                <text :x="plot.left - 8" :y="pointY(tick) + 4" text-anchor="end" class="fill-muted font-mono text-[10px]">{{ tick }}</text>
            </template>

            <rect
                v-if="hovered"
                data-chart-highlight
                :x="columnX(hovered.index) + 2"
                :y="plot.top"
                :width="Math.max(1, band - 4)"
                :height="plotHeight"
                fill="rgb(17 22 26 / 0.05)"
                pointer-events="none"
            />

            <g v-for="bar in stackedBars" :key="bar.date">
                <template v-for="segment in bar.segments" :key="`${bar.date}-${segment.key}`">
                    <path v-if="segment.path" data-chart-bar-segment :d="segment.path" :fill="segment.color" pointer-events="none" />
                    <rect v-else data-chart-bar-segment :x="barX(bar.index)" :y="segment.y" :width="barWidth" :height="segment.height" :fill="segment.color" pointer-events="none" />
                </template>
                <rect
                    v-if="bar.pending"
                    data-chart-bar-pending
                    :x="barX(bar.index) + 0.5"
                    :y="pendingCapY(bar.total)"
                    :width="Math.max(1, barWidth - 1)"
                    :height="PENDING_CAP_HEIGHT"
                    rx="2"
                    :fill="`url(#${pendingPatternId})`"
                    stroke="rgb(17 22 26 / 0.55)"
                    stroke-width="1"
                    stroke-dasharray="2 2"
                    pointer-events="none"
                />
            </g>

            <line :x1="plot.left" :x2="width - plot.right" :y1="plotBottom" :y2="plotBottom" stroke="rgb(17 22 26 / 0.35)" stroke-width="1" />

            <text v-for="tick in xTicks" :key="`date-${tick.index}`" data-chart-date :x="barCenter(tick.index)" :y="plotBottom + 16" text-anchor="middle" class="fill-muted font-mono text-[10px]">
                {{ tick.label }}
            </text>

            <template v-if="stockoutSeries">
                <text :x="plot.left - 8" :y="height - 12" text-anchor="end" class="fill-muted text-[10px]">欠品</text>
                <template v-for="(date, index) in dates" :key="`stockout-${date}`">
                    <g v-if="stockoutCount(date) > 0" data-stockout-point :data-stockout-count="stockoutCount(date)">
                        <rect :x="barCenter(index) - (band >= 18 ? 10 : 3.5)" :y="height - 19" width="7" height="7" rx="2" fill="#f5c400" />
                        <text v-if="band >= 18" :x="barCenter(index) - 1" :y="height - 12" class="fill-ink font-num text-[12px] font-semibold">{{ stockoutCount(date) }}</text>
                    </g>
                </template>
            </template>

            <rect
                v-for="bar in stackedBars"
                :key="`target-${bar.date}`"
                data-chart-bar
                :data-bar-pending="bar.pending ? 'true' : undefined"
                :x="columnX(bar.index)"
                :y="plot.top"
                :width="band"
                :height="height - plot.top"
                fill="transparent"
                tabindex="0"
                :aria-label="barLabel(bar)"
                @mouseenter="showTooltip(bar, $event)"
                @mouseleave="hovered = null"
                @focus="showTooltip(bar, $event)"
                @blur="hovered = null"
            />
        </svg>

        <Transition name="chart-tooltip">
            <div
                v-if="hovered"
                data-chart-tooltip
                class="pointer-events-none fixed z-50 rounded-card border border-line bg-surface px-3 py-2 text-[11px] whitespace-nowrap text-ink shadow-float"
                :style="{ left: `${tooltipLeft(hovered.x)}px`, top: `${tooltipTop(hovered.y)}px`, width: `${tooltipWidth}px` }"
            >
                <p class="font-mono text-[11px] font-medium">{{ hovered.date.replaceAll("-", "/") }}</p>
                <div class="mt-1 space-y-0.5" data-tooltip-breakdown>
                    <p v-for="row in hovered.breakdown" :key="row.series.skuCode" data-tooltip-breakdown-row class="flex items-center justify-between gap-4 text-ink-2">
                        <span class="flex items-center gap-1.5"
                            ><span class="h-2 w-2 rounded-xs" :style="{ backgroundColor: row.series.color }"></span>{{ row.series.label
                            }}<span v-if="seriesNotes[row.series.skuCode]?.[hovered.date]" data-tooltip-series-note class="-ml-1 text-[10px] text-muted"
                                >（{{ seriesNotes[row.series.skuCode]![hovered.date] }}）</span
                            ></span
                        >
                        <span v-if="row.quantity === null" class="text-[10.5px] text-muted">未確定</span>
                        <span v-else class="font-num text-[13px] font-semibold text-ink">{{ row.quantity }}<span class="ml-0.5 font-sans text-[10.5px] font-normal text-muted">点</span></span>
                    </p>
                </div>
                <p data-tooltip-total class="mt-1 flex items-center justify-between gap-4 border-t border-line pt-1 font-medium text-ink-2">
                    <span>総在庫数</span>
                    <span v-if="hovered.totalQuantity === null" class="text-[10.5px] font-normal text-muted">未確定</span>
                    <span v-else class="font-num text-sm font-semibold text-ink">{{ hovered.totalQuantity }}<span class="ml-0.5 font-sans text-[10.5px] font-normal text-muted">点</span></span>
                </p>
                <template v-if="stockoutSeries">
                    <p data-tooltip-stockout class="mt-0.5 flex items-center justify-between gap-4 font-medium text-ink-2">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-[2px] bg-signal"></span>{{ stockoutSeries.label }}</span>
                        <span class="font-num text-sm font-semibold text-ink"
                            >{{ hovered.stockout?.quantity ?? "—" }}<span v-if="hovered.stockout?.quantity != null" class="ml-0.5 font-sans text-[10.5px] font-normal text-muted">SKU</span></span
                        >
                    </p>
                    <div v-if="hovered.stockout?.skus?.length" class="mt-1.5 border-t border-line-2 pt-1.5" data-tooltip-stockout-details>
                        <div class="max-h-40 space-y-0.5 overflow-y-auto pr-1">
                            <p v-for="sku in hovered.stockout.skus" :key="sku.skuCode" data-tooltip-stockout-sku class="flex items-center justify-between gap-4 text-ink-2">
                                <span class="truncate font-mono font-medium uppercase" :title="sku.skuCode">{{ sku.skuCode }}</span>
                                <span class="shrink-0 text-muted"
                                    >期間累計 <span class="font-semibold text-ink">{{ sku.stockoutDays }}日</span></span
                                >
                            </p>
                        </div>
                        <p
                            v-if="hovered.stockout.periodStockoutDays !== undefined"
                            data-tooltip-period-stockout-days
                            class="mt-1.5 flex items-center justify-between gap-4 border-t border-line-2 pt-1.5 font-medium text-ink-2"
                        >
                            <span>全SKUの期間累計欠品日数</span>
                            <span class="font-semibold text-ink">{{ hovered.stockout.periodStockoutDays }}日</span>
                        </p>
                    </div>
                </template>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
[data-chart-bar]:focus {
    outline: none;
}

[data-chart-bar]:focus-visible {
    fill: rgb(17 22 26 / 0.08);
}

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
