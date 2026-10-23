<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import { findManualSection } from "@/constants/manual";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";

defineProps<{ menuOpen: boolean }>();
const emit = defineEmits<{ openMenu: [] }>();

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const surveys = useSurveysStore();
const importSettings = useImportSettingsStore();

const accountMenuOpen = ref(false);
const accountMenuRef = ref<HTMLElement | null>(null);

const dateFormatter = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit" });

function isoDate(iso: string): string {
    const parts = Object.fromEntries(dateFormatter.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
}

const breadcrumb = computed<string[]>(() => {
    const trail = [...(route.meta.breadcrumb ?? [])];
    if (route.name === "survey-result") {
        const survey = surveys.getSummary(String(route.params.id));
        trail.push(survey ? `${isoDate(survey.executedAt)} の調査結果` : "調査結果");
    }
    if (route.name === "manual") {
        const section = findManualSection(String(route.params.section ?? ""));
        if (section) trail.push(section.shortTitle);
    }
    if (route.name === "inventory-trend") {
        trail.push(String(route.params.productCode ?? ""));
    }
    if (route.name === "import-setting-edit") {
        const setting = importSettings.settings.find((item) => item.id === String(route.params.id));
        trail.push(setting?.name ?? "編集");
    }
    return trail;
});

const avatarLabel = computed(() => (auth.user?.loginId ?? "").slice(0, 2));

function onDocumentClick(event: MouseEvent) {
    if (accountMenuOpen.value && accountMenuRef.value && !accountMenuRef.value.contains(event.target as Node)) accountMenuOpen.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === "Escape") accountMenuOpen.value = false;
}

async function logout() {
    accountMenuOpen.value = false;
    await auth.logout();
    window.location.assign(router.resolve({ name: "login" }).href);
}

onMounted(() => {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener("click", onDocumentClick);
    document.removeEventListener("keydown", onKeydown);
});
</script>

<template>
    <header class="sticky top-0 z-30 flex h-11 items-center gap-3 border-b border-line bg-white/70 px-4 font-mono text-[11px] text-muted backdrop-blur-md sm:px-7">
        <button
            type="button"
            class="-ml-1 flex h-7 w-7 items-center justify-center rounded-chip text-ink-2 hover:bg-tint lg:hidden"
            aria-label="メニューを開く"
            aria-controls="app-sidebar"
            :aria-expanded="menuOpen"
            @click="emit('openMenu')"
        >
            <AppIcon name="menu" :size="20" />
        </button>
        <nav class="min-w-0 truncate" aria-label="現在地" data-breadcrumb>
            <template v-for="(item, index) in breadcrumb" :key="`${index}-${item}`">
                <span v-if="index > 0" class="mx-1.5 text-faint" aria-hidden="true">/</span>
                <span :class="index === breadcrumb.length - 1 ? 'font-medium text-ink' : ''" :aria-current="index === breadcrumb.length - 1 ? 'page' : undefined">{{ item }}</span>
            </template>
        </nav>
        <span class="flex-1"></span>
        <div ref="accountMenuRef" class="relative">
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-control py-0.5 pr-1 pl-0.5 text-ink-2 hover:bg-tint"
                aria-label="アカウントメニュー"
                aria-haspopup="menu"
                :aria-expanded="accountMenuOpen"
                @click="accountMenuOpen = !accountMenuOpen"
            >
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink font-sans text-[10px] text-white">{{ avatarLabel }}</span>
                <span class="hidden sm:inline">{{ auth.user?.loginId }}</span>
                <AppIcon name="expand_more" :size="16" />
            </button>
            <div v-if="accountMenuOpen" class="absolute right-0 z-40 mt-1.5 w-44 overflow-hidden rounded-card border border-line bg-surface py-1 font-sans shadow-float" role="menu">
                <RouterLink :to="{ name: 'password-change' }" class="block px-3.5 py-2 text-[12.5px] text-ink hover:bg-surface-2" role="menuitem" @click="accountMenuOpen = false"
                    >パスワード変更</RouterLink
                >
                <button type="button" class="block w-full px-3.5 py-2 text-left text-[12.5px] text-danger hover:bg-danger-soft" role="menuitem" @click="logout">ログアウト</button>
            </div>
        </div>
    </header>
</template>
