<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean }>(), { title: "", description: "", padded: true });
</script>

<template>
    <section class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-end justify-between gap-3 border-b border-line-2 px-[18px] py-3">
            <div>
                <h2 class="text-[13.5px] font-semibold text-ink">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-[11.5px] text-muted">{{ description }}</p>
            </div>
            <div class="flex items-center gap-2"><slot name="actions" /></div>
        </header>
        <div :class="padded ? 'px-[18px] py-3.5' : ''"><slot /></div>
    </section>
</template>
