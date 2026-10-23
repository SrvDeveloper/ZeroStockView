<script setup lang="ts">
import SurveyResultActionsFigure from "@/components/manual/figures/SurveyResultActionsFigure.vue";
import ManualResult from "@/components/manual/ManualResult.vue";
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualStep from "@/components/manual/ManualStep.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** 在庫調査結果の在庫表の表示（StockCell.vue・SurveyResultPage.vue の表示） */
const stockDisplays = [
    { term: "数字", meaning: "在庫数です。Amazon・BOSSは、5以下のときに色を変えて表示します。" },
    { term: "0（色付き）", meaning: "取込済みで、在庫が0と確定しています。" },
    { term: "—", meaning: "取込待ち、またはデータなしです。その種類のファイルをその日にまだ取り込んでいないか、BOSSのファイルにそのSKUが載っていませんでした。在庫0ではありません。" },
    { term: "取込待ち（札）", meaning: "Amazon在庫がまだ反映されていないか、Amazonの在庫レポートに含まれていなかったSKUです。" },
    { term: "対象外", meaning: "ASINが登録されていないため、Amazon在庫を取得しないSKUです。" },
    { term: "総数", meaning: "全倉庫の合計です。どれかの倉庫が未確定のときは「—」になり、0としては数えません。" },
    { term: "色付きの行", meaning: "全倉庫ゼロ（総数が0と確定）のSKUです。行の左端にも印が付きます。" },
    { term: "倉庫別の構成", meaning: "倉庫ごとの在庫の割合を色の帯で示します。総数が未確定のときは「取込後に表示」「Amazon 取込後に表示」と表示されます。" },
];
</script>

