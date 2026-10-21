<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
</script>

<template>
    <p> メニューの<ManualUi variant="menu">ユーザー管理</ManualUi>を押下します。特権管理者だけが使えます。権限ごとにできることは、「はじめに」の表を参照してください。 </p>
    <ul class="list-disc space-y-1 pl-5">
        <li
            >追加：<ManualUi variant="primary" icon="person_add">ユーザーを追加</ManualUi>を押下し、ログインID・権限・初期パスワードを入力して<ManualUi variant="primary">ユーザーを作成</ManualUi
            >を押下します。</li
        >
        <li>編集：一覧の鉛筆のアイコンを押下します。ログインIDと権限を変えて<ManualUi variant="primary">変更を保存</ManualUi>を押下します。</li>
        <li> パスワードの再設定：編集の画面で<ManualUi icon="key">パスワードを再設定する</ManualUi>を押下し、新しいパスワードを入力します。パスワードは半角英数字記号のみで12文字以上です。 </li>
        <li>削除：一覧のごみ箱のアイコンを押下し、<ManualUi>削除する</ManualUi>を押下します。削除したユーザーは、すぐにログアウトされます。</li>
        <li
            >ロックの解除：ログインに続けて失敗したユーザーは、ログインIDの横に「ロック中（解除される日時まで）」と表示されます。一覧の鍵のアイコン（<ManualUi icon="lock_open">ロックを解除</ManualUi
            >）を押下すると、すぐにログインできるようになります。解除しなくても、表示された日時を過ぎると自動で解除されます。</li
        >
    </ul>
    <ManualCallout title="権限の変更と削除の注意" tone="warning">
        <ul class="list-disc space-y-0.5 pl-5">
            <li>権限を変更すると、そのユーザーはすぐにログアウトされ、再ログインが必要になります。自分の権限を変更したときは、ログイン画面に戻ります。</li>
            <li>特権管理者が1人だけのときは、その人を降格・削除できません。先に別の特権管理者を作成してください。</li>
            <li>自分自身は削除できません。</li>
        </ul>
    </ManualCallout>
    <p> 自分のパスワードは、権限に関係なく、画面右上のログインIDを押下して<ManualUi>パスワード変更</ManualUi>から変更できます。 </p>
</template>
