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
    "w-full rounded-lg border py-2 text-sm text-slate-900 placeholder:text-slate-400 transition-colors",
    props.bordered ? "px-3" : "",
    props.bordered ? "bg-white" : "bg-transparent focus:bg-white",
    props.error
        ? "border-rose-400 focus:outline focus:outline-2 focus:outline-rose-500"
        : props.bordered
          ? "border-slate-300 focus:border-primary-500 focus:outline focus:outline-2 focus:outline-primary-500/40"
          : "border-transparent focus:outline-none",
]);
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="areaId" class="mb-1 block text-xs font-medium text-slate-600">{{ label }}</label>
        <textarea
            :id="areaId"
            :value="modelValue"
            :rows="rows"
            :placeholder="placeholder"
            :class="areaClass"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        ></textarea>
        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
