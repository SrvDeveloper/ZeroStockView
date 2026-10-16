<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";
import { useUiStore } from "@/stores/ui";

const ui = useUiStore();

const toneClass = {
    success: "text-lamp-ok",
    error: "text-[#ff8a80]",
    info: "text-faint",
} as const;

const iconName = { success: "check_circle", error: "error", info: "info" } as const;
</script>

<template>
    <div class="pointer-events-none fixed top-4 right-4 z-60 flex w-80 flex-col gap-2">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-4 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-x-4 opacity-0"
        >
            <div v-for="toast in ui.toasts" :key="toast.id" class="pointer-events-auto flex items-center gap-2.5 rounded-card bg-[#161b20] px-3.5 py-3 text-sidebar-strong shadow-float">
                <AppIcon :name="iconName[toast.type]" :size="17" :class="toneClass[toast.type]" />
                <p class="flex-1 text-[12.5px] leading-relaxed">{{ toast.message }}</p>
                <button type="button" class="text-sidebar-dim transition-colors hover:text-white" aria-label="閉じる" @click="ui.dismiss(toast.id)"><AppIcon name="close" :size="14" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
