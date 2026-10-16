<script setup lang="ts">
withDefaults(defineProps<{ title: string; value?: string | number | null; unit?: string; note?: string; alert?: boolean }>(), {
    value: null,
    unit: "",
    note: "",
    alert: false,
});
</script>

<template>
    <div class="relative flex flex-col gap-px overflow-hidden rounded-card border border-line bg-surface px-4 pt-3 shadow-card" :class="alert ? 'pb-[17px]' : 'pb-3'">
        <p class="text-[11.5px] font-semibold text-ink-2">{{ title }}</p>
        <div class="flex items-baseline gap-1.5">
            <slot name="value">
                <span class="font-num text-[36px] leading-[1.05] font-semibold tabular-nums">{{ value ?? "—" }}</span>
            </slot>
            <span v-if="unit" class="text-[11px] text-muted">{{ unit }}</span>
        </div>
        <p v-if="note || $slots.note" class="font-mono text-[10.5px] text-muted">
            <slot name="note">{{ note }}</slot>
        </p>
        <span v-if="alert" class="absolute inset-x-0 bottom-0 h-[5px] bg-hazard" aria-hidden="true"></span>
    </div>
</template>
