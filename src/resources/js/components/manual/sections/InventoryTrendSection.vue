<script setup lang="ts">
import TrendCsvMenuFigure from "@/components/manual/figures/TrendCsvMenuFigure.vue";
import ManualUi from "@/components/manual/ManualUi.vue";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualHeading from "@/components/manual/ManualHeading.vue";
import ManualTermTable from "@/components/manual/ManualTermTable.vue";

/** 在庫推移の在庫区分（utils/inventoryTrendQuery.ts の選択肢と constants/warehouses.ts の倉庫） */
const inventoryScopes = [
    { term: "モール全体", meaning: "Amazon（自社発送・FBA）とBOSS（自社倉庫・RFC倉庫）の合計です。最初はこの区分で表示します。" },
    { term: "Amazon", meaning: "Amazonの自社発送とFBAの合計です。" },
    { term: "BOSS", meaning: "BOSSの自社倉庫とRFC倉庫の合計です。" },
    { term: "フリー", meaning: "フリー在庫です。" },
    { term: "ストック", meaning: "ECストックです。" },
    { term: "総数", meaning: "Amazon・BOSS・フリー在庫・ECストックの合計です。" },
];
/** 品番一覧の注目する品番のボタン（InventoryTrendListPage.vue の FOCUS_OPTIONS） */
const focusOptions = [
    { term: "すべて", meaning: "表示期間に調査があるすべての品番です。" },
    { term: "欠品SKUあり", meaning: "最新の調査で、欠品しているSKUがある品番です。" },
    { term: "欠品が増えた", meaning: "最新の調査の欠品SKU数が、1週間前より増えた品番です。" },
    { term: "在庫が10%以上減った", meaning: "最新在庫が、1週間前より10%以上減った品番です。" },
    { term: "30日以上の欠品", meaning: "30日以上続いている欠品のSKUがある品番です。" },
];
/** ヒートマップのマスの色（InventoryTrendHeatmap.vue の段階） */
const heatmapLevels = [
    { term: "黄色", meaning: "欠品（在庫0）です。" },
    { term: "淡い灰色〜濃い灰色", meaning: "在庫数です。1〜4、5〜9、10〜19、20〜39、40以上の5段階で、多いほど濃くなります。" },
    { term: "斜線", meaning: "取込待ち・未取得の倉庫があり、在庫数が確定していない日です。" },
    { term: "枠だけ", meaning: "そのSKUの調査がない日です。" },
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
        >を押下します。すべての権限で使えます。各日の在庫調査（その日の最後の調査）を集計して表示します。最初に全品番の「品番一覧」が開き、品番を選ぶと、その品番の詳細が開きます。
    </p>

    <div>
        <ManualHeading id="options">表示する内容を選ぶ</ManualHeading>
        <p class="mt-3">品番一覧と詳細の上部で、次の条件を選びます。在庫区分と期間は、一覧と詳細を行き来しても引き継がれます。</p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>在庫区分：どの倉庫の在庫を表示するかを選びます（下の表を参照）。</li>
            <li>
                期間：<ManualUi>2週間</ManualUi><ManualUi>1か月</ManualUi
                ><ManualUi>3か月</ManualUi
                >は、最新の調査日からさかのぼった期間です。最初は「1か月」で表示します。<ManualUi>期間指定</ManualUi>を選ぶと、開始日と終了日を直接指定できます（最大365日間）。
            </li>
            <li>品番一覧では、品番グループ、ブランド、品番・SKU・ASINの検索でも絞り込めます。</li>
        </ul>
        <ManualTermTable class="mt-4" :rows="inventoryScopes" term-label="在庫区分" data-inventory-scopes />
    </div>

    <div>
        <ManualHeading id="product-list">品番一覧</ManualHeading>
        <p class="mt-3">上部の4つの数値で、全体の状況を確認します。</p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>対象品番：表示している品番の数とSKUの数です。</li>
            <li>欠品SKU：最新の調査で欠品しているSKUの数と、1週間前との差です。</li>
            <li>30日以上続いている欠品：30日以上欠品が続いているSKUの数と、その品番の数です。</li>
            <li>在庫の合計：選んだ在庫区分の合計と前週比です。取込待ち・未取得の倉庫があるときは「—」になります。</li>
        </ul>
        <p class="mt-3">表の上のボタンで、注目する品番に絞り込みます。ボタンの数字は、当てはまる品番の数です。</p>
        <ManualTermTable class="mt-4" :rows="focusOptions" term-label="ボタン" data-trend-focus-options />
        <ul class="mt-4 list-disc space-y-1 pl-5">
            <li
                >品番グループ：品番が入っているグループです。複数のグループに入っているときは、先頭のグループと残りの数（「+1」など）を出します。どのグループにも入っていない品番は「グループなし」です。</li
            >
            <li>最新在庫・前週比：最新の調査日の在庫数と、1週間前との比較です。10%以上減ったときは強調して表示します。比べられないときは「—」です。</li>
            <li>期間の推移：在庫数の小さな折れ線です。</li>
            <li>SKUの状況（最新）：SKUを1つずつ四角で表します。灰色は在庫あり、黄色は欠品、斜線は未確定です。</li>
            <li>欠品SKU：最新の調査で欠品しているSKUの数と全SKUの数です。期間の欠品（SKU・日）は、期間中に欠品したSKUと日数を掛け合わせた合計です。</li>
        </ul>
        <p class="mt-3">列の見出し（品番・最新在庫・前週比・欠品SKU）を押下すると並び替えます。最初は欠品SKUの多い順です。品番か右端の矢印を押下すると、その品番の詳細が開きます。</p>
    </div>

    <div>
        <ManualHeading id="product-detail">品番の詳細</ManualHeading>
        <p class="mt-3">
            品番の下に、ブランド・カテゴリ・SKU数と品番グループが表示されます。右上の<ManualUi>前の品番</ManualUi
            ><ManualUi>次の品番</ManualUi>で、品番一覧の並び順のとなりの品番に移ります（URLを直接開いたときは品番コード順）。<ManualUi icon="chevron_left">品番一覧に戻る</ManualUi
            >を押下すると、開いたときの一覧の条件に戻ります。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>在庫総数：最新の調査日の在庫数と前週比です。</li>
            <li>期間の平均：在庫数が確定した日の平均です。</li>
            <li>欠品SKU：最新の調査日に欠品しているSKUの数と、1週間前の数です。</li>
            <li>期間中の欠品：期間中に欠品したSKUと日数を掛け合わせた合計（SKU・日）と、欠品したSKUの数です。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="product-chart">倉庫別の在庫（日別）</ManualHeading>
        <p class="mt-3">品番のすべてのSKUの在庫を日ごとに合計し、在庫区分に含まれる倉庫ごとに色分けして積み上げます。色と倉庫の対応は、グラフの上の凡例で確認できます。</p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>未確定：取込待ち・未取得の倉庫がある日は、確定した倉庫だけを積み上げ、上に「未確定」の印（点線の枠）を付けます。合計は出しません。</li>
            <li>欠品：日付の下の黄色の四角と数字は、その日に在庫区分の在庫が0だったSKUの数です。未確定のSKUがある日は数えません。</li>
            <li>棒にマウスを重ねると、倉庫ごとの在庫数、総在庫数、欠品SKU数が表示されます。BOSS在庫を在庫変動履歴から再現した日は、BOSSの行に「（履歴から再現）」が付きます。</li>
        </ul>
        <p class="mt-3">グラフの右上の<ManualUi icon="refresh">再読込</ManualUi>を押下すると、最新のデータで表示し直します。</p>
    </div>

    <div>
        <ManualHeading id="sku-heatmap">SKU別の在庫（ヒートマップ）</ManualHeading>
        <p class="mt-3">
            1行が1SKU、1マスが1日です。行の右に、最新の在庫数と欠品日数（期間中に在庫が0だった調査の日数）が表示されます。マスにマウスを重ねるか、マスを選んで矢印キーで移ると、その日の在庫数が表示されます（モール全体・総数では倉庫ごとの内訳も）。
        </p>
        <ManualTermTable class="mt-4" :rows="heatmapLevels" term-label="マスの色" data-trend-heatmap-levels />
        <ul class="mt-4 list-disc space-y-1 pl-5">
            <li>並び順：「カラー・サイズ順」「欠品日数の多い順」「最新在庫の少ない順」から選びます。カラー・サイズ順のときは、カラーごとに見出しが入ります。</li>
            <li> <ManualUi>数値を表で見る</ManualUi>：SKUごとの最新在庫・期間平均・期間最小・期間最大・欠品日数を表で表示します。色を見分けにくいときや、数字を確かめたいときに使います。 </li>
        </ul>
    </div>

    <div>
        <ManualHeading id="stockout-skus">カラー × サイズの在庫と欠品中のSKU</ManualHeading>
        <p class="mt-3">
            ヒートマップの下に、最新の調査日の在庫をカラー ×
            サイズの表で表示します。小さい数字は1週間前との差です。欠品のマスは黄色で、欠品が続いている日数を表示します。在庫が1〜4のマスは枠を濃くします。
        </p>
        <p class="mt-3">
            「欠品中のSKU」には、最新の調査日に欠品しているSKUを、欠品が続いている日数の長い順に並べます。欠品が始まった日と、その直前に在庫があった日の在庫数、期間中に欠品していた日数が表示されます。
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5">
            <li>欠品が始まった日：最後に在庫があった日の後で、最初に在庫0と確定した日です。その前の調査がない日や取込待ちの日は、欠品に数えません。</li>
            <li>欠品が始まったあとは、調査がない日や取込待ちの日があっても、欠品が続いているとみなして日数を数えます。</li>
            <li>表示期間の中に在庫があった日がないときは、「◯日目以上」と表示します（期間より前から欠品している場合があります）。</li>
        </ul>
    </div>

    <div>
        <ManualHeading id="csv-export">日次在庫をCSV出力</ManualHeading>
        <p class="mt-3">
            品番一覧の右上の<ManualUi icon="download">日次在庫CSV</ManualUi>から<ManualUi>全品番の日次CSV</ManualUi>を、品番の詳細の右上の<ManualUi icon="download">この品番のCSV</ManualUi
            >から、その品番のCSVを出力します。表示している期間の開始日から終了日までについて、1日・1SKUを1行として出力します。在庫区分に関係なく、すべての倉庫の在庫数を出力します。調査がない日でもAmazon在庫を取得した日は出力し、その日のAmazon以外の列は空欄になります。
        </p>
        <TrendCsvMenuFigure class="mt-4" />
        <ManualTermTable class="mt-4" :rows="trendCsvColumns" term-label="列" meaning-label="内容" data-trend-csv-columns />
    </div>
    <ManualCallout title="CSVの空欄と0の違い" tone="tip"
        >空欄は、取込待ち・未取得で在庫数がわからないことを表します。0は、在庫が0と確定していることを表します。集計するときは、空欄を0として扱わないでください。</ManualCallout
    >
</template>
