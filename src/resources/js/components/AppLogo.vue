<script setup lang="ts">
/**
 * ZeroStockView のロゴ（角ばった棚枠・やや広い）。
 * 3 段の棚のうち真ん中の段だけを黄色にして「在庫ゼロの空き棚」を表す。
 * 形は public/favicon.svg と同じ。変える場合は両方を揃える。
 */
withDefaults(defineProps<{ size?: number; dark?: boolean }>(), { size: 28, dark: false });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 32 32" aria-hidden="true" focusable="false" data-app-logo>
        <rect width="32" height="32" rx="7" :fill="dark ? '#262c32' : '#11161a'" />
        <rect x="6.5" y="5" width="19" height="22" rx="4" fill="none" stroke="#e8ebee" stroke-width="2.6" />
        <rect x="10" y="9.5" width="12" height="3" rx="0.8" fill="#e8ebee" />
        <rect x="10" y="14.5" width="12" height="3" rx="0.8" fill="#f5c400" />
        <rect x="10" y="19.5" width="12" height="3" rx="0.8" fill="#e8ebee" />
    </svg>
</template>
