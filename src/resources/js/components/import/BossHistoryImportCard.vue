<script setup lang="ts">
import { computed, ref } from "vue";
import axios from "axios";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import { applyBossInventoryHistory, previewBossInventoryHistory, type BossHistoryNullReason, type BossHistoryPreview } from "@/api/bossInventoryHistories";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import type { SurveySummary } from "@/types";
import { formatDate, formatDateTime, formatNumber } from "@/utils/format";
import { formatMonthDay } from "@/utils/inventoryTrendMetrics";

/** 不一致の SKU・倉庫の一覧に出す件数 */
const MISMATCH_DISPLAY_LIMIT = 20;

const REASON_LABELS: Record<BossHistoryNullReason, string> = {
    mismatch: "基準の調査と不一致",
    chainBreak: "履歴のつながりが途切れている",
    negative: "マイナスの値",
    noData: "基準の調査にもない",
};

const surveys = useSurveysStore();
const toast = useUiStore();

const fileInputRef = ref<HTMLInputElement | null>(null);
const selectedFile = ref<File | null>(null);
const preview = ref<BossHistoryPreview | null>(null);
const previewing = ref(false);
const applying = ref(false);
const errorMessage = ref("");
const filledSurveys = ref<SurveySummary[]>([]);
const busy = computed(() => previewing.value || applying.value);

const typeCounts = computed(() => Object.entries(preview.value?.typeCounts ?? {}).sort(([, a], [, b]) => b - a));
const shownMismatches = computed(() => preview.value?.mismatches.slice(0, MISMATCH_DISPLAY_LIMIT) ?? []);
const hiddenMismatchCount = computed(() => (preview.value ? preview.value.mismatchCount - shownMismatches.value.length : 0));

function openFilePicker() {
    if (!busy.value) fileInputRef.value?.click();
}

function errorText(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
        const message = errors ? Object.values(errors).flat()[0] : error.response?.data?.message;
        if (typeof message === "string" && message !== "") return message;
    }
    return fallback;
}

async function onFileInputChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = "";
    if (!file || busy.value) return;

    selectedFile.value = file;
    errorMessage.value = "";
    previewing.value = true;
    try {
        preview.value = await previewBossInventoryHistory(file);
    } catch (error) {
        selectedFile.value = null;
        errorMessage.value = errorText(error, "在庫変動履歴を確認できませんでした。ファイルを確認して、もう一度選択してください。");
    } finally {
        previewing.value = false;
    }
}

function cancel() {
    if (applying.value) return;
    preview.value = null;
    selectedFile.value = null;
}

async function apply() {
    if (!selectedFile.value || applying.value) return;
    applying.value = true;
    errorMessage.value = "";
    try {
        const result = await applyBossInventoryHistory(selectedFile.value);
        filledSurveys.value = result.surveys;
        toast.push(`${result.surveys.length}日分のBOSS在庫を補完しました`);
        await surveys.load().catch(() => undefined);
    } catch (error) {
        errorMessage.value = errorText(error, "BOSS在庫を補完できませんでした。時間をおいて、もう一度実行してください。");
        toast.push("BOSS在庫を補完できませんでした", "error");
    } finally {
        applying.value = false;
        preview.value = null;
        selectedFile.value = null;
    }
}

/** ISO 日時を、表示する日付（YYYY-MM-DD）にする */
function localDate(iso: string): string {
    return formatDate(iso).replaceAll("/", "-");
}

function reportTime(iso: string | null): string {
    return iso === null ? "—" : formatDateTime(iso).slice(5);
}
</script>

