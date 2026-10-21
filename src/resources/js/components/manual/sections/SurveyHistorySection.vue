<script setup lang="ts">
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** 在庫調査履歴のカレンダーの印（SurveyCalendar.vue・SurveyHistoryPage.vue の凡例） */
const calendarMarks = [
    { term: "右上の丸", meaning: "Amazon在庫の反映状況です。緑は「Amazon 取込済み」、黄色は「Amazon 未確定」（取込待ち・一部未取得）を表します。" },
    { term: "全ゼロ n", meaning: "全倉庫ゼロのSKU数です。前の調査日より増えた日は、色付きで「▲」が付きます。" },
    { term: "3つの四角", meaning: "取込ファイル数（3種類中）です。塗りつぶしの数が、保存期限内のファイルの種類の数です。" },
    { term: "調査なし", meaning: "調査がない日です（点線の枠）。" },
];
</script>

<template>
    <p>
        メニューの<ManualUi variant="menu">在庫調査履歴</ManualUi
        >を押下します。すべての権限で使えます。在庫調査は1日1件で、最新の500件（約1年4か月分）まで保持されます。それより古い調査は、新しい調査を作ったときに自動で削除されます。右上の<ManualUi
            >カレンダー</ManualUi
        ><ManualUi>リスト</ManualUi>で表示を切り替えます。<ManualUi icon="refresh">最新の在庫を再読込</ManualUi>を押下すると、履歴を最新の状態に更新します。
    </p>

    <div>
        <ManualHeading id="calendar">カレンダーで見る</ManualHeading>
        <p class="mt-3">
            <ManualUi>＜</ManualUi><ManualUi>＞</ManualUi>で月を移動します。月の横の「調査 n 日 ／ 経過 n
            日」は、その月に調査がある日数と、今日までの日数です。調査のある日のマスには、次の印が表示されます。印の意味は、カレンダーの下の凡例でも確認できます。
        </p>
        <ManualTermTable class="mt-4" :rows="calendarMarks" term-label="印" data-calendar-marks />
    </div>

    <div>
        <ManualHeading id="day-detail">日を選んで詳細を見る</ManualHeading>
        <p class="mt-3">調査のある日を押下すると、右側に「○月○日（○）の調査」の詳細が表示されます。最初は最新の調査が選ばれています。</p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>全倉庫ゼロ・一部の倉庫でゼロのSKU数（前日比）と、品番数・SKU数</li>
            <li> 初回取込日時（その日に最初に取り込んだ日時）、結果作成日時、結果更新日時（追加取込やAmazon在庫の反映で更新された日時）、Amazon 在庫の反映状況 </li>
            <li>
                取込ファイル：BOSS・ECストック・フリー在庫ごとに、ファイル名か「ファイルなし」「保存期限切れ」が表示されます。右端のボタンで1件ずつ、<ManualUi icon="folder_zip"
                    >一括ダウンロード</ManualUi
                >で保存中のファイルをまとめて（ZIP形式）ダウンロードできます。
            </li>
            <li><ManualUi variant="primary">結果を開く</ManualUi>で在庫調査結果画面を開き、<ManualUi icon="table_view">Excel出力</ManualUi>でその日の調査結果をExcelファイルでダウンロードします。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="list">リストで見る</ManualHeading>
        <p class="mt-3">
            調査を新しい順に10件ずつ表示します。「調査期間」に開始日と終了日を指定すると、その期間の調査だけを表示します（<ManualUi icon="filter_alt_off">クリア</ManualUi
            >で解除）。一覧には、初回取込日時・品番数・SKU数・取込ファイル数・Amazon
            在庫の反映状況・全倉庫ゼロのSKU数が表示されます。全倉庫ゼロが前の調査日より増えた日は、数字が色付きで表示されます。右端の<ManualUi>結果を開く</ManualUi>と、表のアイコンの「Excel出力」は、カレンダーの詳細欄と同じです。
        </p>
    </div>

    <ManualCallout title="調査の削除（特権管理者のみ）" tone="warning"
        >詳細欄の<ManualUi icon="delete">この日の調査を削除</ManualUi
        >か、リストのごみ箱のアイコンで削除します。削除すると、その日の調査結果に加えて、メモと取込ファイルも削除され、元に戻せません。</ManualCallout
    >
</template>
