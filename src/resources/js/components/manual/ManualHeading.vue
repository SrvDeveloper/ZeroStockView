<script setup lang="ts">
/** id は constants/manual.ts の節の headings と同じにする（「このページの内容」と検索の行き先になる） */
defineProps<{ id: string }>();
</script>

<template>
    <h3 :id="id" class="flex scroll-mt-20 items-center gap-2 text-[15.5px] leading-snug font-bold text-ink" data-manual-heading>
        <span class="h-[1.05em] w-1 shrink-0 rounded-full bg-ink" aria-hidden="true"></span>
        <slot />
    </h3>
</template>
