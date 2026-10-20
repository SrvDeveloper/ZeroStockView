<script setup lang="ts">
import ManualFigure from "@/components/manual/ManualFigure.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
</script>

<template>
    <!-- データ取込画面「ファイルを添付」の枠（DataImportPage.vue） -->
    <ManualFigure
        label="データ取込画面の「ファイルを添付」の枠。「ファイルを選択」ボタンが黄色の枠で囲まれています。"
        caption="データ取込画面の「ファイルを添付」。黄色の枠が押す場所です（枠にドラッグ＆ドロップしてもかまいません）。"
    >
        <div class="min-w-[460px] rounded-card border border-line bg-surface shadow-card">
            <div class="border-b border-line-2 px-4 py-2.5">
                <p class="text-[13px] font-bold">ファイルを添付</p>
                <p class="text-[11px] text-muted">CSVのヘッダーを確認して、BOSS・ECストック・フリー在庫を自動判定します。</p>
            </div>
            <div class="p-3.5">
                <div class="flex items-center gap-3 rounded-card border-[1.5px] border-dashed border-line-stronger bg-surface-2 p-3.5">
                    <AppIcon name="upload_file" :size="24" class="text-muted" />
                    <div class="flex-1">
                        <p class="font-bold">ここにCSVをドロップ</p>
                        <p class="text-[11px] text-muted">1件から実行できます。（最大3件）</p>
                    </div>
                    <span
                        class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-2.5 py-1 text-[11.5px] font-semibold ring-3 ring-signal ring-offset-2 ring-offset-surface-2"
                        ><AppIcon name="upload_file" :size="14" />ファイルを選択</span
                    >
                    <span class="inline-flex items-center gap-1 rounded-control border border-line-strong bg-surface px-2.5 py-1 text-[11.5px] font-semibold"
                        ><AppIcon name="folder_open" :size="14" />フォルダを選択</span
                    >
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-line-2 bg-surface-2 px-4 py-2.5">
                <span class="text-[11.5px] text-ink-2">添付ファイル: <b class="font-num text-[16px] text-ink">0件</b>（3件まで）</span>
                <span class="inline-flex items-center gap-1 rounded-control bg-ink/40 px-3 py-1.5 text-[11.5px] font-semibold text-white"
                    ><AppIcon name="play_arrow" :size="14" />取込・調査を実行</span
                >
            </div>
        </div>
    </ManualFigure>
</template>
