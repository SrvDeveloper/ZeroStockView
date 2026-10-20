<script setup lang="ts">
import TrendCsvMenuFigure from "@/components/manual/figures/TrendCsvMenuFigure.vue";
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** 在庫推移の在庫区分（InventoryTrendPage.vue の選択肢と constants/warehouses.ts の倉庫） */
const inventoryScopes = [
    { term: "モール全体", meaning: "Amazon（自社発送・FBA）とBOSS（自社倉庫・RFC倉庫）の合計です。最初はこの区分で表示します。" },
    { term: "Amazon", meaning: "Amazonの自社発送とFBAの合計です。" },
    { term: "BOSS", meaning: "BOSSの自社倉庫とRFC倉庫の合計です。" },
    { term: "フリー", meaning: "フリー在庫です。" },
    { term: "ストック", meaning: "ECストックです。" },
    { term: "総数", meaning: "Amazon・BOSS・フリー在庫・ECストックの合計です。" },
];
/** 日次在庫CSVの列（InventoryTrendCsvExporter::HEADERS） */
const trendCsvColumns = [
    { term: "date", meaning: "日付" },
    { term: "productCode", meaning: "品番" },
    { term: "brand / category", meaning: "ブランド／カテゴリ" },
    { term: "sku / size", meaning: "SKU／サイズ" },
    { term: "amazonOwn / amazonFba", meaning: "Amazonの自社発送／FBAの在庫数" },
    { term: "bossOwn / bossRfc", meaning: "BOSSの自社倉庫／RFC倉庫の在庫数" },
    { term: "freeStock", meaning: "フリー在庫の在庫数" },
    { term: "ecStock", meaning: "ECストックの在庫数" },
];
</script>

<template>
    <p>
        メニューの<ManualUi variant="menu">在庫推移</ManualUi
        >を押下します。すべての権限で使えます。各日の在庫調査（その日の最新の状態）を集計して表示します。調査がない日でも、Amazon在庫を取得した日はAmazon在庫だけを表示します。
    </p>

    <div>
        <ManualHeading id="options">表示する内容を選ぶ</ManualHeading>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>
                対象品番：<ManualUi>変更</ManualUi>を押下すると「対象品番を選択」が開きます。ブランドや検索（品番・ブランド・カテゴリ）で候補を絞り込み、品番を選んで<ManualUi variant="primary"
                    >この品番を表示</ManualUi
                >を押下します。候補は、これまでの在庫調査かAmazon在庫の取得に含まれた品番です。
            </li>
            <li>在庫区分：どの倉庫の在庫を表示するかを選びます（下の表を参照）。</li>
            <li>表示期間：<ManualUi>2週間</ManualUi><ManualUi>1か月</ManualUi><ManualUi>3か月</ManualUi>は、データがある最新の日からさかのぼった期間です。最初は「1か月」で表示します。</li>
            <li>期間：開始日と終了日を直接指定します（最大365日間）。指定すると、表示期間は「期間指定」になります。</li>
        </ul>
        <ManualTermTable class="mt-4" :rows="inventoryScopes" term-label="在庫区分" data-inventory-scopes />
    </div>

    <div>
        <ManualHeading id="product-chart">品番全体のグラフ</ManualHeading>
        <p class="mt-3">
            グラフの右上の<ManualUi>品番全体</ManualUi
            ><ManualUi>SKU別</ManualUi
            >で表示を切り替えます。「品番全体」では、品番のすべてのSKUの在庫を日ごとに合計し、在庫区分に含まれる倉庫ごとに色分けして積み上げます。色と倉庫の対応は、グラフの上の凡例で確認できます。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>未確定：取込待ち・未取得の倉庫がある日は、確定した倉庫だけを積み上げ、上に「未確定」の印（点線の枠）を付けます。合計は出しません。</li>
            <li>欠品：日付の下の黄色の四角と数字は、その日に在庫区分の在庫が0だったSKUの数です。未確定のSKUがある日は数えません。</li>
            <li> 棒にマウスを重ねると、倉庫ごとの在庫数、総在庫数、欠品SKU数が表示されます。欠品SKUがある日は、そのSKUと期間中に欠品した日数（期間累計）、全SKUの期間累計欠品日数も表示されます。 </li>
        </ul>
    </div>

    <div>
        <ManualHeading id="sku-chart">SKU別のグラフ</ManualHeading>
        <p class="mt-3">
            「SKU別」では、SKUごとに在庫区分の在庫数の折れ線を表示します。右上の札は「欠品
            n日」（期間中に在庫0の日がある）、「在庫あり」、「データなし」のいずれかです。その下に、最新の在庫数と期間平均が表示されます。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>点にマウスを重ねると、その日の日付と在庫数が表示されます。</li>
            <li>点線の部分は、データのない日をまたいでつないだところです。</li>
            <li>最後の点が黄色のときは、最新の在庫数が0です。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="sku-summary">SKU別サマリー</ManualHeading>
        <p class="mt-3">
            グラフの下に、SKUごとの数字を表にまとめます。見出しの横に、表示している期間と日数が表示されます。数字は在庫区分の在庫数で、データのない日は計算に含めません（値がないときは「—」）。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>推移：在庫数の小さな折れ線です。</li>
            <li>最新在庫：期間中、最後にデータがある日の在庫数です。</li>
            <li>期間平均・期間最小・期間最大：期間中の在庫数の平均・最小・最大です。</li>
            <li>欠品日数：期間中に在庫が0だった日数です。1日以上あると黄色で表示します。</li>
        </ul>
        <p class="mt-3">グラフの右上の<ManualUi icon="refresh">再読込</ManualUi>を押下すると、最新のデータで表示し直します。</p>
    </div>

    <div>
        <ManualHeading id="csv-export">日次在庫をCSV出力</ManualHeading>
        <p class="mt-3">
            右上の<ManualUi variant="primary" icon="download">日次在庫をCSV出力</ManualUi
            >を押下し、<ManualUi>選択品番の日次CSV</ManualUi>（表示中の品番）か<ManualUi>全品番の日次CSV</ManualUi>を選びます。期間で指定した開始日から終了日までについて、1日・1SKUを1行として出力します。在庫区分に関係なく、すべての倉庫の在庫数を出力します。調査がない日でもAmazon在庫を取得した日は出力し、その日のAmazon以外の列は空欄になります。
        </p>
        <TrendCsvMenuFigure class="mt-4" />
        <ManualTermTable class="mt-4" :rows="trendCsvColumns" term-label="列" meaning-label="内容" data-trend-csv-columns />
    </div>
    <ManualCallout title="CSVの空欄と0の違い" tone="tip"
        >空欄は、取込待ち・未取得で在庫数がわからないことを表します。0は、在庫が0と確定していることを表します。集計するときは、空欄を0として扱わないでください。</ManualCallout
    >
</template>
