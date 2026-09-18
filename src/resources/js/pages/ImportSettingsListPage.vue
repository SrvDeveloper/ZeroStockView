<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useUiStore } from "@/stores/ui";

const router = useRouter();
const store = useImportSettingsStore();
const toast = useUiStore();

const pendingDeleteId = ref<string | null>(null);
const deleting = ref(false);
const pendingDelete = computed(() => store.settings.find((setting) => setting.id === pendingDeleteId.value) ?? null);

function productCodeSummary(productCodes: string[]): string[] {
    return productCodes.slice(0, 5);
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;
    try {
        await store.remove(pendingDelete.value.id);
        toast.push("取込設定を削除しました");
        pendingDeleteId.value = null;
    } catch {
        toast.push("取込設定の削除に失敗しました。時間をおいて再度お試しください。", "error");
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">取込設定</h1>
                <p class="mt-1 text-sm text-slate-500">用途ごとの取込対象品番を一覧で管理します。全{{ store.settings.length }}件。</p>
            </div>
            <BaseButton variant="primary" icon="add" @click="router.push({ name: 'import-setting-new' })">新しい設定を作成</BaseButton>
        </div>

        <BaseCard v-if="store.settings.length === 0" :padded="false">
            <BaseEmpty icon="settings" title="取込設定がありません" description="最初の取込設定を作成してください。">
                <BaseButton variant="primary" icon="add" @click="router.push({ name: 'import-setting-new' })">取込設定を作成</BaseButton>
            </BaseEmpty>
        </BaseCard>

        <BaseCard v-else :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full min-w-180 text-left text-sm">
                    <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2.5">設定名</th>
                            <th class="w-28 px-5 py-2.5 text-center">対象品番数</th>
                            <th class="px-5 py-2.5">対象品番</th>
                            <th class="w-44 px-5 py-2.5 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="setting in store.settings" :key="setting.id" class="hover:bg-slate-50">
                            <td class="px-5 py-4">
                                <button
                                    type="button"
                                    class="font-semibold text-primary-700 hover:text-primary-800 hover:underline"
                                    @click="router.push({ name: 'import-setting-edit', params: { id: setting.id } })"
                                >
                                    {{ setting.name }}
                                </button>
                            </td>
                            <td class="px-5 py-4 text-center font-medium tabular-nums text-slate-700">{{ setting.productCodes.length }}件</td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <BaseBadge v-for="code in productCodeSummary(setting.productCodes)" :key="code">{{ code }}</BaseBadge>
                                    <span v-if="setting.productCodes.length > 5" class="text-xs text-slate-500">ほか{{ setting.productCodes.length - 5 }}件</span>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <BaseButton variant="secondary" size="sm" icon="edit" @click="router.push({ name: 'import-setting-edit', params: { id: setting.id } })"> 編集 </BaseButton>
                                    <BaseButton variant="danger-ghost" size="sm" icon="delete" aria-label="削除" @click="pendingDeleteId = setting.id" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>

        <ConfirmModal
            :open="pendingDelete !== null"
            title="取込設定の削除"
            :message="`「${pendingDelete?.name}」を削除します。過去の調査結果に保存された設定名は残ります。`"
            @cancel="pendingDeleteId = null"
            @confirm="confirmDelete"
        />
    </div>
</template>
