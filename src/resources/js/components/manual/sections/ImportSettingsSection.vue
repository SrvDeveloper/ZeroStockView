<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <p class="text-[14px] leading-relaxed text-ink">
        メニューの「品番グループ」を押下します。編集者と特権管理者が使えます。すべてのグループに登録した品番が取込の対象になり、同じ品番が複数のグループにあっても1回だけ取り込みます。グループは、在庫調査結果で品番を絞り込むときにも使います。
    </p>

    <div>
        <ManualHeading id="list-view">一覧の見方</ManualHeading>
        <p class="mt-2 text-[14px] leading-relaxed text-ink">
            見出しの下に、グループ数、取込対象の品番数（重複を除く）、取込対象のSKU数が表示されます。一覧には、グループ名、対象品番数（1グループ50件まで）、対象品番（先頭の5件）、最終同期（品番の情報を最後にCrossWalkerから取り込んだ日時）が表示されます。
        </p>
    </div>

    <ManualStep :number="1" title="グループを作成・編集します。">
        <p>「新しいグループを作成」を押下するか、一覧のグループ名か「編集」を押下します。「グループ名」を入力します。</p>
    </ManualStep>
    <ManualStep :number="2" title="品番を追加します。">
        <p>
            「品番を追加」の検索欄に、品番・SKU・ASIN・ターコイズの情報を入力して「検索」を押下します。CrossWalkerに登録されている品番が候補に表示されるので、「追加」を押下します。追加した品番は「追加済み」になり、「取込対象品番」に並びます。
        </p>
    </ManualStep>
    <ManualStep :number="3" title="並び順を整えて保存します。">
        <p>
            「取込対象品番」の行をドラッグすると、並び順を変えられます。この順番は、在庫調査結果でグループを選んだときの表示順になります。対象から外すときは、行の右端のごみ箱のアイコンを押下します。
        </p>
        <p> 最後に「このグループを保存」を押下します。保存していない変更があるあいだは「未保存の変更があります」と表示され、「変更を取り消す」で元に戻せます。 </p>
    </ManualStep>

    <ManualCallout title="グループの削除（特権管理者のみ）" tone="warning"
        >一覧のごみ箱のアイコンで削除します。削除したグループの品番は、ほかのグループに登録されていなければ、次の取込から対象外になります。過去の在庫調査に保存されたグループの情報は残ります。</ManualCallout
    >
</template>
