<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
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
    <div class="flex min-h-screen items-center justify-center bg-canvas bg-blueprint bg-size-[32px_32px] px-4 py-10">
        <div class="w-full max-w-[360px] overflow-hidden rounded-panel border border-line bg-surface shadow-float">
            <div class="h-1.5 bg-hazard" aria-hidden="true"></div>
            <div class="flex items-center gap-2.5 px-6 pt-[22px] pb-1">
                <span class="flex h-7 w-7 items-center justify-center rounded-panel bg-signal font-num text-[17px] font-bold text-ink shadow-[inset_0_-2px_0_rgb(0_0_0/0.12)]" aria-hidden="true"
                    >0</span
                >
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
