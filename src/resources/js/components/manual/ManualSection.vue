<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";

withDefaults(
    defineProps<{
        id: string;
        title: string;
        /** 見出しの下のリード文 */
        description?: string;
        /** 節が属する章。見出しの上に小さく出して、いまどこを読んでいるかを示す */
        chapter?: string;
        /** 使える権限が限られる画面のとき、その権限（例: 特権管理者のみ） */
        audience?: string;
        /** この節の要点（2〜3 行） */
        points?: string[];
    }>(),
    {
        description: "",
        chapter: "",
        audience: "",
        points: () => [],
    },
);
</script>

<template>
    <section :id="id" class="scroll-mt-16 rounded-panel border border-line bg-surface px-5 py-7 shadow-card sm:px-10 sm:py-9" :aria-labelledby="`${id}-title`">
        <header class="flex flex-col gap-3.5">
            <p v-if="chapter" class="text-[12.5px] font-bold tracking-[0.04em] text-muted" data-section-chapter>{{ chapter }}</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <h1 :id="`${id}-title`" class="text-[26px] leading-snug font-extrabold text-ink sm:text-[30px]">{{ title }}</h1>
                <span v-if="audience" class="rounded-chip border border-line-stronger px-2.5 py-0.5 text-[12px] font-bold text-ink-2" data-section-audience>{{ audience }}</span>
            </div>
            <p v-if="description" class="text-[16px] leading-[1.8] text-ink-2">{{ description }}</p>
            <div v-if="points.length > 0" class="rounded-card bg-surface-2 px-5 py-4" data-section-points>
                <p class="text-[13px] font-bold text-ink-2">この節の要点</p>
                <ul class="mt-2 flex flex-col gap-1.5 text-[14.5px] leading-[1.7] text-ink">
                    <li v-for="point in points" :key="point" class="flex gap-2.5">
                        <AppIcon name="check" :size="18" class="mt-[3px] shrink-0 text-lamp-ok" />
                        <span>{{ point }}</span>
                    </li>
                </ul>
            </div>
        </header>
        <div class="mt-9 space-y-8 text-[15px] leading-[1.8] text-ink">
            <slot />
        </div>
    </section>
</template>
