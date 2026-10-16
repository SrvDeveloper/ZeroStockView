<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { formatNumber } from "@/utils/format";

const props = defineProps<{ page: number; totalPages: number; total: number; perPage: number }>();
const emit = defineEmits<{ change: [page: number] }>();

const rangeStart = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1));
const rangeEnd = computed(() => Math.min(props.page * props.perPage, props.total));

/** 表示するページ番号（前後2ページ + 先頭・末尾） */
const pages = computed(() => {
    const list: (number | "gap")[] = [];
    for (let page = 1; page <= props.totalPages; page += 1) {
        const near = Math.abs(page - props.page) <= 1;
        if (page === 1 || page === props.totalPages || near) {
            list.push(page);
        } else if (list[list.length - 1] !== "gap") {
            list.push("gap");
        }
    }
    return list;
});

function go(page: number) {
    if (page < 1 || page > props.totalPages || page === props.page) return;
    emit("change", page);
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line-2 px-4 py-2.5">
        <p class="text-[11.5px] text-muted">
            全 <span class="font-semibold text-ink">{{ formatNumber(total) }}</span> 件中 {{ formatNumber(rangeStart) }} - {{ formatNumber(rangeEnd) }} 件を表示
        </p>
        <nav class="flex items-center gap-1" aria-label="ページ切替">
            <button
                type="button"
                class="flex h-[26px] w-[26px] items-center justify-center rounded-chip text-ink-2 transition-colors hover:bg-tint disabled:opacity-40"
                :disabled="page <= 1"
                aria-label="前のページ"
                @click="go(page - 1)"
            >
                <AppIcon name="chevron_left" :size="16" />
            </button>
            <template v-for="(entry, index) in pages" :key="`${entry}-${index}`">
                <span v-if="entry === 'gap'" class="px-1 text-xs text-faint">...</span>
                <button
                    v-else
                    type="button"
                    class="h-[26px] min-w-[26px] rounded-chip px-1.5 font-mono text-[11px] font-medium transition-colors"
                    :class="entry === page ? 'bg-ink text-white' : 'text-ink-2 hover:bg-tint'"
                    @click="go(entry)"
                >
                    {{ entry }}
                </button>
            </template>
            <button
                type="button"
                class="flex h-[26px] w-[26px] items-center justify-center rounded-chip text-ink-2 transition-colors hover:bg-tint disabled:opacity-40"
                :disabled="page >= totalPages"
                aria-label="次のページ"
                @click="go(page + 1)"
            >
                <AppIcon name="chevron_right" :size="16" />
            </button>
        </nav>
    </div>
</template>
