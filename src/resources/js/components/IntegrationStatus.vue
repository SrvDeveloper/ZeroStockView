<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
import { type AmazonInventoryImport, fetchAmazonInventoryImports } from "@/api/amazonInventoryImports";
import { useSurveysStore } from "@/stores/surveys";
import { countExpiringFiles } from "@/utils/files";

const REFRESH_INTERVAL_MS = 5 * 60 * 1000;

const surveys = useSurveysStore();
const latestImport = ref<AmazonInventoryImport | null>(null);
const loaded = ref(false);
const failed = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | undefined;

const timeFormatter = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false });
const todayFormatter = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit" });

/** 当日なら HH:mm、それ以外は MM-DD HH:mm */
function formatLampTime(iso: string): string {
    const date = new Date(iso);
    const parts = Object.fromEntries(timeFormatter.formatToParts(date).map((part) => [part.type, part.value]));
    const time = `${parts.hour}:${parts.minute}`;
    return todayFormatter.format(date) === todayFormatter.format(new Date()) ? time : `${parts.month}-${parts.day} ${time}`;
}

const amazonLamp = computed<{ state: LampState; detail: string }>(() => {
    const item = latestImport.value;
    if (!item) return { state: "warn", detail: "未取得" };
    if (item.status === "succeeded") return { state: "ok", detail: formatLampTime(item.basisAt ?? item.completedAt ?? item.createdAt) };
    if (item.status === "failed") return { state: "error", detail: formatLampTime(item.completedAt ?? item.createdAt) };
    return { state: "warn", detail: "取得中" };
});

const expiringFileCount = computed(() => countExpiringFiles(surveys.surveys));

async function refresh() {
    try {
        latestImport.value = (await fetchAmazonInventoryImports())[0] ?? null;
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        loaded.value = true;
    }
}

onMounted(() => {
    void refresh();
    refreshTimer = setInterval(() => void refresh(), REFRESH_INTERVAL_MS);
});

onBeforeUnmount(() => clearInterval(refreshTimer));
</script>

<template>
    <section class="flex flex-col gap-[7px] rounded-card bg-white/4 px-3 py-2.5 text-[11.5px] shadow-[inset_0_0_0_1px_rgb(255_255_255/0.06)]" aria-label="連携ステータス">
        <p class="text-[10.5px] font-medium text-sidebar-dim">連携ステータス</p>
        <div class="flex items-center gap-2 text-sidebar-strong" data-integration="amazon">
            <template v-if="failed">
                <span>Amazon SP-API</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">状態を取得できません</span>
            </template>
            <template v-else-if="loaded">
                <StatusLamp :state="amazonLamp.state" label="Amazon SP-API" />
                <span class="ml-auto font-mono text-[10px] text-sidebar-dim">{{ amazonLamp.detail }}</span>
            </template>
            <template v-else>
                <span>Amazon SP-API</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">確認中…</span>
            </template>
        </div>
        <div class="flex items-center gap-2 text-sidebar-strong" data-integration="files">
            <StatusLamp :state="expiringFileCount > 0 ? 'warn' : 'ok'" label="保存ファイル" />
            <span class="ml-auto font-mono text-[10px] text-sidebar-dim">{{ expiringFileCount > 0 ? `${expiringFileCount}件 期限間近` : "期限間近なし" }}</span>
        </div>
    </section>
</template>
