<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import type { UserRole } from "@/constants/roles";

interface ManagedUser {
    id: string;
    loginId: string;
    role: UserRole;
}

const users = ref<ManagedUser[]>([]);
const loading = ref(true);
const failed = ref(false);
const roleLabels: Record<UserRole, string> = { admin: "特権管理者", editor: "編集者", viewer: "閲覧者" };

onMounted(async () => {
    try {
        const { data } = await axios.get<{ users: ManagedUser[] }>("/api/users");
        users.value = data.users;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">ユーザー管理</h1>
            <p class="mt-1 text-[12.5px] text-ink-2">登録済みのユーザーと権限を確認できます。</p>
        </div>

        <BaseAlert v-if="failed" tone="danger">ユーザー一覧を取得できませんでした。</BaseAlert>
        <BaseCard v-else :padded="false">
            <p v-if="loading" class="px-4 py-5 text-sm text-muted">読み込み中…</p>
            <p v-else-if="users.length === 0" class="px-4 py-5 text-sm text-muted">ユーザーがいません。</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-line text-[11px] text-muted">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">ログインID</th>
                            <th class="px-4 py-2.5 font-semibold">権限</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-2">
                        <tr v-for="user in users" :key="user.id">
                            <td class="px-4 py-3 font-mono text-ink">{{ user.loginId }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ roleLabels[user.role] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>
    </div>
</template>
