<script setup lang="ts">
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
</script>

<template>
    <ManualStep :number="1" title="在庫・商品一覧画面で商品一覧を表示します。">
        <p>
            BOSSにログインして、在庫・商品一覧画面を開き、検索条件を変更しないで検索ボタンを押下します。<br />
            ログイン済みの場合は下記URLからアクセス可能です。<br />
            <a href="https://boss-oms.jp/BOSSWeb/ItemInventoryList" target="_blank" class="font-mono text-xs text-ink underline underline-offset-2 hover:text-ink-2"
                >https://boss-oms.jp/BOSSWeb/ItemInventoryList</a
            >
        </p>
        <ManualScreenshot src="/img/manual/02-1.png" caption="在庫・商品一覧画面を開く" />
    </ManualStep>
    <ManualStep :number="2" title="倉庫毎の在庫数レポートをリクエスト">
        <p>
            「商品・在庫情報CSV出力」を押下して、出力テンプレートは「倉庫毎の在庫数」を選択します。<br />
            倉庫選択をすべて選択した状態で、「実行」を押下します。
        </p>
        <ManualScreenshot src="/img/manual/02-2.png" caption="倉庫毎の在庫数レポートをリクエスト" />
    </ManualStep>
    <ManualStep :number="3" title="倉庫毎の在庫数レポートをダウンロード">
        <p>処理が完了するとファイル名が表示されるので、押下してダウンロードしてください。</p>
        <ManualScreenshot src="/img/manual/02-3.png" caption="倉庫毎の在庫数レポートをダウンロード" />
    </ManualStep>
</template>
