<script setup lang="ts">
defineProps<{ modelValue: boolean; label?: string; description?: string }>();
const emit = defineEmits<{ "update:modelValue": [value: boolean] }>();
</script>

<template>
    <label class="flex cursor-pointer items-center gap-3">
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            class="relative mt-0.5 h-[22px] w-[38px] shrink-0 rounded-full shadow-[inset_0_1px_2px_rgb(0_0_0/0.25)] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
            :class="modelValue ? 'bg-ink' : 'bg-line-stronger'"
            @click="emit('update:modelValue', !modelValue)"
        >
            <span
                class="absolute top-[3px] left-[3px] h-4 w-4 rounded-full bg-white shadow-[0_1px_2px_rgb(0_0_0/0.3)] transition-transform"
                :class="modelValue ? 'translate-x-4' : 'translate-x-0'"
            ></span>
        </button>
        <span v-if="label || description">
            <span class="block text-[12.5px] font-semibold text-ink">{{ label }}</span>
            <span v-if="description" class="mt-0.5 block text-[11px] text-muted">{{ description }}</span>
        </span>
    </label>
</template>
