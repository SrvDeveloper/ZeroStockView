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

/** 表の「推移」列用の小さな折れ線。数値は同じ行の列で読めるので、読み上げからは外す */
const props = defineProps<{ points: TrendPoint[] }>();

const INK = "#11161a";
const SIGNAL = "#f5c400";
const WIDTH = 120;
const HEIGHT = 26;
const PLOT = { left: 3, right: 3, top: 3, bottom: 3 };
const plotWidth = WIDTH - PLOT.left - PLOT.right;
const plotHeight = HEIGHT - PLOT.top - PLOT.bottom;
const baseline = PLOT.top + plotHeight;

const availablePoints = computed(() => props.points.flatMap((point, index) => (point.quantity === null ? [] : [{ point, index }])));
const maxQuantity = computed(() => Math.max(1, ...availablePoints.value.map(({ point }) => point.quantity ?? 0)));
/** 終点は最後に値がある日。最新の値が 0 なら黄色の丸にする */
const endPoint = computed(() => availablePoints.value.at(-1) ?? null);
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
    return props.points.length <= 1 ? PLOT.left : PLOT.left + (index / (props.points.length - 1)) * plotWidth;
}

function pointY(quantity: number): number {
    return baseline - (quantity / maxQuantity.value) * plotHeight;
}

function areaPoints(segment: LineSegment): string {
    const points = segment.points.split(" ");
    const firstX = points[0]?.split(",")[0] ?? PLOT.left;
    const lastX = points.at(-1)?.split(",")[0] ?? WIDTH - PLOT.right;
    return `${firstX},${baseline} ${segment.points} ${lastX},${baseline}`;
}
</script>

<template>
    <svg data-sku-sparkline class="block" :width="WIDTH" :height="HEIGHT" :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" aria-hidden="true">
        <polygon v-for="(segment, index) in lineSegments.filter((item) => !item.dashed)" :key="`area-${index}`" data-spark-area :points="areaPoints(segment)" :fill="INK" fill-opacity="0.07" />

        <polyline
            v-for="(segment, index) in lineSegments"
            :key="index"
            data-spark-line
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
            data-spark-end
            :data-zero="endPoint.point.quantity === 0 ? 'true' : undefined"
            :cx="pointX(endPoint.index)"
            :cy="pointY(endPoint.point.quantity ?? 0)"
            r="2.8"
            :fill="endPoint.point.quantity === 0 ? SIGNAL : INK"
            :stroke="endPoint.point.quantity === 0 ? INK : undefined"
            :stroke-width="endPoint.point.quantity === 0 ? 1 : undefined"
        />
    </svg>
</template>