<template>
    <p>
        在庫調査履歴の<ManualUi variant="primary">結果を開く</ManualUi>を押下するか、データ取込が完了すると開きます。すべての権限で使えます。<ManualUi icon="chevron_left">調査履歴に戻る</ManualUi
        >で在庫調査履歴に戻ります。
    </p>

    <div>
        <ManualHeading id="summary">画面上部のまとめ</ManualHeading>
        <SurveyResultActionsFigure class="mt-4" />
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>
                見出しの下に、初回取込日時・結果作成日時・結果更新日時と、取込ファイル（保存期限内のファイルの種類の数）が表示されます。<ManualUi icon="folder_zip">一括ダウンロード</ManualUi
                >で、保存中の取込ファイルをまとめてダウンロードできます。
            </li>
            <li>「対象」は調査したSKU数と品番数、「全倉庫ゼロ」「一部の倉庫でゼロ」はそれぞれのSKU数です。「前回比」は、直前の調査との差です。</li>
            <li>
                「Amazon 在庫」は、Amazon在庫の反映状況です（意味は「Amazon在庫の取得」の表を参照）。下の「基準 hh:mm ・ 照合
                n/n」は、Amazon在庫の基準時刻と、Amazon在庫を取得できたSKU数／ASINのあるSKU数です。
            </li>
        </ul>
    </div>

    <div>
        <ManualHeading id="stock-table">在庫表の見方</ManualHeading>
        <p class="mt-3">
            品番ごとに、SKUの在庫を倉庫別（Amazon・BOSS・フリー在庫・ECストック）に表示します。Amazonは自社発送とFBA、BOSSは自社倉庫とRFC倉庫の合計です。「全倉庫ゼロ」は総数が0と確定したSKU、「一部の倉庫でゼロ」は総数が1以上で、0の倉庫があるSKUです。
        </p>
        <ManualTermTable class="mt-4" :rows="stockDisplays" data-stock-displays />
    </div>
    <ManualCallout title="AmazonとBOSSの内訳を見る" tone="tip"
        >キーボードのCtrlキーを押しながら、Amazon・BOSSの数字にマウスを重ねると、内訳（Amazonは自社／FBA、BOSSは自社／RFC）が表示されます。</ManualCallout
    >

    <div>
        <ManualHeading id="filters">絞り込む</ManualHeading>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li
                >品番グループ・ブランド・カテゴリを選ぶか、品番・SKU・ASINの一部を入力して絞り込みます。SKUやASINで絞り込むと、該当するSKUの行に印が付きます。どの品番グループにも入っていない品番は「グループなし」で選べます。</li
            >
            <li>
                「在庫ゼロ」「在庫あり」のボタン（Amazon・BOSS・フリー・ECストック）を押下すると、在庫の条件で絞り込みます。複数押下したときは、「結合」で<ManualUi>AND</ManualUi>（すべて満たす）か<ManualUi>OR</ManualUi>（どれかを満たす）を選びます。取込待ち（「—」）の倉庫は、在庫ゼロの条件に該当しません。
            </li>
            <li>該当したSKU数は「該当 n ／ n SKU」に表示されます。<ManualUi>条件をクリア</ManualUi>で、すべての条件を解除します。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="excel">Excelに出力する</ManualHeading>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li><ManualUi variant="primary" icon="download">全件をExcel出力</ManualUi>：調査のすべてのSKUを出力します。品番グループを選んでいるときは、そのグループのSKUを出力します。</li>
            <li><ManualUi icon="table_view">絞込結果をExcel出力</ManualUi>：絞り込みで該当したSKUだけを出力します。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="memo">メモを書く（特権管理者のみ）</ManualHeading>
        <p class="mt-3">
            品番の見出しの右にある「品番のメモを追加」と、SKUの行の「メモを追加」に入力します。入力欄から離れると自動で保存されます。メモは調査（日）ごとに保存され、同じ日の追加取込や再照合では残りますが、別の日の調査には引き継がれません。特権管理者以外は、メモを読むことだけができます。
        </p>
    </div>

    <div>
        <ManualHeading id="refresh">最新の在庫を再読込</ManualHeading>
        <p class="mt-3">
            <ManualUi icon="refresh">最新の在庫を再読込</ManualUi
            >を押下すると、この調査を最新の状態に読み込み直します。この画面は30秒ごとに自動で更新されますが、Amazon在庫が反映されたかをすぐ確かめたいときなどに使います。
        </p>
    </div>

    <div>
        <ManualHeading id="rerun">保存ファイルで再照合（編集者・特権管理者）</ManualHeading>
        <p class="mt-3">
            その日に取り込んで保存されているファイルを使い、CrossWalkerで有効な全品番と照合し直して、この調査を更新します。ファイルを添付し直す必要はありません。次のようなときに使います。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>CrossWalkerで品番を追加したり無効にしたりしたあと、取込済みの日の結果にも反映したいとき</li>
            <li>CrossWalkerでSKUの情報（ASIN、ターコイズの品番・カラー・サイズ）を直したあと、結果に反映したいとき</li>
        </ul>
    </div>
    <ManualStep :number="1" title="差異照合メニューを開きます。">
        <p>画面右上の<ManualUi icon="compare_arrows">保存ファイルで再照合</ManualUi>を押下すると、「差異照合メニュー」が開きます。</p>
    </ManualStep>
    <ManualStep :number="2" title="再照合を実行します。">
        <p>
            必要に応じて「再照合後に表示する品番グループ」を選び、<ManualUi variant="primary" icon="refresh">再照合を実行</ManualUi
            >を押下します。選ばないときは、すべての品番グループを表示します。照合の対象は、選んだグループに関係なく、CrossWalkerで有効な全品番です。
        </p>
        <ManualResult>「調査結果を再照合しました」と表示され、選んだグループ（選ばなかったときはすべてのグループ）の結果が表示されます。</ManualResult>
        <p> 初回取込日時は変わりません。保存期限（30日）を過ぎたファイルの種類は、取込待ちになります。Amazon在庫は、その日に取得した最新の在庫で反映し直します。 </p>
    </ManualStep>
    <ManualCallout title="再照合できない調査" tone="warning">
        次の調査では「保存ファイルで再照合」を押せず、画面上部に理由が表示されます。
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li>Amazon在庫をファイルで取り込んでいた旧方式（5ファイル）の調査。保存されているAmazon在庫を守るため、再照合できません。</li>
            <li>保存期限が過ぎるなどして、再照合に使える取込ファイルが1件もない調査。</li>
        </ul>
    </ManualCallout>
</template>
