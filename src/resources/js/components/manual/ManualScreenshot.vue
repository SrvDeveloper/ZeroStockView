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
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
            <img v-if="src" :src="src" :alt="alt" class="h-auto w-full object-contain" />
            <div v-else class="flex min-h-48 flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 p-6 text-center text-slate-400">
                <AppIcon name="image" :size="32" />
                <span class="text-sm font-medium">{{ placeholder }}</span>
                <span class="text-xs">画像を設定すると、この枠内に表示されます</span>
            </div>
        </div>
        <figcaption v-if="caption" class="mt-2 text-center text-xs text-slate-500">{{ caption }}</figcaption>
    </figure>
</template>
