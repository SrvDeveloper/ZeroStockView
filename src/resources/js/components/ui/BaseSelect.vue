<script setup lang="ts">
import { computed, useId } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import type { SelectOption } from "@/types";

const props = withDefaults(
    defineProps<{
        modelValue: string | number | null;
        options: SelectOption[];
        label?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        error?: string;
        size?: "sm" | "md";
        showPlaceholder?: boolean;
    }>(),
    { label: "", placeholder: "選択してください", required: false, disabled: false, error: "", size: "md", showPlaceholder: true },
);

const emit = defineEmits<{ "update:modelValue": [value: string | number | null] }>();
const selectId = useId();

const selectClass = computed(() => [
    "w-full appearance-none rounded-control border bg-surface pr-8 text-ink shadow-field transition-colors focus:outline-none disabled:bg-surface-2 disabled:text-muted",
    props.size === "sm" ? "h-[26px] pl-2 text-xs" : "h-8 pl-2.5 text-[12.5px]",
    props.error ? "border-danger ring-3 ring-danger/12" : "border-line-strong focus:border-ink-2 focus:ring-3 focus:ring-ink/10",
    props.modelValue === null || props.modelValue === "" ? "text-muted" : "",
]);

function onChange(event: Event) {
    const raw = (event.target as HTMLSelectElement).value;
    if (raw === "") {
        emit("update:modelValue", null);
        return;
    }
    const matched = props.options.find((option) => String(option.value) === raw);
    emit("update:modelValue", matched ? matched.value : raw);
}
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="selectId" class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-ink-2">
            {{ label }}
            <span v-if="required" class="rounded-chip bg-danger-soft px-1 py-px text-[10px] font-semibold text-danger">必須</span>
        </label>
        <div class="relative">
            <select :id="selectId" :value="modelValue === null ? '' : String(modelValue)" :disabled="disabled" :class="selectClass" @change="onChange">
                <option v-if="showPlaceholder" value="">{{ placeholder }}</option>
                <option v-for="option in options" :key="String(option.value)" :value="String(option.value)" class="text-ink">{{ option.label }}</option>
            </select>
            <AppIcon name="expand_more" :size="16" class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-muted" />
        </div>
        <p v-if="error" class="mt-1 text-[11px] text-danger">{{ error }}</p>
    </div>
</template>
