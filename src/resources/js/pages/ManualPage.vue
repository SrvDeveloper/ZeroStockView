<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from "vue";
import ManualPager from "@/components/manual/ManualPager.vue";
import ManualRelatedLinks from "@/components/manual/ManualRelatedLinks.vue";
import ManualSection from "@/components/manual/ManualSection.vue";
import ManualTableOfContents from "@/components/manual/ManualTableOfContents.vue";
import AmazonInventorySection from "@/components/manual/sections/AmazonInventorySection.vue";
import ApiSettingsSection from "@/components/manual/sections/ApiSettingsSection.vue";
import BossFilesSection from "@/components/manual/sections/BossFilesSection.vue";
import DataImportSection from "@/components/manual/sections/DataImportSection.vue";
import EcStockFilesSection from "@/components/manual/sections/EcStockFilesSection.vue";
import FileManagementSection from "@/components/manual/sections/FileManagementSection.vue";
import FreeStockFilesSection from "@/components/manual/sections/FreeStockFilesSection.vue";
import ImportSettingsSection from "@/components/manual/sections/ImportSettingsSection.vue";
import IntroductionSection from "@/components/manual/sections/IntroductionSection.vue";
import InventoryTrendSection from "@/components/manual/sections/InventoryTrendSection.vue";
import NotesSection from "@/components/manual/sections/NotesSection.vue";
import SurveyHistorySection from "@/components/manual/sections/SurveyHistorySection.vue";
import SurveyResultSection from "@/components/manual/sections/SurveyResultSection.vue";
import UserManagementSection from "@/components/manual/sections/UserManagementSection.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { DEFAULT_MANUAL_SECTION_ID, findManualSection, manualChapterOf } from "@/constants/manual";
import { canAccess } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";

const props = defineProps<{ section?: string }>();

const SECTION_COMPONENTS: Record<string, Component> = {
    introduction: IntroductionSection,
    "basic-operation-boss": BossFilesSection,
    "basic-operation-ec": EcStockFilesSection,
    "basic-operation-free": FreeStockFilesSection,
    "data-import": DataImportSection,
    "basic-operation-amazon": AmazonInventorySection,
    "survey-history": SurveyHistorySection,
    "survey-result": SurveyResultSection,
    "inventory-trend": InventoryTrendSection,
    "import-settings": ImportSettingsSection,
    "file-management": FileManagementSection,
    "api-settings": ApiSettingsSection,
    "user-management": UserManagementSection,
    notes: NotesSection,
};

/** 存在しない節はルーターで /manual に戻すので、ここでは既定の節を表示するだけにする */
const current = computed(() => findManualSection(props.section) ?? findManualSection(DEFAULT_MANUAL_SECTION_ID)!);
const chapter = computed(() => manualChapterOf(current.value.id));
const auth = useAuthStore();
/** この節で説明している画面。開けない権限のときは出さない */
const screen = computed(() => {
    const target = current.value.screen;
    return target && canAccess(auth.role, target.roles) ? target : null;
});

/* ── いま読んでいる小見出し（「このページの内容」で強調する） ── */
/** 画面上部（ヘッダーの下）を過ぎた最後の小見出しを、いま読んでいる小見出しとする */
const ACTIVE_OFFSET_PX = 120;
const activeHeadingId = ref("");
let frame = 0;

function updateActiveHeading() {
    frame = 0;
    const headings = current.value.headings;
    let active = headings[0]?.id ?? "";
    for (const heading of headings) {
        const element = document.getElementById(heading.id);
        if (element && element.getBoundingClientRect().top <= ACTIVE_OFFSET_PX) active = heading.id;
    }
    // 最後の小見出しは画面の上まで届かないことがあるので、ページの下端まで来たら最後の小見出しとする
    const scrolledToBottom = window.scrollY > 0 && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
    if (scrolledToBottom && headings.length > 0) active = headings[headings.length - 1]!.id;
    activeHeadingId.value = active;
}

function onScroll() {
    if (frame === 0) frame = window.requestAnimationFrame(updateActiveHeading);
}

function jumpToHeading(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
    window.history.replaceState(window.history.state, "", `#${id}`);
    activeHeadingId.value = id;
}

/* ── スマホの目次 ── */
const mobileTocOpen = ref(false);

function jumpFromMobileToc(id: string) {
    mobileTocOpen.value = false;
    jumpToHeading(id);
}

watch(
    () => current.value.id,
    async () => {
        mobileTocOpen.value = false;
        await nextTick();
        updateActiveHeading();
    },
);

