<script setup lang="ts">
defineProps<{
    number: number | string;
    title: string;
}>();
</script>

<template>
    <div class="flex gap-4">
        <span class="flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full bg-primary-600 px-2 text-sm font-semibold text-white" aria-hidden="true">
            {{ number }}
        </span>
        <div class="min-w-0 flex-1 pt-0.5">
            <h3 class="text-sm font-semibold text-slate-900">{{ title }}</h3>
            <div class="mt-2 space-y-3 text-sm leading-6 text-slate-600">
                <slot />
            </div>
        </div>
    </div>
</template>
