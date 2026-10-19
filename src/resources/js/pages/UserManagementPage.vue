<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { createUser, deleteUser, fetchUsers, updateUser, type ManagedUser, type UserInput } from "@/api/users";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import { ADMIN_ROLES, canAccess, type UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";

const router = useRouter();
const auth = useAuthStore();
const toast = useUiStore();
const canManageUsers = computed(() => canAccess(auth.role, ADMIN_ROLES));
const users = ref<ManagedUser[]>([]);
const loading = ref(true);
const listError = ref("");
const roleLabels: Record<UserRole, string> = { admin: "特権管理者", editor: "編集者", viewer: "閲覧者" };
const roleOptions: UserRole[] = ["viewer", "editor", "admin"];
const adminCount = computed(() => users.value.filter((user) => user.role === "admin").length);

const formOpen = ref(false);
const editingId = ref<string | null>(null);
const editingUser = computed(() => users.value.find((user) => user.id === editingId.value) ?? null);
const form = reactive<UserInput>({ loginId: "", password: "", passwordConfirmation: "", role: "viewer" });
const fieldErrors = reactive({ loginId: "", password: "", passwordConfirmation: "", role: "" });
const formError = ref("");
const saving = ref(false);
const roleChanging = computed(() => editingUser.value !== null && form.role !== editingUser.value.role);
const lastAdminDemotion = computed(() => editingUser.value?.role === "admin" && adminCount.value <= 1 && form.role !== "admin");

const pendingDeleteId = ref<string | null>(null);
const pendingDelete = computed(() => users.value.find((user) => user.id === pendingDeleteId.value) ?? null);
const deleteError = ref("");
const deleting = ref(false);

function sortUsers(): void {
    users.value.sort((left, right) => left.loginId.localeCompare(right.loginId));
}

async function loadUsers(): Promise<void> {
    if (!canManageUsers.value) return;
    loading.value = true;
    listError.value = "";
    try {
        users.value = await fetchUsers();
        sortUsers();
    } catch {
        listError.value = "ユーザー一覧を取得できませんでした。再読み込みしてください。";
    } finally {
        loading.value = false;
    }
}

onMounted(() => void loadUsers());

function clearFormErrors(): void {
    fieldErrors.loginId = "";
    fieldErrors.password = "";
    fieldErrors.passwordConfirmation = "";
    fieldErrors.role = "";
    formError.value = "";
}

function openForm(user: ManagedUser | null): void {
    editingId.value = user?.id ?? null;
    form.loginId = user?.loginId ?? "";
    form.password = "";
    form.passwordConfirmation = "";
    form.role = user?.role ?? "viewer";
    clearFormErrors();
    formOpen.value = true;
}

function closeForm(): void {
    if (saving.value) return;
    formOpen.value = false;
    form.password = "";
    form.passwordConfirmation = "";
}

function validateForm(): boolean {
    clearFormErrors();
    const loginId = form.loginId.trim();
    if (!loginId) fieldErrors.loginId = "ログインIDを入力してください。";
    else if (loginId.length > 100) fieldErrors.loginId = "ログインIDは100文字以内で入力してください。";
    if (!editingId.value && !form.password) fieldErrors.password = "初期パスワードを入力してください。";
    else if (form.password && (form.password.length < 12 || !/^[\x21-\x7e]+$/.test(form.password))) fieldErrors.password = "パスワードは半角英数字記号12文字以上で入力してください。";
    if (!editingId.value && !form.passwordConfirmation) fieldErrors.passwordConfirmation = "確認用パスワードを入力してください。";
    else if (form.password !== form.passwordConfirmation) fieldErrors.passwordConfirmation = "確認用パスワードが一致しません。";
    if (lastAdminDemotion.value) fieldErrors.role = "最後の特権管理者は降格できません。";
    return Object.values(fieldErrors).every((message) => message === "");
}

function validationErrors(error: unknown): Record<string, string[]> | null {
    if (!axios.isAxiosError(error) || error.response?.status !== 422) return null;
    return (error.response.data?.errors as Record<string, string[]> | undefined) ?? null;
}

function firstError(errors: Record<string, string[]> | null, key: string): string | null {
    return errors?.[key]?.[0] ?? null;
}

function showFormApiError(error: unknown): void {
    const errors = validationErrors(error);
    if (!errors) {
        formError.value = "ユーザーを保存できませんでした。時間をおいて再度お試しください。";
        return;
    }
    const loginIdError = firstError(errors, "login_id");
    if (loginIdError) fieldErrors.loginId = /taken|unique/i.test(loginIdError) ? "このログインIDはすでに使用されています。" : "ログインIDを確認してください（必須・100文字以内）。";
    if (firstError(errors, "password")) fieldErrors.password = "パスワードは半角英数字記号12文字以上で入力してください。";
    if (firstError(errors, "password_confirmation")) fieldErrors.passwordConfirmation = "確認用パスワードが一致しません。";
    const roleError = firstError(errors, "role");
    if (roleError) fieldErrors.role = roleError.includes("最後の特権管理者") ? roleError : "権限を確認してください。";
    formError.value = firstError(errors, "user") ?? (Object.values(fieldErrors).some(Boolean) ? "入力内容を確認してください。" : "ユーザーを保存できませんでした。入力内容を確認してください。");
}

async function saveUser(): Promise<void> {
    if (!canManageUsers.value || saving.value || !validateForm()) return;
    saving.value = true;
    const input: UserInput = { loginId: form.loginId.trim(), password: form.password, passwordConfirmation: form.passwordConfirmation, role: form.role };
    const wasOwnRoleChange = editingId.value === auth.user?.id && roleChanging.value;
    try {
        const saved = editingId.value ? await updateUser(editingId.value, input) : await createUser(input);
        if (wasOwnRoleChange) {
            auth.user = null;
            auth.initialized = true;
            useSurveysStore().$reset();
            useImportSettingsStore().$reset();
            await router.replace({ name: "login" });
            return;
        }
        const index = users.value.findIndex((user) => user.id === saved.id);
        if (index < 0) users.value.push(saved);
        else users.value[index] = saved;
        sortUsers();
        if (saved.id === auth.user?.id) auth.user = saved;
        toast.push(editingId.value ? "ユーザーを更新しました" : "ユーザーを作成しました");
        formOpen.value = false;
        form.password = "";
        form.passwordConfirmation = "";
    } catch (error) {
        showFormApiError(error);
    } finally {
        saving.value = false;
    }
}

function deleteBlockedReason(user: ManagedUser): string {
    if (user.id === auth.user?.id && user.role === "admin" && adminCount.value <= 1) return "自分自身・最後の特権管理者は削除できません";
    if (user.id === auth.user?.id) return "自分自身は削除できません";
    if (user.role === "admin" && adminCount.value <= 1) return "最後の特権管理者は削除できません";
    return "";
}

function requestDelete(user: ManagedUser): void {
    if (!canManageUsers.value || deleteBlockedReason(user)) return;
    deleteError.value = "";
    pendingDeleteId.value = user.id;
}

function closeDelete(): void {
    if (deleting.value) return;
    pendingDeleteId.value = null;
    deleteError.value = "";
}

async function confirmDelete(): Promise<void> {
    const target = pendingDelete.value;
    if (!canManageUsers.value || !target || deleteBlockedReason(target) || deleting.value) return;
    deleting.value = true;
    deleteError.value = "";
    try {
        await deleteUser(target.id);
        users.value = users.value.filter((user) => user.id !== target.id);
        toast.push("ユーザーを削除しました");
        pendingDeleteId.value = null;
    } catch (error) {
        const errors = validationErrors(error);
        deleteError.value = firstError(errors, "user") ?? firstError(errors, "role") ?? "ユーザーを削除できませんでした。時間をおいて再度お試しください。";
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div v-if="canManageUsers" class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">ユーザー管理</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">ログインIDと権限を管理します。権限を変更すると対象ユーザーの全セッションが即時終了します。</p>
            </div>
            <BaseButton variant="primary" icon="person_add" @click="openForm(null)">ユーザーを追加</BaseButton>
        </div>

        <BaseAlert v-if="listError" tone="danger">
            <div class="flex items-center gap-3">
                <span>{{ listError }}</span>
                <BaseButton size="sm" @click="loadUsers">再読み込み</BaseButton>
            </div>
        </BaseAlert>
        <BaseCard v-else :padded="false">
            <p v-if="loading" class="px-4 py-5 text-sm text-muted">読み込み中…</p>
            <p v-else-if="users.length === 0" class="px-4 py-5 text-sm text-muted">ユーザーがいません。</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-line text-[11px] text-muted">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">ログインID</th>
                            <th class="px-4 py-2.5 font-semibold">権限</th>
                            <th class="px-4 py-2.5 text-right font-semibold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-2">
                        <tr v-for="user in users" :key="user.id" :data-user-id="user.id">
                            <td class="px-4 py-3 font-mono text-ink">{{ user.loginId }} <span v-if="user.id === auth.user?.id" class="font-sans text-muted">（自分）</span></td>
                            <td class="px-4 py-3 text-ink-2">{{ roleLabels[user.role] }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <BaseButton size="sm" icon="edit" :aria-label="`${user.loginId}を編集`" @click="openForm(user)">編集</BaseButton>
                                    <span v-if="deleteBlockedReason(user)" class="text-[11px] text-muted">{{ deleteBlockedReason(user) }}</span>
                                    <BaseButton v-else variant="danger-ghost" size="sm" icon="delete" :aria-label="`${user.loginId}を削除`" @click="requestDelete(user)">削除</BaseButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>

        <BaseModal
            :open="formOpen"
            :title="editingId ? 'ユーザーを編集' : 'ユーザーを追加'"
            :description="editingId ? 'パスワードを空欄にすると変更しません。' : '初期パスワードを設定してください。'"
            @close="closeForm"
        >
            <form class="flex flex-col gap-3" novalidate @submit.prevent="saveUser">
                <BaseAlert v-if="formError" tone="danger">{{ formError }}</BaseAlert>
                <BaseInput v-model="form.loginId" label="ログインID" :error="fieldErrors.loginId" autocomplete="username" required :disabled="saving" />
                <BaseInput
                    v-model="form.password"
                    type="password"
                    :label="editingId ? '新しいパスワード' : '初期パスワード'"
                    :hint="editingId ? '変更しない場合は空欄にしてください。' : '半角英数字記号12文字以上'"
                    :error="fieldErrors.password"
                    autocomplete="new-password"
                    :required="!editingId"
                    :disabled="saving"
                />
                <BaseInput
                    v-model="form.passwordConfirmation"
                    type="password"
                    label="パスワード（確認）"
                    :error="fieldErrors.passwordConfirmation"
                    autocomplete="new-password"
                    :required="!editingId"
                    :disabled="saving"
                />
                <div>
                    <label for="managed-user-role" class="mb-1 block text-[11px] font-medium text-ink-2">権限</label>
                    <select
                        id="managed-user-role"
                        v-model="form.role"
                        class="h-8 w-full rounded-control border border-line-strong bg-surface px-3 text-[12.5px] text-ink shadow-field focus:border-ink-2 focus:outline-none focus:ring-3 focus:ring-ink/10"
                        :class="fieldErrors.role ? 'border-danger ring-3 ring-danger/12' : ''"
                        :disabled="saving"
                    >
                        <option v-for="role in roleOptions" :key="role" :value="role">{{ roleLabels[role] }}</option>
                    </select>
                    <p v-if="fieldErrors.role" class="mt-1 text-[11px] text-danger">{{ fieldErrors.role }}</p>
                </div>
                <BaseAlert v-if="lastAdminDemotion" tone="warning">最後の特権管理者は降格できません。先に別の特権管理者を作成してください。</BaseAlert>
                <BaseAlert v-else-if="roleChanging" tone="warning"
                    >権限を変更すると、このユーザーの全セッションが即時終了します。{{
                        editingId === auth.user?.id ? "自分の権限を変更した場合はログイン画面に戻ります。" : "再ログインが必要です。"
                    }}</BaseAlert
                >
                <div class="flex justify-end gap-2 pt-2">
                    <BaseButton :disabled="saving" @click="closeForm">キャンセル</BaseButton>
                    <BaseButton type="submit" variant="primary" :loading="saving">{{ editingId ? "変更を保存" : "ユーザーを作成" }}</BaseButton>
                </div>
            </form>
        </BaseModal>

        <BaseModal :open="pendingDelete !== null" title="ユーザーを削除" width="sm" @close="closeDelete">
            <p class="text-[13px] leading-relaxed text-ink-2">「{{ pendingDelete?.loginId }}」を削除します。このユーザーの全セッションは即時終了します。元に戻せません。</p>
            <BaseAlert v-if="deleteError" class="mt-3" tone="danger">{{ deleteError }}</BaseAlert>
            <div class="mt-5 flex justify-end gap-2">
                <BaseButton :disabled="deleting" @click="closeDelete">キャンセル</BaseButton>
                <BaseButton variant="danger" :loading="deleting" @click="confirmDelete">削除する</BaseButton>
            </div>
        </BaseModal>
    </div>
</template>
