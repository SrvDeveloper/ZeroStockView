<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

type Tone = "info" | "success" | "warning" | "danger";

const props = withDefaults(defineProps<{ tone?: Tone; title?: string }>(), { tone: "info", title: "" });

const toneClass: Record<Tone, { box: string; icon: string; title: string; name: string }> = {
    info: { box: "border-line bg-surface-2", icon: "text-ink-2", title: "text-ink", name: "info" },
    success: { box: "border-lamp-ok/25 bg-ok-soft", icon: "text-lamp-ok", title: "text-ok-ink", name: "check_circle" },
    warning: { box: "border-lamp-warn/35 bg-warn-soft", icon: "text-caution", title: "text-caution", name: "warning" },
    danger: { box: "border-danger/20 bg-danger-soft", icon: "text-danger", title: "text-danger", name: "error" },
};

const tone = computed(() => toneClass[props.tone]);
</script>

<template>
    <div class="flex gap-2.5 rounded-card border px-3.5 py-3 text-[12.5px] text-ink" :class="tone.box">
        <AppIcon :name="tone.name" :size="18" :class="['mt-px', tone.icon]" />
        <div class="min-w-0 flex-1">
            <p v-if="title" class="font-semibold" :class="tone.title">{{ title }}</p>
            <div class="leading-relaxed"><slot /></div>
        </div>
    </div>
</template>
