<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

type Variant = "primary" | "secondary" | "ghost" | "danger" | "danger-ghost";
type Size = "sm" | "md";

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        size?: Size;
        type?: "button" | "submit";
        icon?: string;
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    { variant: "secondary", size: "md", type: "button", icon: "", disabled: false, loading: false, block: false },
);

const variantClass: Record<Variant, string> = {
    primary: "bg-graphite font-semibold text-white border-graphite-edge shadow-graphite hover:cursor-pointer hover:brightness-125 focus-visible:outline-ink",
    secondary: "bg-surface text-ink border-line-strong shadow-raised hover:cursor-pointer hover:bg-surface-2 focus-visible:outline-ink-2",
    ghost: "bg-transparent text-ink-2 border-transparent hover:cursor-pointer hover:bg-tint hover:text-ink focus-visible:outline-ink-2",
    danger: "bg-danger font-semibold text-white border-danger shadow-raised hover:cursor-pointer hover:brightness-110 focus-visible:outline-danger",
    "danger-ghost": "bg-surface text-danger border-danger/30 hover:cursor-pointer hover:bg-danger-soft focus-visible:outline-danger",
};

const sizeClass: Record<Size, string> = {
    sm: "h-[26px] rounded-chip px-2.5 text-xs gap-1.5",
    md: "h-8 rounded-control px-3.5 text-[12.5px] gap-1.5",
};

const classes = computed(() => [
    "inline-flex items-center justify-center whitespace-nowrap border font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-45 disabled:shadow-none disabled:brightness-100",
    variantClass[props.variant],
    sizeClass[props.size],
    props.block ? "w-full" : "",
]);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading">
        <AppIcon v-if="loading" name="refresh" :size="size === 'sm' ? 14 : 16" class="animate-spin" />
        <AppIcon v-else-if="icon" :name="icon" :size="size === 'sm' ? 14 : 16" />
        <span v-if="$slots.default"><slot /></span>
    </button>
</template>
