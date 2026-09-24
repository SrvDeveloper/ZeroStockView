<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseTextarea from "@/components/ui/BaseTextarea.vue";

const props = withDefaults(
    defineProps<{
        modelValue: string;
        multiline?: boolean;
        placeholder?: string;
        rows?: number;
    }>(),
    {
        multiline: false,
        placeholder: "メモを入力",
        rows: 2,
    },
);

const emit = defineEmits<{ "update:modelValue": [string]; save: [string] }>();

type SaveState = "idle" | "saving" | "saved";

const text = ref(props.modelValue);
const state = ref<SaveState>("idle");
const isEditing = ref(false);
const isComposing = ref(false);
const pendingValue = ref<string | null>(null);
let savingTimer: ReturnType<typeof setTimeout> | undefined;
let savedTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.modelValue,
    (v) => {
        if (pendingValue.value !== null) {
            if (v === pendingValue.value) {
                pendingValue.value = null;
                if (!isEditing.value) text.value = v;
            }
            return;
        }
        if (!isEditing.value) text.value = v;
    },
);

function onInput(value: string) {
    text.value = value;
}

function commit() {
    if (isComposing.value || text.value === props.modelValue || text.value === pendingValue.value) return;
    pendingValue.value = text.value;
    state.value = "saving";
    clearTimeout(savingTimer);
    clearTimeout(savedTimer);
    emit("update:modelValue", text.value);
    emit("save", text.value);
    savingTimer = setTimeout(() => {
        state.value = "saved";
        savedTimer = setTimeout(() => (state.value = "idle"), 1600);
    }, 300);
}

function beginEditing() {
    isEditing.value = true;
}

function endEditing() {
    isEditing.value = false;
    commit();
}

function beginComposition() {
    isComposing.value = true;
}

function endComposition() {
    isComposing.value = false;
    if (!isEditing.value) commit();
}

onBeforeUnmount(() => {
    clearTimeout(savingTimer);
    clearTimeout(savedTimer);
});
</script>

<template>
    <div class="relative" @focusin="beginEditing" @focusout="endEditing" @compositionstart="beginComposition" @compositionend="endComposition">
        <BaseTextarea v-if="multiline" :model-value="text" :placeholder="placeholder" :rows="rows" :bordered="false" @update:model-value="onInput" />
        <BaseInput v-else :model-value="text" size="sm" :placeholder="placeholder" :bordered="false" @update:model-value="onInput" />
        <Transition name="fade">
            <span v-if="state !== 'idle'" class="pointer-events-none absolute top-1/2 right-1.5 z-10 -translate-y-1/2">
                <AppIcon v-if="state === 'saving'" name="refresh" :size="14" class="animate-spin text-slate-400" />
                <AppIcon v-else name="check_circle" :size="14" class="text-emerald-600" />
            </span>
        </Transition>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.25s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
