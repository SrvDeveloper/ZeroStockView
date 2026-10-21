<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
import { type AmazonInventoryImport, fetchAmazonInventoryImports } from "@/api/amazonInventoryImports";
import { type CrossWalkerConnectionStatus, fetchIntegrationStatus } from "@/api/integrations";
import { EDITOR_ROLES, canAccess } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useSurveysStore } from "@/stores/surveys";
import { countExpiringFiles } from "@/utils/files";

const REFRESH_INTERVAL_MS = 5 * 60 * 1000;

const auth = useAuthStore();
const surveys = useSurveysStore();
const canView = computed(() => canAccess(auth.role, EDITOR_ROLES));
const latestImport = ref<AmazonInventoryImport | null>(null);
const loaded = ref(false);
const failed = ref(false);
const crossWalkerStatus = ref<CrossWalkerConnectionStatus | null>(null);
const crossWalkerLoaded = ref(false);
const crossWalkerFailed = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | undefined;

const timeFormatter = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false });
const todayFormatter = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit" });

/** 当日なら HH:mm、それ以外は MM/DD HH:mm（日付を「10-20」と書くと時刻の 10:20 に見えるため「/」で区切る） */
function formatLampTime(iso: string): string {
    const date = new Date(iso);
    const parts = Object.fromEntries(timeFormatter.formatToParts(date).map((part) => [part.type, part.value]));
    const time = `${parts.hour}:${parts.minute}`;
    return todayFormatter.format(date) === todayFormatter.format(new Date()) ? time : `${parts.month}/${parts.day} ${time}`;
}

const amazonLamp = computed<{ state: LampState; detail: string }>(() => {
    const item = latestImport.value;
    if (!item) return { state: "warn", detail: "未取得" };
    if (item.status === "succeeded") return { state: "ok", detail: formatLampTime(item.basisAt ?? item.completedAt ?? item.createdAt) };
    if (item.status === "failed") return { state: "error", detail: formatLampTime(item.completedAt ?? item.createdAt) };
    return { state: "warn", detail: "取得中" };
});

const crossWalkerLamp = computed<{ state: LampState; detail: string }>(() => {
    const status = crossWalkerStatus.value;
    if (!status) return { state: "warn", detail: "記録なし" };
    return { state: status.status === "succeeded" ? "ok" : "error", detail: formatLampTime(status.checkedAt) };
});

const expiringFileCount = computed(() => countExpiringFiles(surveys.summaries));

async function refreshAmazon() {
    try {
        latestImport.value = (await fetchAmazonInventoryImports())[0] ?? null;
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        loaded.value = true;
    }
}

async function refreshCrossWalker() {
    try {
        crossWalkerStatus.value = (await fetchIntegrationStatus()).crossWalker;
        crossWalkerFailed.value = false;
    } catch {
        crossWalkerFailed.value = true;
    } finally {
        crossWalkerLoaded.value = true;
    }
}

/** 片方の取得に失敗しても、もう片方は表示する */
async function refresh() {
    if (!canView.value) return;
    await Promise.all([refreshAmazon(), refreshCrossWalker()]);
}

onMounted(() => {
    if (!canView.value) return;
    void refresh();
    refreshTimer = setInterval(() => void refresh(), REFRESH_INTERVAL_MS);
});

onBeforeUnmount(() => clearInterval(refreshTimer));
</script>

<template>
    <section v-if="canView" class="flex flex-col gap-[7px] rounded-card bg-white/4 px-3 py-2.5 text-[11.5px] shadow-[inset_0_0_0_1px_rgb(255_255_255/0.06)]" aria-label="連携ステータス">
        <p class="text-[10.5px] font-medium text-sidebar-dim">連携ステータス</p>
        <div class="flex items-center gap-1.5 text-sidebar-strong" data-integration="amazon">
            <template v-if="failed">
                <span>Amazon</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">状態を取得できません</span>
            </template>
            <template v-else-if="loaded">
                <StatusLamp :state="amazonLamp.state" label="Amazon" />
                <span class="ml-auto font-mono text-[10px] whitespace-nowrap text-sidebar-dim">{{ amazonLamp.detail }}</span>
            </template>
            <template v-else>
                <span>Amazon</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">確認中…</span>
            </template>
        </div>
        <div class="flex items-center gap-1.5 text-sidebar-strong" data-integration="crosswalker" :title="crossWalkerStatus?.message ?? undefined">
            <template v-if="crossWalkerFailed">
                <span>CrossWalker</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">状態を取得できません</span>
            </template>
            <template v-else-if="crossWalkerLoaded">
                <StatusLamp :state="crossWalkerLamp.state" label="CrossWalker" />
                <span class="ml-auto font-mono text-[10px] whitespace-nowrap text-sidebar-dim">{{ crossWalkerLamp.detail }}</span>
            </template>
            <template v-else>
                <span>CrossWalker</span>
                <span class="ml-auto text-[10px] text-sidebar-dim">確認中…</span>
            </template>
        </div>
        <div class="flex items-center gap-1.5 text-sidebar-strong" data-integration="files">
            <StatusLamp :state="expiringFileCount > 0 ? 'warn' : 'ok'" label="保存ファイル" />
            <span class="ml-auto font-mono text-[10px] whitespace-nowrap text-sidebar-dim">{{ expiringFileCount > 0 ? `${expiringFileCount}件 期限間近` : "期限間近なし" }}</span>
        </div>
    </section>
</template>
