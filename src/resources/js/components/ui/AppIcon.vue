<script setup lang="ts">
import type { IconName } from "@/constants/icons";
import { computed } from "vue";

const props = withDefaults(defineProps<{ name: IconName; size?: number | string; filled?: boolean }>(), { size: 20, filled: false });

const iconSize = computed(() => (typeof props.size === "number" ? `${props.size}px` : props.size));
</script>

<template>
    <span
        class="material-symbols-outlined shrink-0"
        :style="{
            fontSize: iconSize,
            fontVariationSettings: `'FILL' ${filled ? 1 : 0}, 'wght' 400, 'GRAD' 0, 'opsz' 24`,
        }"
        aria-hidden="true"
        >{{ name }}</span
    >
</template>
