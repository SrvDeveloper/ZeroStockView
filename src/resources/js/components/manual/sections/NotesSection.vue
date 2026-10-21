<script setup lang="ts">
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** サイドメニュー下の連携ステータス（IntegrationStatus.vue の表示） */
const integrationStatuses = [
    { term: "Amazon SP-API", meaning: "Amazon在庫の最新の取得結果です。成功は緑で在庫の基準時刻、失敗は赤、取得中・未取得は黄色で表示します。" },
    { term: "CrossWalker", meaning: "CrossWalkerへの最新の接続結果です。成功は緑、失敗は赤で、確認した時刻を表示します。記録がないときは黄色です。失敗の理由は、マウスを重ねると表示されます。" },
    { term: "保存ファイル", meaning: "保存期限まで7日以内の取込ファイルがあると、黄色で「n件 期限間近」と表示します。" },
];
</script>

<template>
    <ManualCallout id="pending-vs-zero" class="scroll-mt-32 lg:scroll-mt-20" title="取込待ち（—）は在庫0ではありません" tone="warning"
        >「—」や「取込待ち」は、まだ数がわからない状態です。在庫が0と確定したときは「0」と表示されます。取込待ちの倉庫があるSKUは、在庫総数が未確定になり、全倉庫ゼロ・一部の倉庫でゼロにも数えません。日次在庫CSVやExcelで集計するときも、空欄を0として扱わないでください。</ManualCallout
    >
    <ManualCallout id="file-retention" class="scroll-mt-32 lg:scroll-mt-20" title="取込ファイルの保存期限は30日です" tone="warning"
        >アップロードから30日を過ぎたファイルは自動で削除され、ダウンロードと「保存ファイルで再照合」ができなくなります。調査結果・メモ・Excel出力は引き続き使えます。必要なファイルは、期限までにダウンロードしておいてください。</ManualCallout
    >
    <ManualCallout id="login-lock" class="scroll-mt-32 lg:scroll-mt-20" title="ログインに5回失敗するとロックされます" tone="warning"
        >同じログインIDで、最初の失敗から15分以内にログインに5回失敗すると、そのログインIDは15分間ログインできなくなります。ロック中は、正しいパスワードを入力してもログインできません。15分たってからもう一度ログインするか、特権管理者にロックの解除を依頼してください。ログインに成功すると、失敗の回数は数え直します。</ManualCallout
    >

    <div>
        <ManualHeading id="survey-date">調査の日付とAmazon在庫の反映</ManualHeading>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>在庫調査の日付は、取込を実行した日（日本時間）です。0時を過ぎてから取り込むと、翌日の調査になります。</li>
            <li>
                Amazon在庫は、取得した日の調査にだけ反映されます。毎朝9:00の自動取得が失敗したときは、その日のうちに特権管理者が取り直してください。翌日に取得しても、前の日の調査には反映されません。
            </li>
            <li>9:00以降に品番グループへ追加した品番は、次にAmazon在庫を取得するまで、Amazon在庫が「取込待ち」になります。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="legacy-surveys">旧方式（5ファイル）の調査</ManualHeading>
        <p class="mt-3">
            Amazon在庫をSP-APIで自動取得するようになる前は、Amazon在庫もファイル（在庫商品レポート・FBA在庫管理レポート）で取り込んでいました。この方式の調査は、Amazon在庫が取り込んだときの値のまま残り、在庫調査結果の「Amazon
            在庫」に「旧ファイル」と表示されます。保存されているAmazon在庫を守るため、「保存ファイルで再照合」はできません。
        </p>
    </div>

    <div>
        <ManualHeading id="integration-status">連携ステータス（編集者・特権管理者）</ManualHeading>
        <p class="mt-3">サイドメニューの下に、外部システムとの連携の状態が表示されます。5分ごとに自動で更新されます。</p>
        <ManualTermTable class="mt-4" :rows="integrationStatuses" term-label="項目" data-integration-statuses />
    </div>
</template>
