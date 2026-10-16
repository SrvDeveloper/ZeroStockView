<script setup lang="ts">
import { useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseButton from "@/components/ui/BaseButton.vue";

const router = useRouter();
</script>

<template>
    <div class="flex min-h-[60vh] items-center justify-center">
        <section class="w-full max-w-[420px] overflow-hidden rounded-card border border-line bg-surface shadow-card">
            <div class="h-1.5 bg-hazard" aria-hidden="true"></div>
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <div class="flex h-11 w-11 items-center justify-center rounded-card bg-tint text-faint"><AppIcon name="wrong_location" :size="22" /></div>
                <p class="mt-3 font-num text-[40px] leading-none font-semibold text-faint" aria-hidden="true">404</p>
                <h1 class="mt-2 text-[15px] font-bold">ページが見つかりません</h1>
                <p class="mt-1 max-w-xs text-[11.5px] text-muted">URL が間違っているか、ページが移動・削除された可能性があります。</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <BaseButton variant="primary" icon="history" @click="router.push({ name: 'survey-history' })">在庫調査履歴へ</BaseButton>
                    <BaseButton variant="secondary" icon="upload" @click="router.push({ name: 'data-import' })">データ取込へ</BaseButton>
                </div>
            </div>
        </section>
    </div>
</template>
