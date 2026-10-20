<script setup lang="ts">
import { computed, ref, useId, watch } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { searchManual, type ManualSearchResult } from "@/utils/manualSearch";

/** large は入口ページの見出し用、compact は節のページの目次の上に置く */
const props = withDefaults(defineProps<{ size?: "large" | "compact" }>(), { size: "compact" });

const emit = defineEmits<{ navigate: [] }>();

const listId = useId();
const query = ref("");
const open = ref(false);
const activeIndex = ref(0);
const rootRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);

const results = computed(() => searchManual(query.value));
const hasQuery = computed(() => query.value.trim() !== "");
const showPanel = computed(() => open.value && hasQuery.value);

watch(query, () => {
    activeIndex.value = 0;
    open.value = true;
});

function linkTo(result: ManualSearchResult) {
    return { name: "manual", params: { section: result.sectionId }, ...(result.anchorId ? { hash: `#${result.anchorId}` } : {}) };
}

function optionId(index: number): string {
    return `${listId}-option-${index}`;
}

function close() {
    open.value = false;
}

/** 候補を選んだら検索欄を空にして閉じる（移動は RouterLink が行う） */
function afterNavigate() {
    query.value = "";
    close();
    inputRef.value?.blur();
    emit("navigate");
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === "Escape") {
        if (showPanel.value) close();
        else inputRef.value?.blur();
        return;
    }
    if (!hasQuery.value || results.value.length === 0) return;
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        open.value = true;
        const step = event.key === "ArrowDown" ? 1 : -1;
        activeIndex.value = (activeIndex.value + step + results.value.length) % results.value.length;
    } else if (event.key === "Enter" && !event.isComposing) {
        event.preventDefault();
        rootRef.value?.querySelector<HTMLElement>(`#${CSS.escape(optionId(activeIndex.value))} a`)?.click();
    }
}

function onFocusOut(event: FocusEvent) {
    if (!rootRef.value?.contains(event.relatedTarget as Node | null)) close();
}

defineExpose({ focus: () => inputRef.value?.focus() });
</script>

<template>
    <div ref="rootRef" class="relative" data-manual-search @focusout="onFocusOut">
        <div
            class="flex items-center gap-2.5 rounded-card border bg-surface text-muted focus-within:border-ink-2 focus-within:ring-3"
            :class="props.size === 'large' ? 'h-[52px] border-transparent px-4 focus-within:ring-signal' : 'h-10 border-line-strong px-3 shadow-field focus-within:ring-ink/10'"
        >
            <AppIcon name="search" :size="props.size === 'large' ? 20 : 18" />
            <input
                ref="inputRef"
                v-model="query"
                type="search"
                role="combobox"
                :aria-expanded="showPanel"
                :aria-controls="listId"
                :aria-activedescendant="showPanel && results.length > 0 ? optionId(activeIndex) : undefined"
                aria-autocomplete="list"
                aria-label="マニュアルを検索"
                :placeholder="props.size === 'large' ? '例：取込待ち、再照合、APIキー' : 'マニュアルを検索'"
                autocomplete="off"
                class="h-full min-w-0 flex-1 bg-transparent text-ink outline-none placeholder:text-muted"
                :class="props.size === 'large' ? 'text-[15px]' : 'text-[13px]'"
                data-manual-search-input
                @focus="open = true"
                @keydown="onKeydown"
            />
            <kbd v-if="!hasQuery" class="hidden rounded-chip border border-line-strong px-1.5 font-mono text-[11px] text-muted sm:inline" aria-hidden="true">/</kbd>
        </div>

        <div
            v-if="showPanel"
            class="absolute top-full left-0 z-40 mt-1.5 w-[min(30rem,calc(100vw-2rem))] overflow-hidden rounded-card border border-line bg-surface text-left text-ink shadow-float"
            data-manual-search-results
        >
            <ul v-if="results.length > 0" :id="listId" role="listbox" aria-label="検索結果" class="max-h-[60vh] overflow-y-auto py-1">
                <li v-for="(result, index) in results" :id="optionId(index)" :key="result.key" role="option" :aria-selected="index === activeIndex">
                    <RouterLink
                        :to="linkTo(result)"
                        tabindex="-1"
                        class="flex flex-col gap-0.5 px-3.5 py-2.5"
                        :class="index === activeIndex ? 'bg-tint' : 'hover:bg-surface-2'"
                        @mousedown.prevent
                        @mouseenter="activeIndex = index"
                        @click="afterNavigate"
                    >
                        <span class="text-[14px] leading-snug font-bold">{{ result.title }}</span>
                        <span class="text-[11.5px] text-muted">{{ result.path }}</span>
                        <span v-if="result.snippet" class="line-clamp-2 text-[12.5px] leading-[1.6] text-ink-2">{{ result.snippet }}</span>
                    </RouterLink>
                </li>
            </ul>
            <div v-else :id="listId" class="flex flex-col gap-1.5 px-3.5 py-3 text-[13px]" role="status" data-manual-search-empty>
                <p class="font-bold">見つかりませんでした</p>
                <p class="text-muted">別の言葉で探すか、マニュアルのホームから探してください。</p>
                <RouterLink :to="{ name: 'manual' }" class="inline-flex items-center gap-1 self-start font-bold text-ink hover:underline" @mousedown.prevent @click="afterNavigate"
                    >マニュアルのホームへ<AppIcon name="arrow_forward" :size="15"
                /></RouterLink>
            </div>
        </div>
    </div>
</template>
