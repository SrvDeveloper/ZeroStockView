<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import { NAV_ITEMS } from "@/constants/navigation";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const mobileOpen = ref(false);
const userMenuOpen = ref(false);

function isActive(name: string): boolean {
    return route.name === name || (name === "survey-history" && route.name === "survey-result");
}

async function logout() {
    userMenuOpen.value = false;
    mobileOpen.value = false;
    await auth.logout();
    window.location.assign(router.resolve({ name: "login" }).href);
}
</script>

<template>
    <nav class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between px-4 py-2.5 sm:px-6 lg:px-8">
            <RouterLink :to="{ name: 'data-import' }" class="flex items-center gap-2">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-600 text-white">
                    <AppIcon name="counter_0" :size="20" filled />
                </span>
                <span class="flex flex-col leading-tight">
                    <span class="text-base font-semibold text-slate-900">ZeroStockView</span>
                    <span class="text-xs text-slate-500">ゼロ在庫照会システム</span>
                </span>
            </RouterLink>

            <button
                type="button"
                class="inline-flex items-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:ring-2 focus:ring-slate-200 focus:outline-none lg:hidden"
                @click="mobileOpen = !mobileOpen"
            >
                <span class="sr-only">メニューを開く</span>
                <AppIcon :name="mobileOpen ? 'close' : 'menu'" :size="24" />
            </button>

            <div class="hidden items-center gap-1 lg:flex">
                <RouterLink
                    v-for="item in NAV_ITEMS"
                    :key="item.name"
                    :to="{ name: item.name }"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="isActive(item.name) ? 'bg-primary-50 text-primary-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
                >
                    {{ item.label }}
                </RouterLink>
            </div>

            <div class="relative hidden lg:block">
                <button type="button" class="flex items-center gap-0.5 rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" aria-label="アカウントメニュー" @click="userMenuOpen = !userMenuOpen">
                    <AppIcon name="account_circle" :size="28" />
                    <AppIcon name="expand_more" :size="18" />
                </button>
                <div v-if="userMenuOpen" class="absolute right-0 z-20 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg" @click="userMenuOpen = false">
                    <RouterLink :to="{ name: 'password-change' }" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-100"> パスワード変更 </RouterLink>
                    <button type="button" class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50" @click="logout"> ログアウト </button>
                </div>
            </div>
        </div>

        <div v-if="mobileOpen" class="mx-auto w-full max-w-7xl border-t border-slate-200 px-4 pt-2 pb-3 sm:px-6 lg:hidden">
            <RouterLink
                v-for="item in NAV_ITEMS"
                :key="item.name"
                :to="{ name: item.name }"
                class="block rounded-lg px-3 py-2 text-sm font-medium"
                :class="isActive(item.name) ? 'bg-primary-50 text-primary-700' : 'text-slate-600 hover:bg-slate-100'"
                @click="mobileOpen = false"
            >
                {{ item.label }}
            </RouterLink>
            <RouterLink
                :to="{ name: 'password-change' }"
                class="block rounded-lg px-3 py-2 text-sm font-medium"
                :class="isActive('password-change') ? 'bg-primary-50 text-primary-700' : 'text-slate-600 hover:bg-slate-100'"
                @click="mobileOpen = false"
            >
                パスワード変更
            </RouterLink>
            <button type="button" class="mt-1 block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-600 hover:bg-rose-50" @click="logout"> ログアウト </button>
        </div>
    </nav>
</template>
