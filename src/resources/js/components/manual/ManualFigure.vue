<script setup lang="ts">
import ManualFigureMarker from "@/components/manual/ManualFigureMarker.vue";

/**
 * 画面の一部を簡略化して描いた図。実物の部品は使わず、押す場所は黄色の枠、説明する場所は番号の印で示す。
 * legend を渡すと、図の下に番号ごとの説明を並べる。
 */
withDefaults(
    defineProps<{
        /** 図の内容を読み上げる説明 */
        label: string;
        caption?: string;
        legend?: string[];
    }>(),
    { caption: "", legend: () => [] },
);
</script>

<template>
    <figure class="overflow-hidden rounded-card border border-line bg-surface-2" data-manual-figure>
        <div class="overflow-x-auto p-3.5 sm:p-4">
            <div role="img" :aria-label="label" class="pointer-events-none text-[12.5px] leading-normal text-ink select-none">
                <slot />
            </div>
        </div>
        <figcaption class="border-t border-line bg-surface px-4 py-2.5 text-[12.5px] leading-[1.7] text-muted">
            <ol v-if="legend.length > 0" class="mb-1 flex flex-col gap-1 text-[13px] text-ink" data-figure-legend>
                <li v-for="(item, index) in legend" :key="item" class="flex items-start gap-2">
                    <ManualFigureMarker :number="index + 1" class="mt-0.5" />
                    <span>{{ item }}</span>
                </li>
            </ol>
            <p v-if="caption">{{ caption }}</p>
        </figcaption>
    </figure>
</template>
