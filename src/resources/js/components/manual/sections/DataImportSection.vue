<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <p class="text-[14px] leading-relaxed text-ink">
        「データ取込」画面は、編集者と特権管理者が使えます。取込の対象は、すべての品番グループに登録した品番です（同じ品番が複数のグループにあっても、1回だけ取り込みます）。Amazon在庫はファイルでは取り込みません。「Amazon在庫の取得」を参照してください。
    </p>
    <ManualStep :number="1" title="データ取込画面を開きます。">
        <p>メニューの「データ取込」を押下します。</p>
        <p>品番グループが1件もないときは「品番グループがありません。」と表示されます。先に「品番グループ」画面で対象の品番を登録してください。</p>
    </ManualStep>
    <ManualStep :number="2" title="ファイルを添付します。">
        <p> 「取込ファイルの準備①〜③」でダウンロードしたCSVを、「ここにCSVをドロップ」の枠にドラッグ＆ドロップします。「ファイルを選択」「フォルダを選択」から選ぶこともできます。 </p>
        <ul class="list-disc space-y-0.5 pl-5">
            <li>1回に添付できるのは1〜3件です。3種類がそろっていなくても取り込めます。</li>
            <li>ファイルの種類（BOSS・ECストック・フリー在庫）は、CSVの1行目の見出しから自動で判定します。ファイル名を変える必要はありません。</li>
            <li>間違えて添付したファイルは、ファイル名の右の「×」で外せます。</li>
        </ul>
    </ManualStep>
    <ManualStep :number="3" title="取込を実行します。">
        <p>「取込・調査を実行」を押下します。完了すると「取込・調査が完了しました」と表示され、在庫調査結果画面が開きます。</p>
        <ManualCallout title="取込に失敗したとき" tone="warning">
            「取込に失敗しました」と、原因が表示されます。このとき、在庫調査は作成も更新もされません。原因を直してから、もう一度実行してください。
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                <li>「〜の種類を内容から判定できません。」：BOSS・ターコイズから出力したCSVを、手を加えずに添付しているか確認してください。</li>
                <li>「〜のファイルが複数あります。」：同じ種類のファイルを2件以上添付しています。1件だけにしてください。</li>
                <li>「〜行目の数量が…」：ファイルの数量の列に、数値でない値やマイナスの値があります。出力し直してください。</li>
            </ul>
        </ManualCallout>
    </ManualStep>

    <div>
        <ManualHeading id="same-day">同じ日に追加で取り込むとき</ManualHeading>
        <p class="mt-2 text-[14px] leading-relaxed text-ink">
            在庫調査は1日1件です。取込を実行した日の調査がすでにあるときは、新しい調査は作られず、その調査が更新されます（初回取込日時は変わりません）。
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-[14px] leading-relaxed text-ink">
            <li>添付した種類は、新しいファイルの内容に置き換わります。</li>
            <li>
                添付しなかった種類は、その日に前回取り込んだファイルをそのまま使います。たとえば、午前にBOSSだけを取り込み、午後にECストックとフリー在庫を取り込むと、3種類がそろった調査になります。
            </li>
            <li>その日に一度も取り込んでいない種類は「取込待ち」になり、在庫表では「—」と表示されます。取込待ちは在庫0ではなく、まだ数がわからない状態です。</li>
        </ul>
    </div>
</template>
