<script setup lang="ts">
defineProps<{
    number: number | string;
    title: string;
}>();
</script>

<template>
    <!-- 次の兄弟も手順のときだけ、番号の下から次の番号まで線をつなぐ -->
    <div class="relative flex gap-[18px] [&:has(+[data-manual-step])_[data-step-line]]:block" data-manual-step>
        <span class="relative z-1 flex h-8 min-w-8 shrink-0 items-center justify-center rounded-full bg-ink px-1.5 font-num text-[18px] font-semibold text-white" aria-hidden="true">
            {{ number }}
        </span>
        <span class="absolute top-10 -bottom-7 left-[15px] hidden w-0.5 rounded-full bg-ink/12" aria-hidden="true" data-step-line></span>
        <div class="min-w-0 flex-1 pt-1">
            <h3 class="text-[17px] leading-snug font-bold text-ink">{{ title }}</h3>
            <div class="mt-2.5 space-y-3">
                <slot />
            </div>
        </div>
    </div>
</template>
