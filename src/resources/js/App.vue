<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute } from "vue-router";
import AppSidebar from "@/components/AppSidebar.vue";
import AppTopBar from "@/components/AppTopBar.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseToasts from "@/components/ui/BaseToasts.vue";
import { isPageLoading } from "@/router/loading";

const route = useRoute();
const showChrome = computed(() => route.name !== undefined && route.name !== "login");
const showLoadingOverlay = ref(false);
const sidebarOpen = ref(false);
let overlayTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    isPageLoading,
    (loading) => {
        if (overlayTimer !== undefined) {
            clearTimeout(overlayTimer);
            overlayTimer = undefined;
        }

        if (loading) {
            overlayTimer = setTimeout(() => {
                showLoadingOverlay.value = true;
                overlayTimer = undefined;
            }, 120);
        } else {
            showLoadingOverlay.value = false;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (overlayTimer !== undefined) clearTimeout(overlayTimer);
});
</script>

<template>
    <div class="min-h-screen bg-canvas bg-blueprint text-ink">
        <a href="#main-content" class="skip-link">本文へ移動</a>
        <BaseToasts />
        <div v-if="showChrome" class="min-h-screen lg:grid lg:grid-cols-[212px_minmax(0,1fr)]">
            <AppSidebar :open="sidebarOpen" @close="sidebarOpen = false" />
            <div class="flex min-w-0 flex-col">
                <AppTopBar :menu-open="sidebarOpen" @open-menu="sidebarOpen = true" />
                <main id="main-content" :aria-busy="isPageLoading" class="w-full px-4 pt-5 pb-8 sm:px-7 sm:pt-[22px]">
                    <router-view />
                </main>
            </div>
        </div>
        <main v-else id="main-content" :aria-busy="isPageLoading">
            <router-view />
        </main>

        <div v-if="isPageLoading" class="fixed inset-x-0 top-0 z-70 h-[3px] overflow-hidden bg-ink/10" role="progressbar" aria-label="画面を読み込んでいます">
            <div class="route-loading-bar h-full w-2/5 bg-ink"></div>
        </div>

        <Transition name="route-loading-fade">
            <div v-if="showLoadingOverlay" class="fixed inset-0 z-60 flex items-center justify-center bg-canvas/70 backdrop-blur-[1px]" role="status" aria-live="polite">
                <div class="flex items-center gap-3 rounded-card border border-line bg-surface px-6 py-4 text-[13px] font-medium text-ink-2 shadow-card">
                    <AppIcon name="progress_activity" :size="24" class="animate-spin text-ink-2 motion-reduce:animate-none" aria-hidden="true" />
                    <span>読み込み中...</span>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.route-loading-bar {
    animation: route-loading-progress 1.1s ease-in-out infinite;
    transform-origin: left;
}

.route-loading-fade-enter-active,
.route-loading-fade-leave-active {
    transition: opacity 120ms ease;
}

.route-loading-fade-enter-from,
.route-loading-fade-leave-to {
    opacity: 0;
}

@keyframes route-loading-progress {
    0% {
        transform: translateX(-100%) scaleX(0.5);
    }
    50% {
        transform: translateX(100%) scaleX(1);
    }
    100% {
        transform: translateX(300%) scaleX(0.5);
    }
}

@media (prefers-reduced-motion: reduce) {
    .route-loading-bar {
        animation-duration: 2s;
    }
}
</style>
