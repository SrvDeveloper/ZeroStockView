<script setup lang="ts">
defineProps<{ number: number }>();
</script>

<template>
    <!-- 図の中の番号の印。図の下の説明と同じ番号を付ける -->
    <span
        class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-signal px-1 font-num text-[13px] leading-none font-semibold text-ink shadow-[0_0_0_2px_var(--color-surface)]"
        aria-hidden="true"
        data-figure-marker
        >{{ number }}</span
    >
</template>
