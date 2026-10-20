<script setup lang="ts">
defineProps<{
    number: number | string;
    title: string;
}>();
</script>

<template>
    <div class="flex gap-4">
        <span class="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-chip bg-ink px-1.5 font-num text-[15px] font-semibold text-white" aria-hidden="true">
            {{ number }}
        </span>
        <div class="min-w-0 flex-1 pt-px">
            <h3 class="text-[13.5px] font-semibold text-ink">{{ title }}</h3>
            <div class="mt-2 space-y-3 text-[13px] leading-relaxed text-ink-2">
                <slot />
            </div>
        </div>
    </div>
</template>
