<script setup lang="ts">
import { computed } from "vue";

interface TrendPoint {
    date: string;
    quantity: number | null;
}

interface LineSegment {
    points: string;
    dashed: boolean;
}

/** card: SKU別のカード用（日付と点の説明つき）。spark: 表の「推移」列用の小さな折れ線 */
type Variant = "card" | "spark";

const props = withDefaults(defineProps<{ label: string; points: TrendPoint[]; variant?: Variant }>(), { variant: "card" });

const INK = "#11161a";
/** テストの data 属性はカードにだけ付ける（表の折れ線と数を分けるため） */
const isCard = computed(() => props.variant === "card");
const SIGNAL = "#f5c400";

const layout = computed(() =>
    props.variant === "spark" ? { width: 120, height: 26, plot: { left: 3, right: 3, top: 3, bottom: 3 } } : { width: 280, height: 112, plot: { left: 8, right: 8, top: 8, bottom: 24 } },
);
const plotWidth = computed(() => layout.value.width - layout.value.plot.left - layout.value.plot.right);
const plotHeight = computed(() => layout.value.height - layout.value.plot.top - layout.value.plot.bottom);
const baseline = computed(() => layout.value.plot.top + plotHeight.value);

const availablePoints = computed(() => props.points.flatMap((point, index) => (point.quantity === null ? [] : [{ point, index }])));
const maxQuantity = computed(() => Math.max(1, ...availablePoints.value.map(({ point }) => point.quantity ?? 0)));
/** 終点は最後に値がある日。最新の値が 0 なら黄色の丸にする */
const endPoint = computed(() => availablePoints.value.at(-1) ?? null);
const firstDate = computed(() => props.points[0]?.date ?? "");
const lastDate = computed(() => props.points.at(-1)?.date ?? "");
const lineSegments = computed<LineSegment[]>(() => {
    const segments: LineSegment[] = [];
    let current: string[] = [];
    let previousAvailable: { x: number; y: number } | null = null;
    let hasGap = false;

    props.points.forEach((point, index) => {
        if (point.quantity === null) {
            if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });
            current = [];
            if (previousAvailable) hasGap = true;
            return;
        }

        const available = { x: pointX(index), y: pointY(point.quantity) };
        if (hasGap && previousAvailable) {
            segments.push({ points: `${previousAvailable.x},${previousAvailable.y} ${available.x},${available.y}`, dashed: true });
            hasGap = false;
        }
        current.push(`${available.x},${available.y}`);
        previousAvailable = available;
    });

    if (current.length > 1) segments.push({ points: current.join(" "), dashed: false });
    return segments;
});

function pointX(index: number): number {
    const { left } = layout.value.plot;
    return props.points.length <= 1 ? left : left + (index / (props.points.length - 1)) * plotWidth.value;
}

function pointY(quantity: number): number {
    return baseline.value - (quantity / maxQuantity.value) * plotHeight.value;
}

function areaPoints(segment: LineSegment): string {
    const points = segment.points.split(" ");
    const firstX = points[0]?.split(",")[0] ?? layout.value.plot.left;
    const lastX = points.at(-1)?.split(",")[0] ?? layout.value.width - layout.value.plot.right;
    return `${firstX},${baseline.value} ${segment.points} ${lastX},${baseline.value}`;
}

function formatDate(date: string): string {
    const [, month, day] = date.split("-");
    return month && day ? `${Number(month)}/${Number(day)}` : date;
}
</script>

<template>
    <svg
        :data-sku-mini-chart="variant === 'card' ? '' : undefined"
        :data-sku-sparkline="variant === 'spark' ? '' : undefined"
        :class="variant === 'spark' ? 'block' : 'block w-full'"
        :width="variant === 'spark' ? layout.width : undefined"
        :height="variant === 'spark' ? layout.height : undefined"
        :viewBox="`0 0 ${layout.width} ${layout.height}`"
        :role="variant === 'spark' ? undefined : 'img'"
        :aria-hidden="variant === 'spark' ? 'true' : undefined"
        :aria-label="variant === 'spark' ? undefined : `${label}の日次在庫推移`"
    >
        <template v-if="variant === 'card'">
            <desc>{{ label }}の選択期間における日別在庫数を表すグラフです。</desc>
            <line :x1="layout.plot.left" :x2="layout.width - layout.plot.right" :y1="baseline" :y2="baseline" stroke="rgb(17 22 26 / 0.3)" stroke-width="1" />
            <line
                :x1="layout.plot.left"
                :x2="layout.width - layout.plot.right"
                :y1="layout.plot.top + plotHeight / 2"
                :y2="layout.plot.top + plotHeight / 2"
                stroke="rgb(17 22 26 / 0.1)"
                stroke-width="1"
                stroke-dasharray="2 4"
            />
        </template>

        <polygon
            v-for="(segment, index) in lineSegments.filter((item) => !item.dashed)"
            :key="`area-${index}`"
            :data-mini-chart-area="isCard ? '' : undefined"
            :points="areaPoints(segment)"
            :fill="INK"
            fill-opacity="0.07"
        />

        <polyline
            v-for="(segment, index) in lineSegments"
            :key="index"
            :data-mini-chart-line="isCard ? '' : undefined"
            :data-line-style="segment.dashed ? 'dashed' : 'solid'"
            :points="segment.points"
            fill="none"
            :stroke="INK"
            stroke-width="1.5"
            :stroke-dasharray="segment.dashed ? '3 5' : undefined"
            stroke-linecap="round"
            stroke-linejoin="round"
        />

        <circle
            v-if="endPoint"
            :data-mini-chart-end="isCard ? '' : undefined"
            :data-zero="endPoint.point.quantity === 0 ? 'true' : undefined"
            :cx="pointX(endPoint.index)"
            :cy="pointY(endPoint.point.quantity ?? 0)"
            r="2.8"
            :fill="endPoint.point.quantity === 0 ? SIGNAL : INK"
            :stroke="endPoint.point.quantity === 0 ? INK : undefined"
            :stroke-width="endPoint.point.quantity === 0 ? 1 : undefined"
        />

        <template v-if="variant === 'card'">
            <circle v-for="entry in availablePoints" :key="entry.point.date" data-mini-chart-point :cx="pointX(entry.index)" :cy="pointY(entry.point.quantity ?? 0)" r="5" fill="transparent">
                <title>{{ entry.point.date.replaceAll("-", "/") }} 在庫{{ entry.point.quantity }}点</title>
            </circle>
            <text :x="layout.plot.left" :y="layout.height - 5" text-anchor="start" class="fill-muted font-mono text-[10px]">{{ formatDate(firstDate) }}</text>
            <text :x="layout.width - layout.plot.right" :y="layout.height - 5" text-anchor="end" class="fill-muted font-mono text-[10px]">{{ formatDate(lastDate) }}</text>
        </template>
    </svg>
</template>
