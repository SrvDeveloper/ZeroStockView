<script setup lang="ts">
withDefaults(
    defineProps<{
        rows: { term: string; meaning: string }[];
        termLabel?: string;
        meaningLabel?: string;
    }>(),
    { termLabel: "表示", meaningLabel: "意味" },
);
</script>

<template>
    <div class="overflow-x-auto rounded-card border border-line">
        <table class="w-full min-w-100 text-left text-[12.5px]">
            <thead class="border-b border-line bg-surface-2 text-[11px] text-muted">
                <tr>
                    <th class="w-32 px-3 py-2 font-semibold">{{ termLabel }}</th>
                    <th class="px-3 py-2 font-semibold">{{ meaningLabel }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line-2 text-ink-2">
                <tr v-for="row in rows" :key="row.term">
                    <td class="px-3 py-1.5 align-top font-semibold text-ink">{{ row.term }}</td>
                    <td class="px-3 py-1.5 leading-relaxed">{{ row.meaning }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
