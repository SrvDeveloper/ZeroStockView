<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import BaseToggle from "@/components/ui/BaseToggle.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
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

/** 外部公開 API の接続情報（routes/web.php と doc/外部公開API仕様書.md に合わせる） */
const API_ENDPOINT = { method: "GET", path: "/api/v1/inventory/daily", baseUrl: "/api/v1", header: "X-API-Key", rateLimit: "60 回 / 分" } as const;
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-[22px] leading-tight font-semibold">API連携設定</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">外部システムから日次在庫データを取得するための認証情報と接続元を設定します。</p>
            </div>
            <BaseButton v-if="!loading" data-testid="save-api-settings" class="sm:ml-auto" variant="primary" icon="check" :loading="saving" @click="saveSetting">保存</BaseButton>
        </div>

        <BaseAlert v-if="loadError" tone="danger" title="設定を読み込めませんでした">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ loadError }}</span>
                <BaseButton size="sm" variant="secondary" icon="refresh" @click="loadSetting">再試行</BaseButton>
            </div>
        </BaseAlert>
        <BaseAlert v-if="errors.form" tone="danger">{{ errors.form }}</BaseAlert>

        <div v-if="loading" class="rounded-card border border-line bg-surface py-16 text-center text-[12.5px] text-muted shadow-card" role="status">設定を読み込んでいます...</div>

        <div v-else class="grid items-start gap-4 xl:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-4">
                <BaseCard title="API有効状態" description="外部システムからの参照APIの利用可否を切り替えます。">
                    <template #actions>
                        <StatusLamp :state="enabled ? 'ok' : 'warn'" :label="enabled ? '有効' : '無効'" class="text-[11.5px] text-ink-2" data-api-state />
                    </template>
                    <BaseToggle v-model="enabled" label="外部APIを有効にする" description="有効にする場合は、APIキーと許可IPアドレスまたはCIDRを1件以上登録してください。" />
                    <p v-if="errors.enabled" class="mt-3 text-[11px] text-danger">{{ errors.enabled }}</p>
                </BaseCard>

                <BaseCard title="APIキー" description="キーの値は発行直後に1回だけ表示されます。">
                    <div class="flex flex-col gap-3">
                        <code class="block rounded-control border border-line bg-surface-2 px-3 py-2 font-mono text-xs break-all text-ink-2" data-testid="api-key-value">{{ apiKeyStatus }}</code>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-[11.5px] text-muted">
                                発行日時: <span class="font-mono text-ink-2">{{ issuedAtLabel }}</span>
                            </p>
                            <BaseButton data-testid="rotate-api-key" variant="secondary" size="sm" icon="refresh" :loading="rotatingKey" @click="rotateKey">
                                {{ apiKeyConfigured ? "再発行" : "発行" }}
                            </BaseButton>
                        </div>
                        <BaseAlert v-if="apiKeyRevealed" tone="warning">このキーを今すぐ安全な場所へ控えてください。</BaseAlert>
                    </div>
                </BaseCard>

                <BaseCard title="接続情報" description="CSV出力と同じ項目をJSON形式で返します。">
                    <div class="flex flex-col gap-3 text-[11.5px]">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5" data-api-endpoint>
                            <dt class="text-muted">メソッド</dt>
                            <dd class="font-mono">{{ API_ENDPOINT.method }}</dd>
                            <dt class="text-muted">パス</dt>
                            <dd class="font-mono break-all">{{ API_ENDPOINT.path }}</dd>
                            <dt class="text-muted">認証</dt>
                            <dd
                                ><span class="font-mono">{{ API_ENDPOINT.header }}</span> ヘッダー</dd
                            >
                            <dt class="text-muted">回数制限</dt>
                            <dd class="font-mono">{{ API_ENDPOINT.rateLimit }}</dd>
                        </dl>
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line-2 pt-3">
                            <span class="text-muted"
                                >ベースURL: <code class="font-mono text-ink-2">{{ API_ENDPOINT.baseUrl }}</code></span
                            >
                            <BaseButton data-testid="open-api-reference" variant="secondary" size="sm" icon="api" @click="apiReferenceOpen = true">APIリファレンス</BaseButton>
                        </div>
                    </div>
                </BaseCard>
            </div>

            <BaseCard title="許可IPアドレス / CIDR" description="接続元IPが許可設定に一致し、APIキーが正しい場合にAPIへの接続を許可します。" :padded="false">
                <div class="grid gap-2.5 border-b border-line-2 px-[18px] py-3.5 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] md:items-end">
                    <BaseInput v-model="newNetwork" class="[&_input]:font-mono" label="IP / CIDR" placeholder="203.0.113.10 または 198.51.100.0/24" :error="errors.network" />
                    <BaseInput v-model="newMemo" label="メモ" placeholder="本社固定IP" />
                    <BaseButton data-testid="add-network" variant="secondary" icon="add" @click="addNetwork">追加</BaseButton>
                </div>

                <div v-if="errors.networks" class="px-[18px] pt-3.5"
                    ><BaseAlert tone="danger">{{ errors.networks }}</BaseAlert></div
                >

                <div v-if="allowedNetworks.length === 0" class="px-[18px] py-10 text-center text-[12.5px] text-muted">許可IPアドレス / CIDRは登録されていません。</div>
                <div v-else class="divide-y divide-line-2">
                    <div v-for="(entry, index) in allowedNetworks" :key="`${entry.network}-${index}`" class="grid grid-cols-[46px_minmax(0,1fr)_auto] items-center gap-2.5 px-[18px] py-2">
                        <span class="rounded-chip bg-ink py-px text-center font-mono text-[9.5px] tracking-widest text-white">{{ entry.network.includes("/") ? "CIDR" : "IP" }}</span>
                        <div class="min-w-0">
                            <code class="block font-mono text-xs font-medium break-all text-ink">{{ entry.network }}</code>
                            <p class="text-[10.5px] text-muted">{{ entry.memo || "メモなし" }}</p>
                        </div>
                        <BaseButton variant="ghost" size="sm" icon="delete" @click="removeNetwork(index)">削除</BaseButton>
                    </div>
                </div>
            </BaseCard>
        </div>

        <BaseModal :open="apiReferenceOpen" title="APIリファレンス" description="CSV出力と同じ項目をJSON形式で返します。" width="lg" @close="apiReferenceOpen = false">
            <div data-testid="api-reference-content">
                <dl class="grid gap-4 text-[12.5px] sm:grid-cols-[140px_minmax(0,1fr)]">
                    <dt class="font-medium text-ink-2">エンドポイント</dt>
                    <dd><code class="rounded-chip bg-surface-2 px-2 py-1 font-mono text-xs break-all text-ink">GET /api/v1/inventory/daily</code></dd>
                    <dt class="font-medium text-ink-2">認証ヘッダー</dt>
                    <dd><code class="rounded-chip bg-surface-2 px-2 py-1 font-mono text-xs break-all text-ink">X-API-Key: 発行したAPIキー</code></dd>
                    <dt class="font-medium text-ink-2">パラメータ</dt>
                    <dd class="text-ink-2"
                        ><code class="font-mono">from</code>、<code class="font-mono">to</code>（必須・日本時間・最大365日間）、<code class="font-mono">product_code</code>（任意・省略時は全品番）</dd
                    >
                </dl>
            </div>

            <template #footer>
                <BaseButton variant="secondary" @click="apiReferenceOpen = false">閉じる</BaseButton>
            </template>
        </BaseModal>
    </div>
</template>
