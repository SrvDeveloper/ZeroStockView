<script setup lang="ts">
defineProps<{
    items: Array<{ id: string; title: string }>;
}>();
</script>

<template>
    <nav aria-label="操作マニュアルの目次" class="rounded-card border border-line bg-surface p-3 shadow-card">
        <p class="px-2 text-[11px] font-semibold text-muted">目次</p>
        <ol class="mt-1.5 space-y-0.5">
            <li v-for="(item, index) in items" :key="item.id">
                <a :href="`#${item.id}`" class="flex items-center gap-2 rounded-control px-2 py-1.5 text-[12.5px] text-ink-2 transition-colors hover:bg-tint hover:text-ink">
                    <span class="w-5 text-right font-mono text-[10.5px] text-faint">{{ `${index + 1}`.padStart(2, "0") }}</span>
                    <span>{{ item.title }}</span>
                </a>
            </li>
        </ol>
    </nav>
</template>
