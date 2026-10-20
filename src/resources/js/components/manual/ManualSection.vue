<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";

withDefaults(
    defineProps<{
        id: string;
        title: string;
        description?: string;
        icon?: string;
        /** 節が属する章。見出しの上に小さく出して、いまどこを読んでいるかを示す */
        chapter?: string;
        /** 使える権限が限られる画面のとき、その権限（例: 特権管理者のみ） */
        audience?: string;
    }>(),
    {
        description: "",
        icon: "menu_book",
        chapter: "",
        audience: "",
    },
);
</script>

<template>
    <section :id="id" class="scroll-mt-16 overflow-hidden rounded-card border border-line bg-surface shadow-card" :aria-labelledby="`${id}-title`">
        <header class="flex items-start gap-3.5 border-b border-line bg-surface-2 px-6 py-4">
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-ink text-white">
                <AppIcon :name="icon" :size="20" />
            </span>
            <div class="min-w-0 flex-1">
                <p v-if="chapter" class="text-[11px] font-semibold tracking-[0.06em] text-muted" data-section-chapter>{{ chapter }}</p>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <h2 :id="`${id}-title`" class="text-[19px] leading-snug font-bold text-ink">{{ title }}</h2>
                    <span v-if="audience" class="rounded-chip border border-line-strong bg-surface px-2 py-px text-[11px] font-semibold text-ink-2" data-section-audience>{{ audience }}</span>
                </div>
                <p v-if="description" class="mt-1 text-[13px] leading-relaxed text-ink-2">{{ description }}</p>
            </div>
        </header>
        <div class="space-y-7 px-6 py-6">
            <slot />
        </div>
    </section>
</template>
