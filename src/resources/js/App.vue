<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute } from "vue-router";
import Navbar from "@/components/Navbar.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseToasts from "@/components/ui/BaseToasts.vue";
import { isPageLoading } from "@/router/loading";

const route = useRoute();
const showChrome = computed(() => route.name !== undefined && route.name !== "login");
const showLoadingOverlay = ref(false);
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
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <a href="#main-content" class="skip-link">本文へ移動</a>
        <BaseToasts />
        <Navbar v-if="showChrome" />
        <main id="main-content" :aria-busy="isPageLoading" :class="showChrome ? 'mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8' : ''">
            <router-view />
        </main>

        <div v-if="isPageLoading" class="fixed inset-x-0 top-0 z-70 h-1 overflow-hidden bg-primary-100" role="progressbar" aria-label="画面を読み込んでいます">
            <div class="route-loading-bar h-full w-2/5 bg-primary-600"></div>
        </div>

        <Transition name="route-loading-fade">
            <div v-if="showLoadingOverlay" class="fixed inset-0 z-60 flex items-center justify-center bg-slate-50/80 backdrop-blur-[1px]" role="status" aria-live="polite">
                <div class="flex items-center gap-3 rounded-xl bg-white px-6 py-4 text-sm font-medium text-slate-700 shadow-xs">
                    <AppIcon name="progress_activity" :size="24" class="animate-spin text-primary-500 motion-reduce:animate-none" aria-hidden="true" />
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
