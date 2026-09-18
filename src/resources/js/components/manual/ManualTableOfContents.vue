<script setup lang="ts">
defineProps<{
    items: Array<{ id: string; title: string }>;
}>();
</script>

<template>
    <nav aria-label="操作マニュアルの目次" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="px-2 text-xs font-semibold tracking-wide text-slate-400 uppercase">目次</p>
        <ol class="mt-2 space-y-1">
            <li v-for="(item, index) in items" :key="item.id">
                <a :href="`#${item.id}`" class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm text-slate-600 transition-colors hover:bg-primary-50 hover:text-primary-700">
                    <span class="w-5 text-right text-xs text-slate-400">{{ index + 1 }}.</span>
                    <span>{{ item.title }}</span>
                </a>
            </li>
        </ol>
    </nav>
</template>
