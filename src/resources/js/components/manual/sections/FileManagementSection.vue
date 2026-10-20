<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
</script>

<template>
    <p>
        メニューの<ManualUi variant="menu">ファイル管理</ManualUi
        >を押下します。特権管理者だけが使えます。取り込んだファイルは、アップロードから30日間保存され、過ぎると自動で削除されます。ファイルが削除されても、調査結果とメモは残ります。
    </p>
    <ul class="list-disc space-y-1 pl-5">
        <li>見出しの下に、保存中のファイル数、合計サイズ、保存期限まで7日以内のファイル数が表示されます。</li>
        <li> 調査（初回取込日時）ごとに、ファイルの種類・ファイル名・アップロード日時・サイズ・保存期限までの残り日数が表示されます。残りが7日以内のファイルは、黄色で表示されます。 </li>
        <li>「調査結果を見る」のリンクで、その日の在庫調査結果を開きます。</li>
        <li
            >ファイルの右端のボタンで1件ずつダウンロード・削除できます。<ManualUi icon="folder_zip">一括ダウンロード</ManualUi
            ><ManualUi icon="delete_sweep">一括削除</ManualUi>は、その調査のファイルをまとめて扱います。</li
        >
    </ul>
    <ManualCallout title="ファイルを削除すると" tone="warning">削除したファイルは元に戻せません。その日の調査で、ダウンロードと「保存ファイルで再照合」ができなくなります。</ManualCallout>
</template>
