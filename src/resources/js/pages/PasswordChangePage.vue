<script setup lang="ts">
import { ref } from "vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import PasswordRules from "@/components/ui/PasswordRules.vue";
import { useAuthStore } from "@/stores/auth";
import { useUiStore } from "@/stores/ui";
import { isValidPassword } from "@/utils/password";

const auth = useAuthStore();
const toast = useUiStore();

const currentPassword = ref("");
const newPassword = ref("");
const confirmPassword = ref("");
const errorMessage = ref("");

async function submit() {
    errorMessage.value = "";

    if (!isValidPassword(newPassword.value)) {
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
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">パスワード変更</h1>
            <p class="mt-1 text-[12.5px] text-ink-2">共通アカウントのパスワードを変更します。次回以降のログインに反映されます。</p>
        </div>

        <BaseCard title="新しいパスワードを設定" description="半角英数字記号で12文字以上にしてください。" :padded="false" class="w-full max-w-[460px]">
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-3.5 px-[18px] py-4">
                    <BaseAlert v-if="errorMessage" tone="danger">{{ errorMessage }}</BaseAlert>

                    <BaseInput v-model="currentPassword" type="password" label="現在のパスワード" autocomplete="current-password" required />

                    <div>
                        <BaseInput v-model="newPassword" type="password" label="新しいパスワード" autocomplete="new-password" required />
                        <PasswordRules :password="newPassword" />
                    </div>

                    <BaseInput v-model="confirmPassword" type="password" label="新しいパスワード（確認）" autocomplete="new-password" required />
                </div>
                <div class="flex justify-end border-t border-line-2 bg-surface-2 px-[18px] py-3">
                    <BaseButton type="submit" variant="primary">変更を保存</BaseButton>
                </div>
            </form>
        </BaseCard>
    </div>
</template>
