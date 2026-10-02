<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import BaseToggle from "@/components/ui/BaseToggle.vue";
import { useUiStore } from "@/stores/ui";
import { formatDateTime } from "@/utils/format";

interface AllowedNetwork {
    network: string;
    memo: string;
}

interface ApiConnectionSetting {
    enabled: boolean;
    apiKeyConfigured: boolean;
    apiKeyMasked: string | null;
    apiKeyIssuedAt: string | null;
    allowedNetworks: AllowedNetwork[];
    apiKey?: string;
}

const toast = useUiStore();
const enabled = ref(false);
const apiKeyConfigured = ref(false);
const displayedApiKey = ref("");
const apiKeyIssuedAt = ref<string | null>(null);
const apiKeyRevealed = ref(false);
const allowedNetworks = ref<AllowedNetwork[]>([]);
const newNetwork = ref("");
const newMemo = ref("");
const loading = ref(true);
const saving = ref(false);
const rotatingKey = ref(false);
const loadError = ref("");
const apiReferenceOpen = ref(false);
const errors = ref<Record<string, string>>({});

const apiKeyStatus = computed(() => (apiKeyConfigured.value ? displayedApiKey.value : "未発行"));
const issuedAtLabel = computed(() => (apiKeyIssuedAt.value ? formatDateTime(apiKeyIssuedAt.value) : "—"));

onMounted(loadSetting);

async function loadSetting() {
    loading.value = true;
    loadError.value = "";
    try {
        const { data } = await axios.get<ApiConnectionSetting>("/api/api-connection-settings");
        applySetting(data);
    } catch {
        loadError.value = "API連携設定を読み込めませんでした。時間をおいて再度お試しください。";
    } finally {
        loading.value = false;
    }
}

function applySetting(setting: ApiConnectionSetting, preserveRevealedKey = false) {
    enabled.value = setting.enabled;
    apiKeyConfigured.value = setting.apiKeyConfigured;
    apiKeyIssuedAt.value = setting.apiKeyIssuedAt;
    allowedNetworks.value = setting.allowedNetworks.map((entry) => ({ ...entry }));

    if (setting.apiKey) {
        displayedApiKey.value = setting.apiKey;
        apiKeyRevealed.value = true;
    } else if (!preserveRevealedKey || !apiKeyRevealed.value) {
        displayedApiKey.value = setting.apiKeyMasked ?? "";
        apiKeyRevealed.value = false;
    }
}

function addNetwork() {
    errors.value.network = "";
    const network = newNetwork.value.trim();
    if (!network) {
        errors.value.network = "IPアドレスまたはCIDRを入力してください。";
        return;
    }
    if (allowedNetworks.value.some((entry) => entry.network === network)) {
        errors.value.network = "同じIPアドレスまたはCIDRがすでに登録されています。";
        return;
    }

    allowedNetworks.value.push({ network, memo: newMemo.value.trim() });
    newNetwork.value = "";
    newMemo.value = "";
}

function removeNetwork(index: number) {
    allowedNetworks.value.splice(index, 1);
}

async function rotateKey() {
    rotatingKey.value = true;
    errors.value = {};
    try {
        const wasConfigured = apiKeyConfigured.value;
        const { data } = await axios.post<ApiConnectionSetting>("/api/api-connection-settings/rotate-key");
        applySetting(data);
        toast.push(wasConfigured ? "APIキーを再発行しました" : "APIキーを発行しました");
    } catch {
        errors.value.form = "APIキーを発行できませんでした。";
    } finally {
        rotatingKey.value = false;
    }
}

