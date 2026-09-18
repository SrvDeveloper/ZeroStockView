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

const styles: Record<CalloutTone, { container: string; icon: string }> = {
    info: { container: "border-primary-200 bg-primary-50 text-primary-900", icon: "info" },
    tip: { container: "border-emerald-200 bg-emerald-50 text-emerald-900", icon: "lightbulb" },
    warning: { container: "border-amber-200 bg-amber-50 text-amber-950", icon: "warning" },
};

const style = computed(() => styles[props.tone]);
</script>

<template>
    <aside class="flex gap-3 rounded-lg border p-4" :class="style.container" :role="tone === 'warning' ? 'alert' : 'note'">
        <AppIcon :name="style.icon" :size="20" class="mt-0.5" />
        <div class="min-w-0 text-sm leading-6">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title ? 'mt-1' : ''"><slot /></div>
        </div>
    </aside>
</template>
