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
    <section :id="id" class="scroll-mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" :aria-labelledby="`${id}-title`">
        <header class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                <AppIcon :name="icon" :size="20" />
            </span>
            <div>
                <h2 :id="`${id}-title`" class="text-base font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-sm leading-6 text-slate-500">{{ description }}</p>
            </div>
        </header>
        <div class="space-y-5 px-5 py-5 sm:px-6 sm:py-6">
            <slot />
        </div>
    </section>
</template>
