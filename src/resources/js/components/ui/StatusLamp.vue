<script setup lang="ts">
export type LampState = "ok" | "warn" | "error";

withDefaults(defineProps<{ state: LampState; label: string; detail?: string }>(), { detail: "" });

const dotClass: Record<LampState, string> = {
    ok: "bg-lamp-ok shadow-[0_0_0_3px_rgb(31_157_85/0.18)]",
    warn: "bg-lamp-warn shadow-[0_0_0_3px_rgb(217_158_0/0.2)]",
    error: "bg-lamp-error shadow-[0_0_0_3px_rgb(210_58_47/0.18)]",
};
</script>

<template>
    <span class="inline-flex items-center gap-[7px] whitespace-nowrap" :data-lamp-state="state">
        <span class="h-[7px] w-[7px] shrink-0 rounded-full" :class="dotClass[state]" aria-hidden="true"></span>
        <span>{{ label }}</span>
        <span v-if="detail" class="font-mono text-[10.5px] opacity-70">{{ detail }}</span>
    </span>
</template>
