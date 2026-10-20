<script setup lang="ts">
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <ManualStep :number="1" title="ターコイズでKEEP一覧表出力画面を開きます。">
        <p>ターコイズにログインして、「在庫管理」＞「KEEP一覧表」を押下します。</p>
        <ManualScreenshot src="/img/manual/03-1.png" caption="KEEP一覧表出力画面を開く" />
    </ManualStep>
    <ManualStep :number="2" title="ECストック倉庫のKEEP一覧表を出力">
        <p>得意先に「59」を指定して、CSV出力ボタンを押下します。</p>
        <ManualScreenshot src="/img/manual/03-2.png" caption="59番倉庫のKEEP一覧表をリクエスト" />
    </ManualStep>
    <ManualStep :number="3" title="KEEP一覧表をダウンロード">
        <p>処理が完了するとCSV出力が表示されるので、出力を実行します。</p>
        <ManualScreenshot src="/img/manual/03-3.png" caption="KEEP一覧表をダウンロード" />
    </ManualStep>
</template>
