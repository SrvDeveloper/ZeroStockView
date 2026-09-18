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
    "w-full rounded-lg border pt-0 pb-0.5 text-slate-900 placeholder:text-slate-400 transition-colors disabled:bg-slate-100 disabled:text-slate-500",
    props.size === "sm" ? "h-8 text-xs" : "h-10 text-sm",
    props.bordered ? (showClearButton.value ? (props.size === "sm" ? "pr-8 pl-2" : "pr-9 pl-3") : props.size === "sm" ? "px-2" : "px-3") : "",
    props.bordered ? "bg-white" : "bg-transparent focus:bg-white",
    props.error
        ? "border-rose-400 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-rose-500"
        : props.bordered
          ? "border-slate-300 focus:border-primary-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-primary-500/40"
          : "border-transparent focus:outline-none",
]);
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="inputId" class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
            {{ label }}
            <span v-if="required" class="rounded bg-rose-50 px-1 py-px text-[10px] font-semibold text-rose-600">必須</span>
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
                class="absolute inset-y-0 right-1 flex items-center rounded-md px-1.5 text-slate-400 transition-colors hover:text-slate-600 hover:cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary-500"
                :aria-label="clearLabel"
                @mousedown.prevent
                @click="emit('update:modelValue', '')"
            >
                <AppIcon name="close" :size="18" />
            </button>
        </div>
        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
