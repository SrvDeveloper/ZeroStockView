<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";
</script>

<template>
    <!-- 操作したあとに画面がどうなるか -->
    <p class="flex items-start gap-2.5 rounded-card bg-ok-soft px-3.5 py-2.5 text-[14px] leading-[1.75] text-ok-ink" data-manual-result>
        <AppIcon name="check" :size="20" class="mt-0.5 shrink-0" />
        <span><b>結果：</b><slot /></span>
    </p>
</template>
