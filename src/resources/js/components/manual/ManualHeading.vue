<script setup lang="ts">
/** id は constants/manual.ts の節の headings と同じにする（「このページの内容」と検索の行き先になる） */
defineProps<{ id: string }>();
</script>

<template>
    <h2 :id="id" class="scroll-mt-32 border-b-2 lg:scroll-mt-20 border-ink pb-2.5 text-[21px] leading-snug font-extrabold text-ink" data-manual-heading>
        <slot />
    </h2>
</template>
