<script setup lang="ts">
withDefaults(
    defineProps<{
        rows: { term: string; meaning: string }[];
        termLabel?: string;
        meaningLabel?: string;
        /** 左の列を等幅で出す（画面に出るメッセージや列名など、文字どおりの表示） */
        monoTerm?: boolean;
    }>(),
    { termLabel: "表示", meaningLabel: "意味", monoTerm: false },
);
</script>

<template>
    <div class="overflow-x-auto rounded-card border border-line bg-surface">
        <table class="w-full text-left text-[14px]">
            <thead class="border-b border-line bg-surface-2 text-[12px] text-muted">
                <tr>
                    <th class="w-28 px-3.5 py-2 font-bold sm:w-40">{{ termLabel }}</th>
                    <th class="px-3.5 py-2 font-bold">{{ meaningLabel }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line-2 text-ink">
                <tr v-for="row in rows" :key="row.term">
                    <td class="px-3.5 py-2.5 align-top break-words text-ink" :class="monoTerm ? 'font-mono text-[12.5px]' : 'font-bold'">{{ row.term }}</td>
                    <td class="px-3.5 py-2.5 leading-[1.7]">{{ row.meaning }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
