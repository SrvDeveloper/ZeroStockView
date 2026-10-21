<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { createUser, deleteUser, fetchUsers, unlockUser, updateUser, type ManagedUser, type UserInput } from "@/api/users";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import PasswordRules from "@/components/ui/PasswordRules.vue";
import { ADMIN_ROLES, ALL_ROLES, ROLE_DESCRIPTIONS, ROLE_LABELS, canAccess } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { formatDateTime } from "@/utils/format";
import { isValidPassword } from "@/utils/password";

const router = useRouter();
const auth = useAuthStore();
const toast = useUiStore();
const canManageUsers = computed(() => canAccess(auth.role, ADMIN_ROLES));
const users = ref<ManagedUser[]>([]);
const loading = ref(true);
const listError = ref("");
const adminCount = computed(() => users.value.filter((user) => user.role === "admin").length);

const formOpen = ref(false);
const editingId = ref<string | null>(null);
const editingUser = computed(() => users.value.find((user) => user.id === editingId.value) ?? null);
const form = reactive<UserInput>({ loginId: "", password: "", passwordConfirmation: "", role: "viewer" });
const fieldErrors = reactive({ loginId: "", password: "", passwordConfirmation: "", role: "" });
const formError = ref("");
const saving = ref(false);
/** 編集ではパスワード欄を畳んでおき、再設定するときだけ開く。新規作成では常に開く */
const passwordOpen = ref(false);
const roleChanging = computed(() => editingUser.value !== null && form.role !== editingUser.value.role);
const lastAdminDemotion = computed(() => editingUser.value?.role === "admin" && adminCount.value <= 1 && form.role !== "admin");

const unlockingId = ref<string | null>(null);

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
    passwordOpen.value = user === null;
    clearFormErrors();
    formOpen.value = true;
}

function closeForm(): void {
    if (saving.value) return;
    formOpen.value = false;
    form.password = "";
    form.passwordConfirmation = "";
}

function closePasswordReset(): void {
    passwordOpen.value = false;
    form.password = "";
    form.passwordConfirmation = "";
    fieldErrors.password = "";
    fieldErrors.passwordConfirmation = "";
}

