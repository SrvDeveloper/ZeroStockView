<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

type CalloutTone = "info" | "tip" | "warning";

const props = withDefaults(
    defineProps<{
        title?: string;
        tone?: CalloutTone;
    }>(),
    {
        title: "",
        tone: "info",
    },
);

const styles: Record<CalloutTone, { container: string; icon: string; iconColor: string }> = {
    info: { container: "border-line bg-surface-2", icon: "info", iconColor: "text-ink-2" },
    tip: { container: "border-lamp-ok/25 bg-ok-soft", icon: "lightbulb", iconColor: "text-lamp-ok" },
    warning: { container: "border-lamp-warn/35 bg-warn-soft", icon: "warning", iconColor: "text-caution" },
};

const style = computed(() => styles[props.tone]);
</script>

<template>
    <aside class="flex gap-2.5 rounded-card border px-3.5 py-3 text-ink" :class="style.container" :role="tone === 'warning' ? 'alert' : 'note'">
        <AppIcon :name="style.icon" :size="18" :class="['mt-0.5', style.iconColor]" />
        <div class="min-w-0 text-[12.5px] leading-relaxed">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title ? 'mt-1' : ''"><slot /></div>
        </div>
    </aside>
</template>
