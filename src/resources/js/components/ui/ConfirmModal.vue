<script setup lang="ts">
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseModal from "@/components/ui/BaseModal.vue";

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        message: string;
        confirmLabel?: string;
        danger?: boolean;
    }>(),
    {
        confirmLabel: "削除する",
        danger: true,
    },
);

const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <BaseModal :open="open" :title="title" width="sm" @close="emit('cancel')">
        <p class="text-[13px] leading-relaxed text-ink-2">{{ message }}</p>
        <template #footer>
            <BaseButton variant="secondary" @click="emit('cancel')">キャンセル</BaseButton>
            <BaseButton :variant="danger ? 'danger' : 'primary'" @click="emit('confirm')">{{ confirmLabel }}</BaseButton>
        </template>
    </BaseModal>
</template>
