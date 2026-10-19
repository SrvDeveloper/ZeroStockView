<script setup lang="ts">
import AppIcon from "@/components/ui/AppIcon.vue";

withDefaults(
    defineProps<{
        src?: string;
        alt?: string;
        caption?: string;
        placeholder?: string;
    }>(),
    {
        src: "",
        alt: "",
        caption: "",
        placeholder: "ここに画面キャプチャを配置",
    },
);
</script>

<template>
    <figure>
        <div class="overflow-hidden rounded-card border border-line bg-surface-2 shadow-card">
            <img v-if="src" :src="src" :alt="alt" class="h-auto w-full object-contain" />
            <div v-else class="flex min-h-48 flex-col items-center justify-center gap-2 border-[1.5px] border-dashed border-line-stronger p-6 text-center text-faint">
                <AppIcon name="image" :size="32" />
                <span class="text-[12.5px] font-medium text-muted">{{ placeholder }}</span>
                <span class="text-[11px]">画像を設定すると、この枠内に表示されます</span>
            </div>
        </div>
        <figcaption v-if="caption" class="mt-2 text-center text-[11px] text-muted">{{ caption }}</figcaption>
    </figure>
</template>
