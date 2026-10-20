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
        <table class="w-full text-left text-[13px]">
            <thead class="border-b border-line bg-surface-2 text-[11.5px] text-muted">
                <tr>
                    <th class="w-28 px-3 py-2 font-semibold sm:w-36">{{ termLabel }}</th>
                    <th class="px-3 py-2 font-semibold">{{ meaningLabel }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line-2 text-ink">
                <tr v-for="row in rows" :key="row.term">
                    <td class="px-3 py-2 align-top font-semibold break-words text-ink">{{ row.term }}</td>
                    <td class="px-3 py-2 leading-relaxed">{{ row.meaning }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
