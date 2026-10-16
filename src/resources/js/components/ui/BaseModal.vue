<script setup lang="ts">
import { onBeforeUnmount, watch } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";

const props = withDefaults(defineProps<{ open: boolean; title: string; description?: string; width?: "sm" | "md" | "lg" }>(), { description: "", width: "md" });
const emit = defineEmits<{ close: [] }>();

const widthClass = { sm: "max-w-sm", md: "max-w-lg", lg: "max-w-3xl" } as const;

function onKeydown(event: KeyboardEvent) {
    if (event.key === "Escape") emit("close");
}

watch(
    () => props.open,
    (open) => {
        if (open) window.addEventListener("keydown", onKeydown);
        else window.removeEventListener("keydown", onKeydown);
    },
);

onBeforeUnmount(() => window.removeEventListener("keydown", onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/45 px-4 py-10 backdrop-blur-[2px]" @click.self="emit('close')">
                <div class="w-full overflow-hidden rounded-panel border border-line bg-surface shadow-float" :class="widthClass[width]">
                    <header class="flex items-start justify-between gap-4 border-b border-line-2 px-5 py-4">
                        <div>
                            <h2 class="text-[15px] font-semibold text-ink">{{ title }}</h2>
                            <p v-if="description" class="mt-1 text-[11.5px] text-muted">{{ description }}</p>
                        </div>
                        <button type="button" class="rounded-chip p-1 text-muted transition-colors hover:bg-tint hover:text-ink" aria-label="閉じる" @click="emit('close')">
                            <AppIcon name="close" :size="18" />
                        </button>
                    </header>
                    <div class="px-5 py-4"><slot /></div>
                    <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-line-2 bg-surface-2 px-5 py-3"><slot name="footer" /></footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
