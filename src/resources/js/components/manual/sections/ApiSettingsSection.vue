<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
</script>

<template>
    <p class="text-[14px] leading-relaxed text-ink">
        メニューの「API連携設定」を押下します。特権管理者だけが使えます。外部システムは、このAPIで、在庫推移の日次在庫CSVと同じ項目をJSON形式で取得します。設定を変えたら、右上の「保存」を押下してください。
    </p>
    <ul class="list-disc space-y-1 pl-5 text-[14px] leading-relaxed text-ink">
        <li> API有効状態：「外部APIを有効にする」で、APIを使えるかどうかを切り替えます。有効にするには、APIキーの発行と、許可IPアドレスまたはCIDRの登録（1件以上）が必要です。 </li>
        <li>APIキー：「発行」（2回目以降は「再発行」）を押下すると、新しいキーが表示されます。再発行すると、それまでのキーは使えなくなります。</li>
        <li>
            許可IPアドレス / CIDR：APIに接続できる接続元を登録します。「IP /
            CIDR」とメモを入力して「追加」を押下し、「保存」で反映します。接続元のIPアドレスが登録に一致し、APIキーが正しいときだけ接続できます。
        </li>
        <li>接続情報：外部システムの担当者に伝える、パスや認証の方法、回数制限です。「APIリファレンス」で、指定できるパラメータを確認できます。</li>
    </ul>
    <ManualCallout title="APIキーは発行直後に1回だけ表示されます" tone="warning"
        >画面を離れると、キーの値は二度と表示されません。表示されたらすぐに安全な場所へ控え、外部システムの担当者に伝えてください。控え忘れたときは、再発行して新しいキーを伝え直します。</ManualCallout
    >
</template>
