<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** データ取込画面「Amazon 在庫」欄の状態（DataImportPage.vue の表示文言） */
const amazonImportStatuses = [
    { term: "受付", meaning: "取得の依頼を受け付け、開始を待っています。" },
    { term: "処理中", meaning: "Amazonから在庫レポートを取得しています。" },
    { term: "成功", meaning: "取得が終わり、その日の在庫調査に反映しました。" },
    { term: "失敗", meaning: "取得できませんでした。結果の欄に理由が表示されます。" },
];
/** 在庫調査結果画面「Amazon 在庫」の表示（SurveyResultPage.vue の表示文言） */
const amazonSurveyStatuses = [
    { term: "取込済み", meaning: "その日のAmazon在庫を反映済みです。" },
    { term: "取込待ち", meaning: "その日のAmazon在庫がまだ取得できていません。取得に成功すると自動で反映されます。在庫調査結果画面は30秒ごとに自動更新します。" },
    {
        term: "一部未取得",
        meaning: "取得は成功しましたが、Amazonの在庫レポートに含まれていないSKUがあります。そのSKUのAmazon在庫は「取込待ち」と表示され、在庫総数は未確定になります。",
    },
];
</script>

<template>
    <div>
        <ManualHeading id="scheduled-fetch">毎朝9:00の自動取得</ManualHeading>
        <p class="mt-3">
            毎朝9:00に、品番グループに登録した品番のAmazon在庫を自動で取得します。Amazon側で在庫レポートを作成するため、完了までに時間がかかることがあります。取得が成功すると、その日の在庫調査に反映されます。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>その日の在庫調査がすでにあるときは、その調査に反映します。</li>
            <li> その日の在庫調査がまだないときは、Amazon在庫だけが入った調査を作成します。BOSS・ECストック・フリー在庫は取込待ちになり、あとでデータ取込を行うと同じ調査に追加されます。 </li>
            <li>取得より先にデータ取込を行ったときは、調査のAmazon在庫は取込待ちになり、取得が成功した時点で反映されます。</li>
            <li>ASINが登録されていないSKUは、Amazon在庫の取得対象外です。在庫表では「対象外」と表示されます。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="import-status">取得の状態を確認する</ManualHeading>
        <p class="mt-3">
            <ManualUi variant="menu">データ取込</ManualUi>画面の「Amazon
            在庫」欄で確認します。「最新の実行」に状態と、定期実行（毎朝の自動取得）か手動実行かが表示されます。「在庫基準日時」は、Amazon在庫がいつ時点の数かを示します。下の一覧には、直近5回の取得の開始日時・起動（定期／手動）・結果が表示されます。
        </p>
        <ManualTermTable class="mt-4" :rows="amazonImportStatuses" term-label="状態" data-amazon-import-statuses />
    </div>

    <div>
        <ManualHeading id="manual-fetch">手動で取得する（特権管理者のみ）</ManualHeading>
        <p class="mt-3">
            自動取得が失敗したときや、9:00以降に品番グループへ品番を追加したときは、「Amazon 在庫」欄の<ManualUi icon="refresh">Amazon 在庫を取得</ManualUi
            >を押下して取り直します。完了するまで、画面は自動で更新されます。受付・処理中の取得があるあいだは押せません。同じ日に何回取得しても、調査にはその日に成功した最新の在庫が反映されます。
        </p>
    </div>
    <ManualCallout title="取り直しは同じ日のうちに行ってください" tone="warning"
        >取得した在庫は、取得した日の在庫調査にだけ反映されます。翌日に取得しても、前の日の調査は取込待ちのまま変わりません。</ManualCallout
    >

    <div>
        <ManualHeading id="survey-display">在庫調査結果での表示</ManualHeading>
        <p class="mt-3">在庫調査結果画面の上部の「Amazon 在庫」に、その調査へのAmazon在庫の反映状況が表示されます。</p>
        <ManualTermTable class="mt-4" :rows="amazonSurveyStatuses" data-amazon-survey-statuses />
    </div>
</template>
