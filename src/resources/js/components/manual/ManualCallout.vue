<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

type CalloutTone = "info" | "tip" | "warning";

const props = withDefaults(
    defineProps<{
        title?: string;
        /** 見出しの右に添える短い補足（例: 調査は作成も更新もされません） */
        note?: string;
        tone?: CalloutTone;
    }>(),
    {
        title: "",
        note: "",
        tone: "info",
    },
);

const styles: Record<CalloutTone, { container: string; header: string; icon: string; iconColor: string; note: string }> = {
    info: { container: "border-line-strong bg-surface-2", header: "border-line", icon: "info", iconColor: "text-ink-2", note: "text-muted" },
    tip: { container: "border-lamp-ok/35 bg-ok-soft", header: "border-lamp-ok/20", icon: "lightbulb", iconColor: "text-lamp-ok", note: "text-ok-ink" },
    warning: { container: "border-signal-border/60 bg-warn-soft", header: "border-caution/20", icon: "warning", iconColor: "text-caution", note: "text-caution" },
};

const style = computed(() => styles[props.tone]);
</script>

<template>
    <aside class="overflow-hidden rounded-card border text-ink" :class="style.container" :role="tone === 'warning' ? 'alert' : 'note'">
        <div v-if="title" class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 border-b px-[18px] py-3" :class="style.header">
            <AppIcon :name="style.icon" :size="20" :class="style.iconColor" />
            <p class="text-[15px] font-bold">{{ title }}</p>
            <p v-if="note" class="text-[13px]" :class="style.note">{{ note }}</p>
        </div>
        <div class="flex gap-2.5 px-[18px] py-3.5 text-[14px] leading-[1.8]">
            <AppIcon v-if="!title" :name="style.icon" :size="20" :class="['mt-0.5 shrink-0', style.iconColor]" />
            <div class="min-w-0 flex-1"><slot /></div>
        </div>
    </aside>
</template>
