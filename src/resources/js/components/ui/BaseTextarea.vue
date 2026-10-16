<script setup lang="ts">
import { computed, useId } from "vue";

const props = withDefaults(defineProps<{ modelValue: string; label?: string; placeholder?: string; rows?: number; error?: string; hint?: string; bordered?: boolean }>(), {
    label: "",
    placeholder: "",
    rows: 4,
    error: "",
    hint: "",
    bordered: true,
});

const emit = defineEmits<{ "update:modelValue": [value: string] }>();
const areaId = useId();

const areaClass = computed(() => [
    "w-full rounded-control border py-2 text-[12.5px] text-ink placeholder:text-faint transition-colors",
    props.bordered ? "px-3" : "",
    props.bordered ? "bg-surface shadow-field" : "bg-transparent hover:bg-tint focus:bg-surface",
    props.error
        ? "border-danger ring-3 ring-danger/12 focus:outline-none"
        : props.bordered
          ? "border-line-strong focus:border-ink-2 focus:outline-none focus:ring-3 focus:ring-ink/10"
          : "border-transparent focus:border-line-strong focus:outline-none",
]);
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="areaId" class="mb-1 block text-[11px] font-medium text-ink-2">{{ label }}</label>
        <textarea
            :id="areaId"
            :value="modelValue"
            :rows="rows"
            :placeholder="placeholder"
            :class="areaClass"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        ></textarea>
        <p v-if="error" class="mt-1 text-[11px] text-danger">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-[11px] text-muted">{{ hint }}</p>
    </div>
</template>
