<script setup lang="ts">
import { computed, useId } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

const props = withDefaults(
    defineProps<{
        modelValue: string;
        label?: string;
        type?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        error?: string;
        hint?: string;
        size?: "sm" | "md";
        autocomplete?: string;
        bordered?: boolean;
        clearable?: boolean;
        clearLabel?: string;
    }>(),
    {
        label: "",
        type: "text",
        placeholder: "",
        required: false,
        disabled: false,
        error: "",
        hint: "",
        size: "md",
        autocomplete: "off",
        bordered: true,
        clearable: false,
        clearLabel: "入力内容をクリア",
    },
);

const emit = defineEmits<{ "update:modelValue": [value: string] }>();
const inputId = useId();
const showClearButton = computed(() => props.clearable && !props.disabled && props.modelValue.length > 0);

const inputClass = computed(() => [
    "w-full rounded-control border pt-0 pb-px text-ink placeholder:text-faint transition-colors disabled:bg-surface-2 disabled:text-muted",
    props.size === "sm" ? "h-[26px] text-xs" : "h-8 text-[12.5px]",
    props.bordered ? (showClearButton.value ? (props.size === "sm" ? "pr-8 pl-2" : "pr-9 pl-3") : props.size === "sm" ? "px-2" : "px-3") : "",
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
        <label v-if="label" :for="inputId" class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-ink-2">
            {{ label }}
            <span v-if="required" class="rounded-chip bg-danger-soft px-1 py-px text-[10px] font-semibold text-danger">必須</span>
        </label>
        <div class="relative">
            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :autocomplete="autocomplete"
                :class="inputClass"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
            <button
                v-if="showClearButton"
                type="button"
                class="absolute inset-y-0 right-1 flex items-center rounded-chip px-1.5 text-faint transition-colors hover:text-ink-2 hover:cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ink-2"
                :aria-label="clearLabel"
                @mousedown.prevent
                @click="emit('update:modelValue', '')"
            >
                <AppIcon name="close" :size="16" />
            </button>
        </div>
        <p v-if="error" class="mt-1 text-[11px] text-danger">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-[11px] text-muted">{{ hint }}</p>
    </div>
</template>
