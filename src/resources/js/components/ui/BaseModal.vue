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
            <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 px-4 py-10 backdrop-blur-[1px]" @click.self="emit('close')">
                <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl" :class="widthClass[width]">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
                            <p v-if="description" class="mt-1 text-xs text-slate-500">{{ description }}</p>
                        </div>
                        <button type="button" class="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700" aria-label="閉じる" @click="emit('close')">
                            <AppIcon name="close" :size="18" />
                        </button>
                    </header>
                    <div class="px-5 py-4"><slot /></div>
                    <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3.5"><slot name="footer" /></footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
