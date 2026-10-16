<script setup lang="ts">
import { computed, ref } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { formatSizeKb } from "@/utils/format";

const props = withDefaults(
    defineProps<{
        accept: string;
        modelValue: File | null;
        title?: string;
        description?: string;
        icon?: string;
    }>(),
    { title: "", description: "", icon: "description" },
);

const emit = defineEmits<{ "update:modelValue": [File | null] }>();

const dragging = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);

const acceptHint = computed(() => `${props.accept.replace(/^\./, "").toUpperCase()}形式のファイルを選択してください`);

function onDrop(e: DragEvent) {
    dragging.value = false;
    const file = e.dataTransfer?.files?.[0];
    if (file) emit("update:modelValue", file);
}

function onInputChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (file) emit("update:modelValue", file);
    (e.target as HTMLInputElement).value = "";
}

function clear() {
    emit("update:modelValue", null);
    if (inputRef.value) inputRef.value.value = "";
}
</script>

<template>
    <label
        class="flex min-h-64 cursor-pointer flex-col rounded-card border-[1.5px] border-dashed p-5 transition sm:p-6"
        :class="[props.modelValue ? 'border-ink-2/40 bg-surface' : 'border-line-stronger bg-surface-2 hover:border-ink-2/50', dragging ? 'border-ink bg-surface ring-3 ring-ink/10' : '']"
        @dragenter.prevent="dragging = true"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent.stop="onDrop"
    >
        <span v-if="props.title" class="flex items-start gap-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-tint text-ink-2">
                <AppIcon :name="props.icon" :size="22" />
            </span>
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold text-ink">{{ props.title }}</span>
                <span v-if="props.description" class="block text-[11.5px] text-muted">{{ props.description }}</span>
            </span>
        </span>

        <span class="mt-6 flex flex-1 flex-col items-center justify-center rounded-control bg-surface p-5 text-center">
            <AppIcon :name="props.modelValue ? 'check_circle' : 'upload_file'" :size="30" class="text-muted" />
            <span class="mt-2 text-[12.5px] font-semibold text-ink">{{ props.modelValue ? "選択済み" : "CSVをドロップまたはクリックして選択" }}</span>
            <span class="mt-1 text-[11px] text-muted">{{ acceptHint }}</span>
        </span>

        <span v-if="props.modelValue" class="mt-4 flex items-center gap-2 rounded-control border border-line bg-surface p-3 text-[12.5px] shadow-raised">
            <AppIcon name="attach_file" :size="18" class="shrink-0 text-ink-2" />
            <span class="min-w-0 flex-1 truncate font-mono text-[11.5px] text-ink">{{ props.modelValue.name }}（{{ formatSizeKb(Math.round(props.modelValue.size / 1024)) }}）</span>
            <button type="button" class="shrink-0 text-muted transition hover:text-ink" aria-label="削除" @click.prevent.stop="clear">
                <AppIcon name="close" :size="18" />
            </button>
        </span>

        <input ref="inputRef" type="file" :accept="props.accept" class="sr-only" @change="onInputChange" />
    </label>
</template>
