<script setup lang="ts">
import type { IconName } from "@/constants/icons";
import AppIcon from "@/components/ui/AppIcon.vue";

withDefaults(defineProps<{ icon?: IconName; title: string; description?: string }>(), { icon: "inventory_2", description: "" });
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="flex h-11 w-11 items-center justify-center rounded-card bg-tint text-faint"><AppIcon :name="icon" :size="22" /></div>
        <p class="mt-3 text-[13px] font-medium text-ink-2">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-md text-[11.5px] text-muted">{{ description }}</p>
        <div class="mt-4"><slot /></div>
    </div>
</template>
