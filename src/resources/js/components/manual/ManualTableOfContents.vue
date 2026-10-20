<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";

export interface ManualChapter {
    /** 章の名前。空なら章の見出しを出さず、項目だけを並べる */
    title: string;
    items: { id: string; title: string }[];
}

const props = defineProps<{ chapters: ManualChapter[] }>();

/** 画面上部（ヘッダーの下）を過ぎた最後の節を「いま読んでいる節」とする */
const ACTIVE_OFFSET_PX = 120;
const activeId = ref(props.chapters[0]?.items[0]?.id ?? "");
let frame = 0;

function updateActive() {
    frame = 0;
    let current = props.chapters[0]?.items[0]?.id ?? "";
    for (const item of props.chapters.flatMap((chapter) => chapter.items)) {
        const element = document.getElementById(item.id);
        if (element && element.getBoundingClientRect().top <= ACTIVE_OFFSET_PX) current = item.id;
    }
    activeId.value = current;
}

function onScroll() {
    if (frame === 0) frame = window.requestAnimationFrame(updateActive);
}

onMounted(() => {
    window.addEventListener("scroll", onScroll, { passive: true });
    updateActive();
});

onBeforeUnmount(() => {
    window.removeEventListener("scroll", onScroll);
    if (frame !== 0) window.cancelAnimationFrame(frame);
});
</script>

<template>
    <nav aria-label="操作マニュアルの目次" class="rounded-card border border-line bg-surface p-3 shadow-card lg:max-h-[calc(100vh-5.5rem)] lg:overflow-y-auto">
        <p class="px-2 text-[11px] font-semibold tracking-[0.08em] text-muted">目次</p>
        <div class="mt-1.5 space-y-2.5">
            <div v-for="chapter in chapters" :key="chapter.items[0]?.id">
                <p v-if="chapter.title" class="px-2 pb-0.5 text-[11px] font-semibold text-muted" data-toc-chapter>{{ chapter.title }}</p>
                <ol class="space-y-px">
                    <li v-for="item in chapter.items" :key="item.id">
                        <a
                            :href="`#${item.id}`"
                            class="flex items-center rounded-control border-l-[3px] py-1.5 pr-2 text-[13px] transition-colors"
                            :class="[
                                chapter.title ? 'pl-3.5' : 'pl-2',
                                activeId === item.id ? 'border-ink bg-tint font-semibold text-ink' : 'border-transparent text-ink-2 hover:bg-tint hover:text-ink',
                            ]"
                            :aria-current="activeId === item.id ? 'location' : undefined"
                            @click="activeId = item.id"
                            >{{ item.title }}</a
                        >
                    </li>
                </ol>
            </div>
        </div>
    </nav>
</template>
