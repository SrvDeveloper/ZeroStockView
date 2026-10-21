<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppLogo from "@/components/AppLogo.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import { useAuthStore } from "@/stores/auth";
import { EDITOR_ROLES, canAccess, homeRouteName } from "@/constants/roles";

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
    const result = await auth.login(loginId.value, password.value);
    if (result !== true) {
        submitting.value = false;
        errorMessage.value = result;
        return;
    }
    await Promise.allSettled([surveys.load(), ...(canAccess(auth.role, EDITOR_ROLES) ? [importSettings.load()] : [])]);
    submitting.value = false;
    const redirect = typeof route.query.redirect === "string" ? route.query.redirect : undefined;
    await router.push(redirect ?? { name: homeRouteName(auth.user!.role) });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-canvas bg-blueprint bg-size-[32px_32px] px-4 py-10">
        <div class="w-full max-w-[360px] overflow-hidden rounded-panel border border-line bg-surface shadow-float">
            <div class="h-1.5 bg-hazard" aria-hidden="true"></div>
            <div class="flex items-center gap-2.5 px-6 pt-[22px] pb-1">
                <AppLogo :size="28" />
                <div class="leading-tight">
                    <h1 class="text-[15px] font-semibold text-ink">ZeroStockView</h1>
                    <p class="text-[10.5px] text-muted">在庫状況調査アプリ</p>
                </div>
            </div>

            <form class="flex flex-col gap-3 px-6 pt-4 pb-6" @submit.prevent="submit">
                <BaseAlert v-if="errorMessage" tone="danger">{{ errorMessage }}</BaseAlert>

                <BaseInput v-model="loginId" class="[&_input]:font-mono" label="ログインID" autocomplete="username" placeholder="admin" />
                <BaseInput v-model="password" type="password" label="パスワード" autocomplete="current-password" placeholder="••••••••••••" />

                <BaseButton type="submit" variant="primary" block class="mt-1 h-9" :loading="submitting">
                    {{ submitting ? "ログイン中…" : "ログイン" }}
                </BaseButton>
            </form>

            <p class="border-t border-line-2 bg-surface-2 px-6 py-2.5 font-mono text-[10px] tracking-wide text-muted">SUNREEVE CO., LTD.</p>
        </div>
    </div>
</template>