function validateForm(): boolean {
    clearFormErrors();
    const loginId = form.loginId.trim();
    if (!loginId) fieldErrors.loginId = "ログインIDを入力してください。";
    else if (loginId.length > 100) fieldErrors.loginId = "ログインIDは100文字以内で入力してください。";
    if (!editingId.value && !form.password) fieldErrors.password = "初期パスワードを入力してください。";
    else if (passwordOpen.value && !form.password) fieldErrors.password = "新しいパスワードを入力してください。";
    else if (form.password && !isValidPassword(form.password)) fieldErrors.password = "パスワードは半角英数字記号12文字以上で入力してください。";
    if (passwordOpen.value && !form.passwordConfirmation) fieldErrors.passwordConfirmation = "確認用パスワードを入力してください。";
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

async function unlock(user: ManagedUser): Promise<void> {
    if (!canManageUsers.value || unlockingId.value !== null) return;
    unlockingId.value = user.id;
    try {
        const saved = await unlockUser(user.id);
        const index = users.value.findIndex((candidate) => candidate.id === saved.id);
        if (index >= 0) users.value[index] = saved;
        toast.push(`${saved.loginId} のロックを解除しました`);
    } catch {
        toast.push("ロックを解除できませんでした。時間をおいて再度お試しください。", "error");
    } finally {
        unlockingId.value = null;
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">ユーザー管理</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">ログインできるユーザーと権限を管理します。</p>
            </div>
            <BaseButton variant="primary" icon="person_add" @click="openForm(null)">ユーザーを追加</BaseButton>
        </div>

        <BaseAlert v-if="listError" tone="danger">
            <div class="flex items-center gap-3">
                <span>{{ listError }}</span>
                <BaseButton size="sm" icon="refresh" @click="loadUsers">再読み込み</BaseButton>
            </div>
        </BaseAlert>
        <BaseCard v-else :title="loading ? 'ユーザー' : `ユーザー ${users.length}人`" :padded="false">
            <div v-if="loading" class="flex min-h-32 items-center justify-center gap-2 text-[12.5px] text-muted" role="status">
                <AppIcon name="progress_activity" :size="20" class="animate-spin text-ink-2" />
                ユーザーを読み込んでいます...
            </div>
            <BaseEmpty v-else-if="users.length === 0" icon="group" title="ユーザーがいません" description="「ユーザーを追加」から作成してください。" />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-120 text-left text-xs">
                    <thead class="border-b border-line text-[11px] text-muted">
                        <tr>
                            <th class="px-[18px] py-2.5 font-semibold">ログインID</th>
                            <th class="px-4 py-2.5 font-semibold">権限</th>
                            <th class="w-36 px-[18px] py-2.5 text-right font-semibold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-2">
                        <tr v-for="user in users" :key="user.id" :data-user-id="user.id" class="hover:bg-surface-2">
                            <td class="px-[18px] py-2">
                                <span class="inline-flex items-center gap-2">
                                    <span class="font-mono text-[12.5px] font-medium text-ink">{{ user.loginId }}</span>
                                    <BaseBadge v-if="user.id === auth.user?.id" data-current-user>自分</BaseBadge>
                                    <BaseBadge v-if="user.lockedUntil" tone="danger" data-locked-user :title="`ログインに続けて失敗したため、${formatDateTime(user.lockedUntil)}までロックされています`"
                                        >ロック中（{{ formatDateTime(user.lockedUntil) }}まで）</BaseBadge
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-2" :data-user-role="user.role">
                                <BaseBadge :tone="user.role === 'admin' ? 'brand' : 'neutral'">{{ ROLE_LABELS[user.role] }}</BaseBadge>
                            </td>
                            <td class="px-[18px] py-2">
                                <div class="flex items-center justify-end gap-1">
                                    <BaseButton
                                        v-if="user.lockedUntil"
                                        variant="ghost"
                                        size="sm"
                                        icon="lock_open"
                                        title="ロックを解除"
                                        :aria-label="`${user.loginId}のロックを解除`"
                                        :loading="unlockingId === user.id"
                                        :disabled="unlockingId !== null && unlockingId !== user.id"
                                        @click="unlock(user)"
                                    />
                                    <BaseButton variant="ghost" size="sm" icon="edit" title="編集" :aria-label="`${user.loginId}を編集`" @click="openForm(user)" />
                                    <span :title="deleteBlockedReason(user) || '削除'" :data-delete-blocked="deleteBlockedReason(user) || undefined">
                                        <BaseButton
                                            variant="ghost"
                                            size="sm"
                                            icon="delete"
                                            class="text-danger!"
                                            :aria-label="`${user.loginId}を削除`"
                                            :disabled="deleteBlockedReason(user) !== ''"
                                            @click="requestDelete(user)"
                                        />
                                    </span>
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
            :description="editingId ? 'ログインIDと権限を変更できます。パスワードは必要なときだけ再設定します。' : 'ログインID・権限・初期パスワードを設定します。'"
            @close="closeForm"
        >
            <form class="flex flex-col gap-4" novalidate @submit.prevent="saveUser">
                <BaseAlert v-if="formError" tone="danger">{{ formError }}</BaseAlert>
                <BaseInput v-model="form.loginId" label="ログインID" class="[&_input]:font-mono" :error="fieldErrors.loginId" autocomplete="username" required :disabled="saving" />

                <fieldset :disabled="saving">
                    <legend class="mb-1.5 text-[11px] font-medium text-ink-2">権限</legend>
                    <div class="flex flex-col gap-1.5" role="radiogroup" aria-label="権限">
                        <label
                            v-for="role in ALL_ROLES"
                            :key="role"
                            :data-role-option="role"
                            class="flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2 transition-colors has-focus-visible:outline-2 has-focus-visible:outline-ink-2"
                            :class="form.role === role ? 'border-ink bg-surface-2 shadow-[inset_0_0_0_1px_var(--color-ink)]' : 'border-line-strong bg-surface hover:bg-surface-2'"
                        >
                            <input v-model="form.role" type="radio" name="managed-user-role" :value="role" class="mt-0.5 accent-ink" />
                            <span class="flex flex-col">
                                <span class="text-[12.5px] font-semibold text-ink">{{ ROLE_LABELS[role] }}</span>
                                <span class="text-[11px] text-muted">{{ ROLE_DESCRIPTIONS[role] }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="fieldErrors.role" class="mt-1 text-[11px] text-danger">{{ fieldErrors.role }}</p>
                </fieldset>
                <BaseAlert v-if="lastAdminDemotion" tone="warning">最後の特権管理者は降格できません。先に別の特権管理者を作成してください。</BaseAlert>
                <BaseAlert v-else-if="roleChanging" tone="warning"
                    >権限を変更すると、このユーザーの全セッションが即時終了します。{{
                        editingId === auth.user?.id ? "自分の権限を変更した場合はログイン画面に戻ります。" : "再ログインが必要です。"
                    }}</BaseAlert
                >

                <div v-if="passwordOpen" class="flex flex-col gap-3" data-password-fields>
                    <div class="flex items-end justify-between gap-2">
                        <span class="text-[11px] font-medium text-ink-2">{{ editingId ? "パスワードの再設定" : "初期パスワード" }}</span>
                        <BaseButton v-if="editingId" variant="ghost" size="sm" :disabled="saving" @click="closePasswordReset">再設定をやめる</BaseButton>
                    </div>
                    <div>
                        <BaseInput
                            v-model="form.password"
                            type="password"
                            :label="editingId ? '新しいパスワード' : 'パスワード'"
                            :error="fieldErrors.password"
                            autocomplete="new-password"
                            required
                            :disabled="saving"
                        />
                        <PasswordRules :password="form.password" />
                    </div>
                    <BaseInput
                        v-model="form.passwordConfirmation"
                        type="password"
                        label="パスワード（確認）"
                        :error="fieldErrors.passwordConfirmation"
                        autocomplete="new-password"
                        required
                        :disabled="saving"
                    />
                </div>
                <div v-else>
                    <BaseButton variant="secondary" size="sm" icon="key" data-testid="open-password-reset" :disabled="saving" @click="passwordOpen = true">パスワードを再設定する</BaseButton>
                </div>

                <div class="flex justify-end gap-2 border-t border-line-2 pt-3">
                    <BaseButton :disabled="saving" @click="closeForm">キャンセル</BaseButton>
                    <BaseButton type="submit" variant="primary" :loading="saving">{{ editingId ? "変更を保存" : "ユーザーを作成" }}</BaseButton>
                </div>
            </form>
        </BaseModal>

        <BaseModal :open="pendingDelete !== null" title="ユーザーを削除" width="sm" @close="closeDelete">
            <p class="text-[13px] leading-relaxed text-ink-2">
                <span class="font-mono font-semibold text-ink">{{ pendingDelete?.loginId }}</span> を削除します。このユーザーの全セッションは即時終了します。元に戻せません。
            </p>
            <BaseAlert v-if="deleteError" class="mt-3" tone="danger">{{ deleteError }}</BaseAlert>
            <div class="mt-5 flex justify-end gap-2">
                <BaseButton :disabled="deleting" @click="closeDelete">キャンセル</BaseButton>
                <BaseButton variant="danger" :loading="deleting" @click="confirmDelete">削除する</BaseButton>
            </div>
        </BaseModal>
    </div>
</template>