onMounted(() => {
    window.addEventListener("scroll", onScroll, { passive: true });
    updateActiveHeading();
});

onBeforeUnmount(() => {
    window.removeEventListener("scroll", onScroll);
    if (frame !== 0) window.cancelAnimationFrame(frame);
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- スマホ・タブレット：いまの節を示すバー。押すと目次が開く -->
        <div class="sticky top-12 z-20 lg:hidden" data-mobile-toc>
            <button
                type="button"
                class="flex min-h-11 w-full items-center gap-2 rounded-card border border-line bg-surface px-4 text-left text-[13px] shadow-card"
                :aria-expanded="mobileTocOpen"
                aria-controls="manual-mobile-toc"
                @click="mobileTocOpen = !mobileTocOpen"
            >
                <span v-if="chapter" class="shrink-0 text-muted">{{ chapter }}</span>
                <AppIcon v-if="chapter" name="chevron_right" :size="16" class="shrink-0 text-faint" />
                <span class="min-w-0 truncate font-semibold text-ink">{{ current.shortTitle }}</span>
                <span class="ml-auto flex shrink-0 items-center gap-0.5 rounded-control border border-line-strong px-2 py-0.5 text-[12px] font-semibold text-ink-2"
                    >目次<AppIcon :name="mobileTocOpen ? 'expand_less' : 'expand_more'" :size="16"
                /></span>
            </button>
            <div v-if="mobileTocOpen" id="manual-mobile-toc" class="mt-2 max-h-[70vh] overflow-y-auto rounded-card shadow-float">
                <ManualTableOfContents :current-id="current.id" :headings="current.headings" :active-heading-id="activeHeadingId" @navigate="mobileTocOpen = false" @jump="jumpFromMobileToc" />
            </div>
        </div>

        <div class="grid items-start gap-5 lg:grid-cols-[14rem_minmax(0,760px)] 2xl:grid-cols-[14rem_minmax(0,760px)_13rem]">
            <div class="hidden lg:sticky lg:top-16 lg:block">
                <ManualTableOfContents :current-id="current.id" :headings="current.headings" :active-heading-id="activeHeadingId" @jump="jumpToHeading" />
            </div>

            <article class="flex min-w-0 flex-col gap-5">
                <ManualSection :id="current.id" :key="current.id" :title="current.title" :description="current.description" :chapter="chapter" :audience="current.audience" :points="current.points">
                    <component :is="SECTION_COMPONENTS[current.id]" />
                </ManualSection>
                <div class="flex flex-col gap-3 rounded-card border border-line bg-surface px-4 py-3.5 shadow-card sm:flex-row sm:items-center sm:justify-between 2xl:hidden">
                    <ManualRelatedLinks :ids="current.related" />
                    <RouterLink
                        v-if="screen"
                        :to="{ name: screen.routeName }"
                        class="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 rounded-control bg-ink px-4 text-[13px] font-bold text-white hover:bg-ink-2"
                        data-open-screen
                        >{{ screen.label }}<AppIcon name="arrow_forward" :size="16"
                    /></RouterLink>
                </div>
                <ManualPager :current-id="current.id" />
            </article>

            <aside class="hidden 2xl:sticky 2xl:top-16 2xl:flex 2xl:flex-col 2xl:gap-5" aria-label="このページの内容と関連する節">
                <nav v-if="current.headings.length > 0" aria-label="このページの内容" class="flex flex-col" data-page-outline>
                    <p class="pb-1.5 text-[11px] font-semibold text-muted">このページの内容</p>
                    <a
                        v-for="heading in current.headings"
                        :key="heading.id"
                        :href="`#${heading.id}`"
                        class="border-l-2 py-1 pl-3 text-[12.5px] leading-snug transition-colors"
                        :class="activeHeadingId === heading.id ? 'border-ink font-semibold text-ink' : 'border-line-strong text-ink-2 hover:text-ink'"
                        :aria-current="activeHeadingId === heading.id ? 'location' : undefined"
                        @click.prevent="jumpToHeading(heading.id)"
                        >{{ heading.title }}</a
                    >
                </nav>
                <RouterLink
                    v-if="screen"
                    :to="{ name: screen.routeName }"
                    class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-control bg-ink px-3 text-[13px] font-bold text-white hover:bg-ink-2"
                    data-open-screen
                    >{{ screen.label }}<AppIcon name="arrow_forward" :size="16"
                /></RouterLink>
                <ManualRelatedLinks :ids="current.related" />
            </aside>
        </div>
    </div>
</template>
