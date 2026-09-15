<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import { useAuthStore } from "@/stores/auth";

import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
const auth = useAuthStore();
const importSettings = useImportSettingsStore();
const surveys = useSurveysStore();
const router = useRouter();
const route = useRoute();

const loginId = ref("");
const password = ref("");
const errorMessage = ref("");
const submitting = ref(false);

async function submit() {
    errorMessage.value = "";
    if (!loginId.value || !password.value) {
        errorMessage.value = "ログインIDとパスワードを入力してください。";
        return;
    }
    submitting.value = true;
    const ok = await auth.login(loginId.value, password.value);
    if (!ok) {
        submitting.value = false;
        errorMessage.value = "ログインIDまたはパスワードが正しくありません。";
        return;
    }
    await Promise.allSettled([importSettings.load(), surveys.load()]);
    submitting.value = false;
    const redirect = typeof route.query.redirect === "string" ? route.query.redirect : undefined;
    await router.push(redirect ?? { name: "data-import" });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center gap-2 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600 text-white">
                    <AppIcon name="counter_0" :size="26" filled />
                </span>
                <h1 class="text-lg font-semibold text-slate-900">ZeroStockView</h1>
                <p class="text-sm text-slate-500">ゼロ在庫照会システム</p>
            </div>

            <BaseCard>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <BaseAlert v-if="errorMessage" tone="danger">{{ errorMessage }}</BaseAlert>

                    <BaseInput v-model="loginId" label="ログインID" autocomplete="username" placeholder="admin" />
                    <BaseInput v-model="password" type="password" label="パスワード" autocomplete="current-password" placeholder="••••••••••••" />

                    <BaseButton type="submit" variant="primary" block :loading="submitting">
                        {{ submitting ? "ログイン中…" : "ログイン" }}
                    </BaseButton>
                </form>
            </BaseCard>

            <p class="mt-4 text-center text-xs text-slate-400">
                デモ用アカウント: ID <code class="rounded bg-slate-200 px-1 py-0.5">admin</code> / パスワード
                <code class="rounded bg-slate-200 px-1 py-0.5">password1234</code>
            </p>
        </div>
    </div>
</template>
