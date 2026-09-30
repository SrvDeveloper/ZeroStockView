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

const props = withDefaults(defineProps<{ series: TrendSeries[]; displayMode?: ChartMode }>(), { displayMode: "line" });

const width = 960;
const height = 360;
const plot = { left: 56, right: 24, top: 20, bottom: 48 };
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
    if (hovered.value?.totalQuantity === null || !hovered.value) return 1;
    const columnsThatFit = Math.max(1, Math.floor((window.innerWidth - 16) / 165));
    return Math.min(3, columnsThatFit, Math.ceil(hovered.value.entries.length / 12));
});
const tooltipWidth = computed(() => (tooltipColumnCount.value === 1 ? 190 : tooltipColumnCount.value * 165));

function pointX(index: number): number {
    if (props.displayMode === "bar") return plot.left + ((index + 0.5) / Math.max(1, dates.value.length)) * plotWidth;
    return dates.value.length <= 1 ? plot.left : plot.left + (index / (dates.value.length - 1)) * plotWidth;
}

function pointY(quantity: number): number {
    return plot.top + plotHeight - (quantity / maxQuantity.value) * plotHeight;
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
        const matchingPoint = series.points.find((candidate) => candidate.date === point.date && candidate.quantity === point.quantity);
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
        totalQuantity: null,
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
            :aria-label="displayMode === 'line' ? 'SKU別の日次在庫推移（折れ線グラフ）' : 'SKU別の日次在庫推移（棒グラフ）'"
            aria-describedby="inventory-chart-description"
        >
            <desc id="inventory-chart-description">選択されたSKUごとの日別在庫数を表すグラフです。</desc>

            <g v-for="tick in yTicks" :key="tick">
                <line :x1="plot.left" :x2="width - plot.right" :y1="pointY(tick)" :y2="pointY(tick)" stroke="#e2e8f0" stroke-width="1" />
                <text :x="plot.left - 12" :y="pointY(tick) + 4" text-anchor="end" class="fill-slate-400 text-[11px]">{{ tick }}</text>
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
                    <span v-if="hovered.totalQuantity !== null" class="font-medium text-slate-700">合計 {{ hovered.totalQuantity }}点</span>
                </p>
                <div class="mt-1 grid gap-x-4 gap-y-1" :style="{ gridTemplateColumns: `repeat(${tooltipColumnCount}, minmax(0, 1fr))` }">
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
