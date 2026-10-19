<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import { ADMIN_ROLES, canAccess } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { MAX_TARGET_PRODUCTS, useImportSettingsStore } from "@/stores/importSettings";
import { useUiStore } from "@/stores/ui";
import { formatDateTime } from "@/utils/format";

const router = useRouter();
const auth = useAuthStore();
const store = useImportSettingsStore();
const toast = useUiStore();
const canDelete = computed(() => canAccess(auth.role, ADMIN_ROLES));

const pendingDeleteId = ref<string | null>(null);
const deleting = ref(false);
const pendingDelete = computed(() => store.settings.find((setting) => setting.id === pendingDeleteId.value) ?? null);

function productCodeSummary(productCodes: string[]): string[] {
    return productCodes.slice(0, 5);
}

/** 全グループを合わせた取込対象（同じ品番・SKU が複数のグループにあっても 1 件と数える） */
const targetSummary = computed(() => {
    const productCodes = new Set(store.settings.flatMap((setting) => setting.productCodes));
    const skuCodes = new Set(
        store.settings.flatMap((setting) => setting.products.filter((product) => productCodes.has(product.productCode)).flatMap((product) => product.skus.map((sku) => sku.skuCode))),
    );
    return { productCount: productCodes.size, skuCount: skuCodes.size };
});

async function confirmDelete() {
    if (!canDelete.value || !pendingDelete.value || deleting.value) return;

    deleting.value = true;
    try {
        await store.remove(pendingDelete.value.id);
        toast.push("品番グループを削除しました");
        pendingDeleteId.value = null;
    } catch {
        toast.push("品番グループの削除に失敗しました。時間をおいて再度お試しください。", "error");
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">品番グループ</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">用途ごとに品番をまとめます。登録された全品番が取込対象です。全{{ store.settings.length }}件。</p>
            </div>
            <BaseButton class="sm:ml-auto" variant="primary" icon="add" @click="router.push({ name: 'import-setting-new' })">新しいグループを作成</BaseButton>
        </div>

        <div v-if="store.settings.length > 0" class="-mt-1 flex flex-wrap gap-x-5 gap-y-1 text-[11.5px] text-muted" data-target-summary>
            <span
                >グループ <b class="font-mono font-medium text-ink-2">{{ store.settings.length }}</b></span
            >
            <span
                >取込対象の品番（重複を除く） <b class="font-mono font-medium text-ink-2">{{ targetSummary.productCount }}</b></span
            >
            <span
                >取込対象の SKU <b class="font-mono font-medium text-ink-2">{{ targetSummary.skuCount }}</b></span
            >
        </div>

        <BaseCard v-if="store.settings.length === 0" :padded="false">
            <BaseEmpty icon="category" title="品番グループがありません" description="最初の品番グループを作成してください。">
                <BaseButton variant="primary" icon="add" @click="router.push({ name: 'import-setting-new' })">品番グループを作成</BaseButton>
            </BaseEmpty>
        </BaseCard>

        <BaseCard v-else :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full min-w-180 text-left text-xs">
                    <thead class="border-b border-line text-[11px] text-muted">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">グループ名</th>
                            <th class="w-28 px-4 py-2.5 text-right font-semibold">対象品番数</th>
                            <th class="px-4 py-2.5 font-semibold">対象品番</th>
                            <th class="w-36 px-4 py-2.5 font-semibold">最終同期</th>
                            <th class="w-40 px-4 py-2.5 text-right font-semibold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-2">
                        <tr v-for="setting in store.settings" :key="setting.id" :data-setting-id="setting.id" class="hover:bg-surface-2">
                            <td class="px-4 py-2.5">
                                <button
                                    type="button"
                                    class="text-[13px] font-semibold text-ink hover:cursor-pointer hover:underline"
                                    @click="router.push({ name: 'import-setting-edit', params: { id: setting.id } })"
                                >
                                    {{ setting.name }}
                                </button>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <span class="font-num text-xl font-semibold tabular-nums">{{ setting.productCodes.length }}</span>
                                <span class="ml-1 font-mono text-[10.5px] text-muted">/ {{ MAX_TARGET_PRODUCTS }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap items-center gap-1">
                                    <code v-for="code in productCodeSummary(setting.productCodes)" :key="code" class="rounded-chip bg-tint px-1.5 py-px font-mono text-[10.5px] text-ink uppercase">{{
                                        code
                                    }}</code>
                                    <span v-if="setting.productCodes.length > 5" class="text-[10.5px] text-muted">ほか{{ setting.productCodes.length - 5 }}件</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-ink-2" data-last-synced>{{ setting.lastSyncedAt ? formatDateTime(setting.lastSyncedAt) : "—" }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1.5">
                                    <BaseButton variant="secondary" size="sm" icon="edit" @click="router.push({ name: 'import-setting-edit', params: { id: setting.id } })"> 編集 </BaseButton>
                                    <BaseButton v-if="canDelete" variant="danger-ghost" size="sm" icon="delete" aria-label="削除" @click="pendingDeleteId = setting.id" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>

        <ConfirmModal
            v-if="canDelete"
            :open="pendingDelete !== null"
            title="品番グループの削除"
            :message="`「${pendingDelete?.name}」を削除します。過去の調査結果に保存されたグループ所属は残ります。`"
            @cancel="pendingDeleteId = null"
            @confirm="confirmDelete"
        />
    </div>
</template>
