<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
</script>

<template>
    <p>
        メニューの<ManualUi variant="menu">API連携設定</ManualUi
        >を押下します。特権管理者だけが使えます。外部システムは、このAPIで、在庫推移の日次在庫CSVと同じ項目をJSON形式で取得します。設定を変えたら、右上の<ManualUi variant="primary" icon="check"
            >保存</ManualUi
        >を押下してください。
    </p>
    <ul class="list-disc space-y-1 pl-5">
        <li> API有効状態：<ManualUi>外部APIを有効にする</ManualUi>で、APIを使えるかどうかを切り替えます。有効にするには、APIキーの発行と、許可IPアドレスまたはCIDRの登録（1件以上）が必要です。 </li>
        <li
            >APIキー：<ManualUi icon="refresh">発行</ManualUi>（2回目以降は<ManualUi icon="refresh">再発行</ManualUi
            >）を押下すると、新しいキーが表示されます。再発行すると、それまでのキーは使えなくなります。</li
        >
        <li>
            許可IPアドレス / CIDR：APIに接続できる接続元を登録します。「IP / CIDR」とメモを入力して<ManualUi icon="add">追加</ManualUi>を押下し、<ManualUi variant="primary" icon="check">保存</ManualUi
            >で反映します。接続元のIPアドレスが登録に一致し、APIキーが正しいときだけ接続できます。
        </li>
        <li>接続情報：外部システムの担当者に伝える、パスや認証の方法、回数制限です。<ManualUi icon="api">APIリファレンス</ManualUi>で、指定できるパラメータを確認できます。</li>
    </ul>
    <ManualCallout title="APIキーは発行直後に1回だけ表示されます" tone="warning"
        >画面を離れると、キーの値は二度と表示されません。表示されたらすぐに安全な場所へ控え、外部システムの担当者に伝えてください。控え忘れたときは、再発行して新しいキーを伝え直します。</ManualCallout
    >
</template>
