<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <ManualStep :number="1" title="ターコイズで在庫一覧照会画面を開きます。">
        <p>ターコイズにログインして、「在庫管理」＞「在庫一覧照会」を押下します。</p>
        <ManualScreenshot src="/img/manual/04-1.png" caption="在庫一覧照会画面を開く" />
    </ManualStep>
    <ManualStep :number="2" title="01番倉庫の在庫一覧表をリクエスト">
        <p>倉庫に「01」を指定して、ゼロ在庫出力のチェックを外した状態で検索開始ボタンを押下します。</p>
        <ManualScreenshot src="/img/manual/04-2.png" caption="01番倉庫の在庫一覧表をリクエスト" />
        <ManualCallout title="注意" tone="warning"
            >ゼロ在庫出力のチェックを外さないと、検索結果の表示にかなりの時間がかかります。<br />チェックを外しても検索結果の表示には時間がかかりますので、しばらくお待ちください。</ManualCallout
        >
    </ManualStep>
    <ManualStep :number="3" title="在庫一覧照会表をダウンロード">
        <p>検索結果が表示されたら、CSV出力ボタンを押下して、在庫一覧照会表をダウンロードしてください。</p>
        <ManualScreenshot src="/img/manual/04-3.png" caption="在庫一覧照会表をダウンロード" />
    </ManualStep>
</template>
