<script setup lang="ts">
import ManualResult from "@/components/manual/ManualResult.vue";
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <p>
        メニューの<ManualUi variant="menu">品番グループ</ManualUi
        >を押下します。編集者と特権管理者が使えます。グループは、在庫調査結果で品番を絞り込んだり、表示順を決めたりするために使います。取込の対象はグループに関係なく、CrossWalkerで有効な全品番です。
    </p>

    <div>
        <ManualHeading id="list-view">一覧の見方</ManualHeading>
        <p class="mt-3">
            見出しの下に、グループ数、グループに入っている品番数（重複を除く）、グループに入っているSKU数が表示されます。一覧には、グループ名、対象品番数（1グループ50件まで）、対象品番（先頭の5件）、最終同期（品番の情報を最後にCrossWalkerから取り込んだ日時）が表示されます。
        </p>
    </div>

    <div class="space-y-6">
        <ManualHeading id="edit">グループを作成・編集する</ManualHeading>
        <ManualStep :number="1" title="グループを作成・編集します。">
            <p><ManualUi variant="primary" icon="add">新しいグループを作成</ManualUi>を押下するか、一覧のグループ名か<ManualUi icon="edit">編集</ManualUi>を押下します。「グループ名」を入力します。</p>
        </ManualStep>
        <ManualStep :number="2" title="品番を追加します。">
            <p>
                「品番を追加」の検索欄に、品番・SKU・ASIN・ターコイズの情報を入力して<ManualUi icon="search">検索</ManualUi
                >を押下します。CrossWalkerに登録されている品番が候補に表示されるので、<ManualUi icon="add">追加</ManualUi>を押下します。追加した品番は<ManualUi icon="check">追加済み</ManualUi
                >になり、「グループの品番」に並びます。
            </p>
        </ManualStep>
        <ManualStep :number="3" title="並び順を整えて保存します。">
            <p>
                「グループの品番」の行をドラッグすると、並び順を変えられます。この順番は、在庫調査結果でグループを選んだときの表示順になります。対象から外すときは、行の右端のごみ箱のアイコンを押下します。
            </p>
            <p>
                最後に<ManualUi variant="primary">このグループを保存</ManualUi
                >を押下します。保存していない変更があるあいだは「未保存の変更があります」と表示され、<ManualUi>変更を取り消す</ManualUi>で元に戻せます。
            </p>
            <ManualResult>「品番グループを保存しました」と表示され、一覧に戻ります。</ManualResult>
        </ManualStep>
    </div>

    <ManualCallout title="グループの削除（特権管理者のみ）" tone="warning"
        >一覧のごみ箱のアイコンで削除します。削除したグループの品番は、ほかのグループに登録されていなければ、次の取込から対象外になります。過去の在庫調査に保存されたグループの情報は残ります。</ManualCallout
    >
</template>