async function saveSetting() {
    errors.value = {};
    saving.value = true;
    try {
        const { data } = await axios.put<ApiConnectionSetting>("/api/api-connection-settings", {
            enabled: enabled.value,
            allowed_networks: allowedNetworks.value,
        });
        applySetting(data, true);
        toast.push("API連携設定を保存しました");
    } catch (error) {
        if (axios.isAxiosError(error)) {
            const responseErrors = error.response?.data?.errors as Record<string, string[]> | undefined;
            if (responseErrors) {
                for (const [field, messages] of Object.entries(responseErrors)) {
                    const target = field.startsWith("allowed_networks") ? "networks" : field;
                    errors.value[target] ||= messages[0] ?? "";
                }
            }
        }
        if (Object.keys(errors.value).length === 0) errors.value.form = "API連携設定を保存できませんでした。";
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">API連携設定</h1>
            <p class="mt-1 text-sm text-slate-500">外部システムから日次在庫データを取得するための認証情報と接続元を設定します。</p>
        </div>

        <BaseAlert v-if="loadError" tone="danger" title="設定を読み込めませんでした">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ loadError }}</span>
                <BaseButton size="sm" variant="secondary" icon="refresh" @click="loadSetting">再試行</BaseButton>
            </div>
        </BaseAlert>
        <BaseAlert v-if="errors.form" tone="danger">{{ errors.form }}</BaseAlert>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white py-16 text-center text-sm text-slate-500 shadow-sm" role="status">設定を読み込んでいます...</div>

        <template v-else>
            <div class="grid gap-5 lg:grid-cols-2">
                <BaseCard title="API有効状態" description="外部システムからの参照APIの利用可否を切り替えます。" :padded="false">
                    <div class="p-5">
                        <BaseToggle v-model="enabled" label="外部APIを有効にする" description="有効にする場合は、APIキーと許可IPアドレスまたはCIDRを1件以上登録してください。" />
                        <p v-if="errors.enabled" class="mt-3 text-xs text-rose-600">{{ errors.enabled }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs">
                        <span class="flex items-center gap-2 text-slate-500">
                            <BaseBadge :tone="enabled ? 'success' : 'neutral'">{{ enabled ? "有効" : "無効" }}</BaseBadge>
                            ベースURL: <code class="font-mono text-slate-700">/api/v1</code>
                        </span>
                        <button
                            type="button"
                            data-testid="open-api-reference"
                            class="inline-flex items-center gap-1.5 font-medium text-primary-700 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                            @click="apiReferenceOpen = true"
                        >
                            <AppIcon name="api" :size="15" />APIリファレンス
                        </button>
                    </div>
                </BaseCard>

                <BaseCard title="APIキー" description="キーの値は発行直後に1回だけ表示されます。" :padded="false">
                    <div class="flex min-h-36 flex-wrap items-start justify-between gap-4 p-5">
                        <div class="min-w-0">
                            <code class="block break-all font-mono text-sm font-medium text-slate-800" data-testid="api-key-value">{{ apiKeyStatus }}</code>
                            <p class="mt-2 text-xs text-slate-500">発行日時: {{ issuedAtLabel }}</p>
                            <p v-if="apiKeyRevealed" class="mt-2 text-xs font-medium text-amber-700">このキーを今すぐ安全な場所へ控えてください。</p>
                        </div>
                        <BaseButton data-testid="rotate-api-key" variant="secondary" icon="refresh" :loading="rotatingKey" @click="rotateKey">
                            {{ apiKeyConfigured ? "再発行" : "発行" }}
                        </BaseButton>
                    </div>
                </BaseCard>
            </div>

            <BaseCard title="許可IPアドレス / CIDR" description="接続元IPが許可設定に一致し、APIキーが正しい場合にAPIへの接続を許可します。" :padded="false">
                <div class="grid gap-3 border-b border-slate-200 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                    <BaseInput v-model="newNetwork" label="IP / CIDR" placeholder="203.0.113.10 または 198.51.100.0/24" :error="errors.network" />
                    <BaseInput v-model="newMemo" label="メモ" placeholder="本社固定IP" />
                    <BaseButton data-testid="add-network" variant="secondary" icon="add" class="md:min-w-36" @click="addNetwork">追加</BaseButton>
                </div>

                <BaseAlert v-if="errors.networks" tone="danger" class="m-5">{{ errors.networks }}</BaseAlert>

                <div v-if="allowedNetworks.length === 0" class="px-5 py-10 text-center text-sm text-slate-500"> 許可IPアドレス / CIDRは登録されていません。 </div>
                <div v-else class="divide-y divide-slate-100">
                    <div v-for="(entry, index) in allowedNetworks" :key="`${entry.network}-${index}`" class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <BaseBadge tone="brand" mono>{{ entry.network.includes("/") ? "CIDR" : "IP" }}</BaseBadge>
                            <div class="min-w-0">
                                <code class="block break-all font-mono text-sm font-medium text-slate-900">{{ entry.network }}</code>
                                <p class="mt-0.5 text-xs text-slate-500">{{ entry.memo || "メモなし" }}</p>
                            </div>
                        </div>
                        <BaseButton variant="ghost" size="sm" icon="delete" @click="removeNetwork(index)">削除</BaseButton>
                    </div>
                </div>

                <div class="flex justify-end border-t border-slate-200 bg-slate-50/70 px-5 py-3.5">
                    <BaseButton data-testid="save-api-settings" variant="primary" icon="check" :loading="saving" @click="saveSetting">保存</BaseButton>
                </div>
            </BaseCard>
        </template>

        <BaseModal :open="apiReferenceOpen" title="APIリファレンス" description="CSV出力と同じ項目をJSON形式で返します。" width="lg" @close="apiReferenceOpen = false">
            <div data-testid="api-reference-content">
                <dl class="grid gap-4 text-sm sm:grid-cols-[140px_minmax(0,1fr)]">
                    <dt class="font-medium text-slate-600">エンドポイント</dt>
                    <dd><code class="break-all rounded bg-slate-100 px-2 py-1 text-xs text-slate-800">GET /api/v1/inventory/daily</code></dd>
                    <dt class="font-medium text-slate-600">認証ヘッダー</dt>
                    <dd><code class="break-all rounded bg-slate-100 px-2 py-1 text-xs text-slate-800">X-API-Key: 発行したAPIキー</code></dd>
                    <dt class="font-medium text-slate-600">パラメータ</dt>
                    <dd class="text-slate-600"><code>from</code>、<code>to</code>（必須）、<code>product_code</code>（任意・省略時は全品番）</dd>
                </dl>
            </div>

            <template #footer>
                <BaseButton variant="secondary" @click="apiReferenceOpen = false">閉じる</BaseButton>
            </template>
        </BaseModal>
    </div>
</template>
