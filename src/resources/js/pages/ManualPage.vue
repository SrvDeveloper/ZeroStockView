<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualSection from "@/components/manual/ManualSection.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTableOfContents from "@/components/manual/ManualTableOfContents.vue";

const sections = [
    { id: "introduction", title: "はじめに" },
    { id: "basic-operation", title: "基本操作" },
    { id: "notes", title: "注意事項" },
    { id: "faq", title: "よくある質問" },
];
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">操作マニュアル</h1>
            <p class="mt-1 text-sm text-slate-500">システムの操作方法をご案内します。</p>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div class="lg:sticky lg:top-6">
                <ManualTableOfContents :items="sections" />
            </div>

            <div class="min-w-0 space-y-6">
                <!-- 必要に応じて ManualSection を複製し、sections に同じ id とタイトルを追加してください。 -->
                <ManualSection id="introduction" title="はじめに" description="このマニュアルの対象者や前提条件を記載します。" icon="waving_hand">
                    <p class="text-sm leading-7 text-slate-600">ここにマニュアルの概要、利用前に確認してほしいこと、対象となる利用者などを記載してください。</p>
                    <ManualCallout title="補足情報" tone="info">ここに、最初に確認してほしい補足情報を記載してください。</ManualCallout>
                </ManualSection>

                <ManualSection id="basic-operation" title="基本操作" description="操作の流れを手順ごとに記載します。" icon="touch_app">
                    <!-- ManualStep は必要な手順数だけ追加・削除できます。 -->
                    <ManualStep :number="1" title="手順のタイトルを記載">
                        <p>ここに操作内容を記載してください。ボタン名や入力内容を具体的にすると、手順が伝わりやすくなります。</p>
                        <ManualScreenshot caption="必要に応じて画像の説明を記載してください" />
                    </ManualStep>
                    <div class="border-t border-slate-100"></div>
                    <ManualStep :number="2" title="次の手順のタイトルを記載">
                        <p>ここに次の操作内容を記載してください。</p>
                    </ManualStep>
                </ManualSection>

                <ManualSection id="notes" title="注意事項" description="操作時の制約や間違えやすい点を記載します。" icon="error">
                    <ManualCallout title="注意" tone="warning">ここに、操作前に確認が必要な注意事項を記載してください。</ManualCallout>
                    <ManualCallout title="操作のヒント" tone="tip">ここに、作業を進めやすくするヒントを記載してください。</ManualCallout>
                </ManualSection>

                <ManualSection id="faq" title="よくある質問" description="問い合わせの多い内容を質問と回答の形式で記載します。" icon="help">
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        <details class="group p-4" open>
                            <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900 marker:hidden">質問を記載してください</summary>
                            <p class="mt-3 text-sm leading-6 text-slate-600">ここに回答を記載してください。</p>
                        </details>
                        <details class="group p-4">
                            <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900 marker:hidden">質問を追加してください</summary>
                            <p class="mt-3 text-sm leading-6 text-slate-600">ここに回答を記載してください。</p>
                        </details>
                    </div>
                </ManualSection>
            </div>
        </div>
    </div>
</template>
