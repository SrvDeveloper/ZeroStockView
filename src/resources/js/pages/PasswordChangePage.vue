<script setup lang="ts">
import { computed, ref } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import { useAuthStore } from "@/stores/auth";
import { useUiStore } from "@/stores/ui";

const auth = useAuthStore();
const toast = useUiStore();

const currentPassword = ref("");
const newPassword = ref("");
const confirmPassword = ref("");
const errorMessage = ref("");

const MIN_LENGTH = 12;
const VALID_PATTERN = /^[\x21-\x7e]+$/;

const rules = computed(() => [
    { label: `${MIN_LENGTH}文字以上`, valid: newPassword.value.length >= MIN_LENGTH },
    { label: "半角英数字記号のみ", valid: newPassword.value.length > 0 && VALID_PATTERN.test(newPassword.value) },
]);

async function submit() {
    errorMessage.value = "";

    if (newPassword.value.length < MIN_LENGTH || !VALID_PATTERN.test(newPassword.value)) {
        errorMessage.value = "新しいパスワードは半角英数字記号12文字以上で入力してください。";
        return;
    }
    if (newPassword.value !== confirmPassword.value) {
        errorMessage.value = "新しいパスワード（確認）が一致しません。";
        return;
    }

    try {
        await auth.changePassword(currentPassword.value, newPassword.value);
    } catch {
        errorMessage.value = "現在のパスワードが正しくありません。";
        return;
    }
    toast.push("パスワードを変更しました");
    currentPassword.value = "";
    newPassword.value = "";
    confirmPassword.value = "";
}
</script>

<template>
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">パスワード変更</h1>
            <p class="mt-1 text-sm text-slate-500">共通アカウントのパスワードを変更します。次回以降のログインに反映されます。</p>
        </div>

        <BaseCard>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <BaseAlert v-if="errorMessage" tone="danger">{{ errorMessage }}</BaseAlert>

                <BaseInput v-model="currentPassword" type="password" label="現在のパスワード" autocomplete="current-password" required />

                <div>
                    <BaseInput v-model="newPassword" type="password" label="新しいパスワード" autocomplete="new-password" required />
                    <ul class="mt-1.5 flex flex-col gap-0.5">
                        <li v-for="rule in rules" :key="rule.label" class="flex items-center gap-1.5 text-xs" :class="rule.valid ? 'text-emerald-600' : 'text-slate-400'">
                            <AppIcon :name="rule.valid ? 'check_circle' : 'radio_button_unchecked'" :size="14" />
                            {{ rule.label }}
                        </li>
                    </ul>
                </div>

                <BaseInput v-model="confirmPassword" type="password" label="新しいパスワード（確認）" autocomplete="new-password" required />

                <BaseButton type="submit" variant="primary" block>変更を保存</BaseButton>
            </form>
        </BaseCard>
    </div>
</template>
