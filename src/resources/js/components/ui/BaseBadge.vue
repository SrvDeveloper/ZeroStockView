<script setup lang="ts">
import { computed } from "vue";

type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

const props = withDefaults(defineProps<{ tone?: Tone; mono?: boolean }>(), { tone: "neutral", mono: false });

const toneClass: Record<Tone, string> = {
    neutral: "bg-tint text-ink-2",
    brand: "bg-ink text-white",
    success: "bg-ok-soft text-ok-ink",
    warning: "bg-warn-soft text-caution",
    danger: "bg-danger-soft text-danger",
};

const classes = computed(() => ["inline-flex items-center rounded-chip px-2 py-0.5 text-[11px] leading-snug font-semibold whitespace-nowrap", toneClass[props.tone], props.mono ? "font-mono" : ""]);
</script>

<template>
    <span :class="classes"><slot /></span>
</template>
