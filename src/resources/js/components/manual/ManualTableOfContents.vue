<script setup lang="ts">
import { MANUAL_CHAPTERS, type ManualHeadingMeta } from "@/constants/manual";

defineProps<{
    currentId: string;
    /** いまの節の小見出し。右の列がない画面幅のとき、いまの節の下に並べる */
    headings: ManualHeadingMeta[];
    activeHeadingId: string;
}>();

const emit = defineEmits<{ navigate: []; jump: [headingId: string] }>();
</script>

<template>
    <nav aria-label="操作マニュアルの目次" class="rounded-card border border-line bg-surface p-3 shadow-card lg:max-h-[calc(100vh-5.5rem)] lg:overflow-y-auto">
        <p class="px-2 text-[11px] font-semibold tracking-[0.08em] text-muted">目次</p>
        <div class="mt-1.5 space-y-2.5">
            <div v-for="chapter in MANUAL_CHAPTERS" :key="chapter.sections[0]?.id">
                <p v-if="chapter.title" class="px-2 pb-0.5 text-[11px] font-semibold text-muted" data-toc-chapter>{{ chapter.title }}</p>
                <ol class="space-y-px">
                    <li v-for="section in chapter.sections" :key="section.id">
                        <RouterLink
                            :to="{ name: 'manual', params: { section: section.id } }"
                            class="flex items-center rounded-control border-l-[3px] py-1.5 pr-2 text-[13px] transition-colors"
                            :class="[
                                chapter.title ? 'pl-3.5' : 'pl-2',
                                currentId === section.id ? 'border-ink bg-tint font-semibold text-ink' : 'border-transparent text-ink-2 hover:bg-tint hover:text-ink',
                            ]"
                            :aria-current="currentId === section.id ? 'page' : undefined"
                            :data-toc-section="section.id"
                            @click="emit('navigate')"
                            >{{ section.shortTitle }}</RouterLink
                        >
                        <ol v-if="currentId === section.id && headings.length > 0" class="mt-0.5 mb-1 space-y-px 2xl:hidden" :class="chapter.title ? 'ml-5' : 'ml-3'" data-toc-headings>
                            <li v-for="heading in headings" :key="heading.id">
                                <a
                                    :href="`#${heading.id}`"
                                    class="block border-l py-1 pr-1 pl-2.5 text-[12px] leading-snug transition-colors"
                                    :class="activeHeadingId === heading.id ? 'border-ink font-semibold text-ink' : 'border-line-strong text-muted hover:text-ink'"
                                    @click.prevent="emit('jump', heading.id)"
                                    >{{ heading.title }}</a
                                >
                            </li>
                        </ol>
                    </li>
                </ol>
            </div>
        </div>
    </nav>
</template>
