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

const props = defineProps<{
    label: string;
    points: TrendPoint[];
    color: string;
}>();

const width = 280;
const height = 112;
const plot = { left: 8, right: 8, top: 8, bottom: 24 };
const plotWidth = width - plot.left - plot.right;
const plotHeight = height - plot.top - plot.bottom;

const availablePoints = computed(() => props.points.flatMap((point, index) => (point.quantity === null ? [] : [{ point, index }])));
const maxQuantity = computed(() => Math.max(1, ...availablePoints.value.map(({ point }) => point.quantity ?? 0)));
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
    return props.points.length <= 1 ? plot.left : plot.left + (index / (props.points.length - 1)) * plotWidth;
}

function pointY(quantity: number): number {
    return plot.top + plotHeight - (quantity / maxQuantity.value) * plotHeight;
}

function areaPoints(segment: LineSegment): string {
    const points = segment.points.split(" ");
    const firstX = points[0]?.split(",")[0] ?? plot.left;
    const lastX = points.at(-1)?.split(",")[0] ?? width - plot.right;
    const baseline = plot.top + plotHeight;
    return `${firstX},${baseline} ${segment.points} ${lastX},${baseline}`;
}

function formatDate(date: string): string {
    const [, month, day] = date.split("-");
    return month && day ? `${Number(month)}/${Number(day)}` : date;
}
</script>

<template>
    <svg data-sku-mini-chart class="block w-full" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="`${label}の日次在庫推移`">
        <desc>{{ label }}の選択期間における日別在庫数を表すグラフです。</desc>

        <line :x1="plot.left" :x2="width - plot.right" :y1="plot.top + plotHeight" :y2="plot.top + plotHeight" stroke="rgb(17 22 26 / 0.3)" stroke-width="1" />
        <line :x1="plot.left" :x2="width - plot.right" :y1="plot.top + plotHeight / 2" :y2="plot.top + plotHeight / 2" stroke="rgb(17 22 26 / 0.1)" stroke-width="1" stroke-dasharray="2 4" />

        <polygon v-for="(segment, index) in lineSegments.filter((item) => !item.dashed)" :key="`area-${index}`" data-mini-chart-area :points="areaPoints(segment)" :fill="color" fill-opacity="0.08" />

        <polyline
            v-for="(segment, index) in lineSegments"
            :key="index"
            data-mini-chart-line
            :data-line-style="segment.dashed ? 'dashed' : 'solid'"
            :points="segment.points"
            fill="none"
            :stroke="color"
            stroke-width="2"
            :stroke-dasharray="segment.dashed ? '3 5' : undefined"
            stroke-linecap="round"
            stroke-linejoin="round"
        />

        <circle
            v-for="entry in availablePoints"
            :key="entry.point.date"
            data-mini-chart-point
            :cx="pointX(entry.index)"
            :cy="pointY(entry.point.quantity ?? 0)"
            :r="entry.point.quantity === 0 ? 3 : 1.75"
            :fill="entry.point.quantity === 0 ? '#f5c400' : color"
            :stroke="entry.point.quantity === 0 ? '#11161a' : undefined"
            :stroke-width="entry.point.quantity === 0 ? 1 : undefined"
        >
            <title>{{ entry.point.date.replaceAll("-", "/") }} 在庫{{ entry.point.quantity }}点</title>
        </circle>

        <text :x="plot.left" :y="height - 5" text-anchor="start" class="fill-muted font-mono text-[10px]">{{ formatDate(firstDate) }}</text>
        <text :x="width - plot.right" :y="height - 5" text-anchor="end" class="fill-muted font-mono text-[10px]">{{ formatDate(lastDate) }}</text>
    </svg>
</template>
