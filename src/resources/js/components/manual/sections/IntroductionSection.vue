<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";

/** 権限ごとにできること（docs/ユーザー管理実装/README.md の確定要件） */
const rolePermissions: { feature: string; viewer: boolean; editor: boolean; admin: boolean }[] = [
    { feature: "在庫調査履歴・在庫調査結果・在庫推移の閲覧", viewer: true, editor: true, admin: true },
    { feature: "Excel・CSVの出力、取込ファイルのダウンロード", viewer: true, editor: true, admin: true },
    { feature: "操作マニュアルの閲覧", viewer: true, editor: true, admin: true },
    { feature: "データ取込、保存ファイルでの再照合", viewer: false, editor: true, admin: true },
    { feature: "品番グループの追加・編集", viewer: false, editor: true, admin: true },
    { feature: "連携ステータスの確認", viewer: false, editor: true, admin: true },
    { feature: "品番グループの削除", viewer: false, editor: false, admin: true },
    { feature: "Amazon在庫の手動取得", viewer: false, editor: false, admin: true },
    { feature: "調査結果のメモの編集、調査履歴・取込ファイルの削除", viewer: false, editor: false, admin: true },
    { feature: "ファイル管理・API連携設定・ユーザー管理", viewer: false, editor: false, admin: true },
];
</script>

<template>
    <p>
        ZeroStockView（在庫状況調査システム）は、CrossWalkerで有効な全品番のSKUごとに、Amazon（自社発送・FBA）、BOSS（自社倉庫・RFC倉庫）、フリー在庫、ECストックの在庫数をまとめて確認するシステムです。在庫数は1日1件の「在庫調査」として保存され、過去の日の在庫を見比べたり、在庫推移として確認したりできます。
    </p>

    <div>
        <ManualHeading id="daily-flow">1日の作業の流れ</ManualHeading>
        <ol class="mt-3 list-decimal space-y-1 pl-5">
            <li>毎朝9:00に、Amazon在庫がSP-APIから自動で取得されます。操作は不要です。</li>
            <li> BOSSとターコイズから、取込に使う3種類のファイルをダウンロードします。</li>
            <li><ManualUi variant="menu">データ取込</ManualUi>画面でファイルを添付して取り込みます。同じ日に2回目以降に取り込んだときは同じ調査が更新されます。</li>
            <li><ManualUi variant="menu">在庫調査履歴</ManualUi>から調査結果を開き、在庫ゼロのSKUなどを確認します。必要に応じてExcelに出力します。</li>
        </ol>
    </div>
    <ManualCallout title="取込の順番" tone="tip">Amazon在庫の取得とファイルの取込は、どちらが先でもかまいません。同じ日の在庫調査にまとめて反映されます。</ManualCallout>

    <div>
        <ManualHeading id="role-permissions">権限ごとにできること</ManualHeading>
        <p class="mt-3">
            ユーザーには「閲覧者」「編集者」「特権管理者」のいずれかの権限が設定されています。使えない操作のボタンやメニューは表示されません。権限の変更は特権管理者に依頼してください。
        </p>
        <div class="mt-2 overflow-x-auto rounded-card border border-line">
            <table class="w-full min-w-120 text-left text-[13px]" data-role-permissions>
                <thead class="border-b border-line bg-surface-2 text-[11.5px] text-muted">
                    <tr>
                        <th class="px-3 py-2 font-semibold">できること</th>
                        <th class="w-20 px-3 py-2 text-center font-semibold">閲覧者</th>
                        <th class="w-20 px-3 py-2 text-center font-semibold">編集者</th>
                        <th class="w-24 px-3 py-2 text-center font-semibold">特権管理者</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2 text-ink">
                    <tr v-for="row in rolePermissions" :key="row.feature">
                        <td class="px-3 py-2">{{ row.feature }}</td>
                        <td v-for="allowed in [row.viewer, row.editor, row.admin]" :key="String(allowed) + row.feature" class="px-3 py-2 text-center">
                            <span v-if="allowed" class="font-semibold text-ink">○</span>
                            <span v-else class="text-faint">－</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ManualCallout id="crosswalker" class="scroll-mt-32 lg:scroll-mt-20" title="品番は先にCrossWalkerへ登録してください" tone="warning"
        >取込の対象は、商品識別子管理システム「CrossWalker」で有効な全品番です。CrossWalkerに登録されていない品番は取り込めず、品番グループにも追加できません。新しい品番を調べるときは、先にCrossWalkerへ登録してください。</ManualCallout
    >
</template>
