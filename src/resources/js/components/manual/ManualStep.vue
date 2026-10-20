<script setup lang="ts">
defineProps<{
    number: number | string;
    title: string;
}>();
</script>

<template>
    <div class="flex gap-4">
        <span class="flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full bg-ink px-1.5 font-num text-base font-semibold text-white" aria-hidden="true">
            {{ number }}
        </span>
        <div class="min-w-0 flex-1 pt-0.5">
            <h3 class="text-[15px] leading-snug font-bold text-ink">{{ title }}</h3>
            <div class="mt-2 space-y-3 text-[14px] leading-relaxed text-ink">
                <slot />
            </div>
        </div>
    </div>
</template>
