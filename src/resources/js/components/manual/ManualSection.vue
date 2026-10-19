<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";

withDefaults(
    defineProps<{
        id: string;
        title: string;
        description?: string;
        icon?: string;
    }>(),
    {
        description: "",
        icon: "menu_book",
    },
);
</script>

<template>
    <section :id="id" class="scroll-mt-16 overflow-hidden rounded-card border border-line bg-surface shadow-card" :aria-labelledby="`${id}-title`">
        <header class="flex items-center gap-3 border-b border-line-2 px-[18px] py-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-control bg-tint text-ink-2">
                <AppIcon :name="icon" :size="18" />
            </span>
            <div>
                <h2 :id="`${id}-title`" class="text-[15px] font-bold text-ink">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-xs leading-relaxed text-muted">{{ description }}</p>
            </div>
        </header>
        <div class="space-y-5 px-[18px] py-5">
            <slot />
        </div>
    </section>
</template>
