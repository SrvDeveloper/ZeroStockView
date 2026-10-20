<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
</script>

<template>
    <p class="text-[14px] leading-relaxed text-ink"> メニューの「ユーザー管理」を押下します。特権管理者だけが使えます。権限ごとにできることは、「はじめに」の表を参照してください。 </p>
    <ul class="list-disc space-y-1 pl-5 text-[14px] leading-relaxed text-ink">
        <li>追加：「ユーザーを追加」を押下し、ログインID・権限・初期パスワードを入力して「ユーザーを作成」を押下します。</li>
        <li>編集：一覧の鉛筆のアイコンを押下します。ログインIDと権限を変えて「変更を保存」を押下します。</li>
        <li> パスワードの再設定：編集の画面で「パスワードを再設定する」を押下し、新しいパスワードを入力します。パスワードは半角英数字記号のみで12文字以上です。 </li>
        <li>削除：一覧のごみ箱のアイコンを押下し、「削除する」を押下します。削除したユーザーは、すぐにログアウトされます。</li>
    </ul>
    <ManualCallout title="権限の変更と削除の注意" tone="warning">
        <ul class="list-disc space-y-0.5 pl-5">
            <li>権限を変更すると、そのユーザーはすぐにログアウトされ、再ログインが必要になります。自分の権限を変更したときは、ログイン画面に戻ります。</li>
            <li>特権管理者が1人だけのときは、その人を降格・削除できません。先に別の特権管理者を作成してください。</li>
            <li>自分自身は削除できません。</li>
        </ul>
    </ManualCallout>
    <p class="text-[14px] leading-relaxed text-ink"> 自分のパスワードは、権限に関係なく、画面右上のログインIDを押下して「パスワード変更」から変更できます。 </p>
</template>
