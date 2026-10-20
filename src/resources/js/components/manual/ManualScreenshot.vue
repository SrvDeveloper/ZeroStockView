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
    <!-- ページが縦に長くなりすぎないよう、画像は幅を抑えて表示し、押すと原寸で開く -->
    <figure class="max-w-xl">
        <a v-if="src" :href="src" target="_blank" rel="noopener" class="group relative block overflow-hidden rounded-card border border-line bg-surface-2 shadow-card" title="クリックで拡大">
            <img :src="src" :alt="alt || caption" class="h-auto w-full object-contain" />
            <span
                class="absolute right-2 bottom-2 flex items-center gap-1 rounded-chip bg-ink/75 px-2 py-0.5 text-[11px] text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                aria-hidden="true"
                ><AppIcon name="open_in_new" :size="14" />拡大</span
            >
        </a>
        <div v-else class="flex min-h-48 flex-col items-center justify-center gap-2 rounded-card border-[1.5px] border-dashed border-line-stronger bg-surface-2 p-6 text-center text-faint">
            <AppIcon name="image" :size="32" />
            <span class="text-[12.5px] font-medium text-muted">{{ placeholder }}</span>
            <span class="text-[11px]">画像を設定すると、この枠内に表示されます</span>
        </div>
        <figcaption v-if="caption" class="mt-1.5 text-[12px] text-muted">{{ caption }}</figcaption>
    </figure>
</template>
