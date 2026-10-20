<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";

/**
 * 本文の中で、画面のボタン名・メニュー名を画面と同じ見た目の札で示す。
 * menu はサイドメニューの項目、primary は黒い主ボタン、button はそれ以外のボタン・切り替え・入力欄の名前。
 */
withDefaults(
    defineProps<{
        variant?: "menu" | "primary" | "button";
        icon?: string;
    }>(),
    { variant: "button", icon: "" },
);
</script>

<template>
    <span
        class="mx-0.5 inline-flex items-center gap-1 rounded-chip px-2 py-px align-[0.05em] text-[13px] leading-[1.6] font-bold whitespace-nowrap"
        :class="{
            'bg-sidebar text-sidebar-strong': variant === 'menu',
            'bg-ink text-white': variant === 'primary',
            'border border-line-stronger bg-surface text-ink': variant === 'button',
        }"
        :data-ui-label="variant"
        ><AppIcon v-if="icon" :name="icon" :size="14" /><slot
    /></span>
</template>
