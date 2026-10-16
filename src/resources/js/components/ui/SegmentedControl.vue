<script setup lang="ts" generic="T extends string">
withDefaults(defineProps<{ modelValue: T; options: { value: T; label: string }[]; label: string; size?: "sm" | "md" }>(), { size: "md" });

const emit = defineEmits<{ "update:modelValue": [value: T] }>();
</script>

<template>
    <div class="inline-flex gap-0.5 rounded-control bg-tint p-0.5" role="radiogroup" :aria-label="label">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === option.value"
            :data-value="option.value"
            class="rounded-chip whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ink-2"
            :class="[
                size === 'sm' ? 'px-2.5 py-0.5 text-[11px]' : 'px-3 py-[3px] text-[11.5px]',
                modelValue === option.value ? 'bg-surface font-semibold text-ink shadow-[0_1px_2px_rgb(16_24_32/0.12),0_0_0_1px_rgb(17_22_26/0.04)]' : 'text-ink-2 hover:cursor-pointer hover:text-ink',
            ]"
            @click="emit('update:modelValue', option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
