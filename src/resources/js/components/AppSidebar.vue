<script setup lang="ts">
import { useRoute } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import { isNavItemActive, NAV_GROUPS } from "@/constants/navigation";

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const route = useRoute();
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="emit('close')"></div>
    <aside
        id="app-sidebar"
        class="fixed inset-y-0 left-0 z-50 flex w-[212px] flex-col bg-sidebar px-2.5 pt-[18px] pb-3 text-sidebar-text transition-transform lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        aria-label="メインメニュー"
    >
        <span class="absolute inset-x-0 top-0 h-1 bg-hazard" aria-hidden="true"></span>
        <div class="flex items-center justify-between gap-2 px-2 pt-1 pb-4">
            <RouterLink :to="{ name: 'data-import' }" class="flex items-center gap-2.5 rounded-control focus-visible:outline-2 focus-visible:outline-signal" @click="emit('close')">
                <span class="flex h-7 w-7 items-center justify-center rounded-panel bg-signal font-num text-[17px] font-bold text-ink shadow-[inset_0_-2px_0_rgb(0_0_0/0.12)]">0</span>
                <span class="flex flex-col leading-tight">
                    <span class="text-[13.5px] font-semibold text-sidebar-strong">ZeroStockView</span>
                    <span class="text-[10px] text-sidebar-dim">在庫状況調査</span>
                </span>
            </RouterLink>
            <button type="button" class="rounded-chip p-1 text-sidebar-dim hover:text-white lg:hidden" aria-label="メニューを閉じる" @click="emit('close')">
                <AppIcon name="close" :size="20" />
            </button>
        </div>

        <nav class="flex flex-col gap-0.5 overflow-y-auto" aria-label="画面の切り替え">
            <template v-for="group in NAV_GROUPS" :key="group.label">
                <p class="px-2.5 pt-3.5 pb-1 text-[10.5px] font-medium text-sidebar-dim">{{ group.label }}</p>
                <RouterLink
                    v-for="item in group.items"
                    :key="item.name"
                    :to="{ name: item.name }"
                    :data-nav-item="item.name"
                    :aria-current="isNavItemActive(item, route.name) ? 'page' : undefined"
                    class="relative flex items-center gap-2.5 rounded-control px-2.5 py-[7px] text-[12.5px] transition-colors focus-visible:outline-2 focus-visible:outline-signal"
                    :class="isNavItemActive(item, route.name) ? 'bg-white/8 font-semibold text-white shadow-[inset_0_0_0_1px_rgb(255_255_255/0.05)]' : 'hover:bg-white/5 hover:text-sidebar-strong'"
                    @click="emit('close')"
                >
                    <span v-if="isNavItemActive(item, route.name)" class="absolute top-[9px] bottom-[9px] -left-2.5 w-[3px] rounded-r-sm bg-signal" aria-hidden="true"></span>
                    <AppIcon :name="item.icon" :size="18" :class="isNavItemActive(item, route.name) ? 'text-signal' : 'text-sidebar-icon'" />
                    <span>{{ item.label }}</span>
                </RouterLink>
            </template>
        </nav>

        <div class="mt-auto pt-4"><slot name="footer" /></div>
    </aside>
</template>
