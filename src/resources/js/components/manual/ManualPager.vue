<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { adjacentManualSections } from "@/constants/manual";
import { useAuthStore } from "@/stores/auth";

const props = defineProps<{ currentId: string }>();

const auth = useAuthStore();
/** 権限で見られない節は飛ばして、前後の節を選ぶ */
const adjacent = computed(() => adjacentManualSections(props.currentId, auth.role));
</script>

<template>
    <nav aria-label="前後の節" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <RouterLink
            v-if="adjacent.previous"
            :to="{ name: 'manual', params: { section: adjacent.previous.id } }"
            class="flex min-h-11 items-center gap-2 rounded-card border border-line-strong bg-surface px-4 py-3 shadow-card transition-colors hover:border-line-stronger hover:bg-surface-2"
            data-pager="previous"
        >
            <AppIcon name="chevron_left" :size="20" class="shrink-0 text-muted" />
            <span class="flex min-w-0 flex-col">
                <span class="text-[11.5px] text-muted">前へ</span>
                <span class="truncate text-[14px] font-semibold text-ink">{{ adjacent.previous.title }}</span>
            </span>
        </RouterLink>
        <span v-else class="hidden sm:block" aria-hidden="true"></span>
        <RouterLink
            v-if="adjacent.next"
            :to="{ name: 'manual', params: { section: adjacent.next.id } }"
            class="flex min-h-11 items-center justify-end gap-2 rounded-card border border-line-strong bg-surface px-4 py-3 text-right shadow-card transition-colors hover:border-line-stronger hover:bg-surface-2"
            data-pager="next"
        >
            <span class="flex min-w-0 flex-col">
                <span class="text-[11.5px] text-muted">次へ</span>
                <span class="truncate text-[14px] font-semibold text-ink">{{ adjacent.next.title }}</span>
            </span>
            <AppIcon name="chevron_right" :size="20" class="shrink-0 text-muted" />
        </RouterLink>
    </nav>
</template>