<template>
    <BaseCard
        title="休日のBOSS在庫を補完"
        description="BOSSの在庫変動履歴を取り込むと、在庫レポートを出力していない日のBOSS在庫を、9:00時点の値で再現します。休日前の最後の出勤日の0:00から、休日明けにレポートを出力したあとまでの履歴を出力してください。"
        :padded="false"
    >
        <div class="flex flex-col gap-3 px-[18px] py-3.5 text-[11.5px]">
            <div class="flex flex-wrap items-center gap-3">
                <BaseButton data-testid="boss-history-select" variant="secondary" size="sm" icon="upload_file" :disabled="busy" :loading="previewing" @click="openFilePicker">
                    在庫変動履歴を選択
                </BaseButton>
                <span v-if="previewing" class="text-muted">内容を確認中…</span>
                <input ref="fileInputRef" type="file" class="sr-only" accept=".csv,.txt" aria-label="在庫変動履歴を選択" @change="onFileInputChange" />
            </div>
            <BaseAlert v-if="errorMessage" data-testid="boss-history-error" tone="danger" title="BOSS在庫の補完">{{ errorMessage }}</BaseAlert>
            <div v-if="filledSurveys.length > 0" data-testid="boss-history-result" class="flex flex-col gap-1.5">
                <p class="text-ink-2">補完した日</p>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="survey in filledSurveys" :key="survey.id">
                        <RouterLink
                            :to="{ name: 'survey-result', params: { id: survey.id } }"
                            class="inline-flex items-center gap-1 rounded-chip border border-line bg-surface px-2 py-1 font-medium text-ink hover:bg-tint"
                        >
                            {{ formatMonthDay(localDate(survey.executedAt)) }}
                            <AppIcon name="chevron_right" :size="14" class="text-muted" />
                        </RouterLink>
                    </li>
                </ul>
            </div>
        </div>
    </BaseCard>

    <BaseModal :open="preview !== null" title="BOSS在庫の補完内容を確認" :description="selectedFile?.name ?? ''" width="lg" @close="cancel">
        <div v-if="preview" data-testid="boss-history-preview" class="flex flex-col gap-4 text-[12px]">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt class="text-muted">履歴の期間</dt>
                <dd class="font-mono">{{ formatDateTime(preview.periodFrom) }} 〜 {{ formatDateTime(preview.periodTo) }}</dd>
                <dt class="text-muted">行数</dt>
                <dd>
                    <span class="font-num font-semibold">{{ formatNumber(preview.rowCount) }}</span> 行
                    <span class="ml-1 text-muted"
                        >（<template v-for="([type, count], index) in typeCounts" :key="type">{{ index > 0 ? "・" : "" }}{{ type }} {{ formatNumber(count) }}</template
                        >）</span
                    >
                </dd>
            </dl>

            <section class="flex flex-col gap-1.5">
                <h3 class="text-[12.5px] font-semibold">基準の調査（BOSSのレポートを取り込んだ日）</h3>
                <div class="overflow-x-auto rounded-card border border-line">
                    <table class="w-full text-left">
                        <thead class="bg-surface-2 text-[11px] text-muted">
                            <tr>
                                <th class="px-3 py-1.5 font-medium">日付</th>
                                <th class="px-3 py-1.5 font-medium">推定したレポートの時刻</th>
                                <th class="px-3 py-1.5 text-right font-medium">不一致</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="base in preview.baseSurveys" :key="base.date" data-base-survey class="border-t border-line-2">
                                <td class="px-3 py-1.5">{{ formatMonthDay(base.date) }}</td>
                                <td class="px-3 py-1.5">
                                    <span v-if="base.unusableReason" class="text-muted">使えません（{{ base.unusableReason }}）</span>
                                    <span v-else class="font-mono">{{ reportTime(base.estimatedReportAt) }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-right font-num">{{ formatNumber(base.mismatchCount) }}件</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-1.5">
                <h3 class="text-[12.5px] font-semibold">補完する日（9:00時点）</h3>
                <div class="overflow-x-auto rounded-card border border-line">
                    <table class="w-full text-left">
                        <thead class="bg-surface-2 text-[11px] text-muted">
                            <tr>
                                <th class="px-3 py-1.5 font-medium">日付</th>
                                <th class="px-3 py-1.5 font-medium">調査</th>
                                <th class="px-3 py-1.5 text-right font-medium">値が入るSKU</th>
                                <th class="px-3 py-1.5 text-right font-medium">データなしのSKU</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in preview.days" :key="day.date" data-fill-day class="border-t border-line-2">
                                <td class="px-3 py-1.5">{{ formatMonthDay(day.date) }}</td>
                                <td class="px-3 py-1.5">{{ day.surveyExists ? "既存の調査を更新" : "新しく作る" }}</td>
                                <td class="px-3 py-1.5 text-right font-num">{{ formatNumber(day.filledSkuCount) }}</td>
                                <td class="px-3 py-1.5 text-right font-num">
                                    <span v-if="day.nullSkuCount === 0">0</span>
                                    <span
                                        v-else
                                        class="group relative inline-block cursor-help underline decoration-dotted underline-offset-2"
                                        tabindex="0"
                                        :aria-label="`データなしのSKU ${day.nullSkuCount}件の内訳`"
                                    >
                                        {{ formatNumber(day.nullSkuCount) }}
                                        <span
                                            role="tooltip"
                                            class="invisible absolute right-0 bottom-full z-40 mb-1 flex flex-col gap-0.5 rounded-card border border-line bg-surface px-2.5 py-1.5 text-left font-sans text-[11px] whitespace-nowrap text-ink-2 no-underline opacity-0 shadow-float transition-opacity group-hover:visible group-hover:opacity-100 group-focus:visible group-focus:opacity-100"
                                        >
                                            <span v-for="(count, reason) in day.nullReasons" :key="reason" data-null-reason>{{ REASON_LABELS[reason] }} {{ formatNumber(count ?? 0) }}件</span>
                                        </span>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <BaseAlert
                v-if="preview.mismatchCount > 0"
                data-testid="boss-history-mismatches"
                tone="warning"
                :title="`基準の調査や履歴と合わないSKUが ${formatNumber(preview.mismatchCount)}件あります`"
            >
                <p>これらのSKU・倉庫は、補完する日のBOSS在庫をデータなしにします（理由の記載がないものは、基準の調査と不一致）。</p>
                <ul class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-[11px]">
                    <li v-for="item in shownMismatches" :key="`${item.skuCode}-${item.warehouse}-${item.reason}`"
                        >{{ item.skuCode }}（{{ item.warehouse }}{{ item.reason === "mismatch" ? "" : `・${REASON_LABELS[item.reason]}` }}）</li
                    >
                    <li v-if="hiddenMismatchCount > 0" class="font-sans text-muted">ほか {{ formatNumber(hiddenMismatchCount) }}件</li>
                </ul>
            </BaseAlert>

            <BaseAlert v-if="preview.unfillableDates.length > 0" data-testid="boss-history-unfillable" tone="info" title="補完できない日">
                <ul class="flex flex-col gap-0.5">
                    <li v-for="item in preview.unfillableDates" :key="item.date">{{ formatMonthDay(item.date) }}: {{ item.reason }}</li>
                </ul>
            </BaseAlert>
        </div>
        <template #footer>
            <BaseButton data-testid="boss-history-cancel" variant="secondary" :disabled="applying" @click="cancel">キャンセル</BaseButton>
            <BaseButton data-testid="boss-history-apply" variant="primary" icon="play_arrow" :disabled="applying" :loading="applying" @click="apply">
                {{ applying ? "実行中…" : "補完を実行" }}
            </BaseButton>
        </template>
    </BaseModal>
</template>
