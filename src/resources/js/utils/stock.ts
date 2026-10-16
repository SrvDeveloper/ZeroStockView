import type { StockQuantities, Survey, SurveySkuResult } from "@/types";

/** 在庫セルの状態。low は 1〜{@link LOW_STOCK_THRESHOLD} 件、pending は取込待ち・未取得、na は Amazon の対象外 */
export type StockState = "has" | "low" | "zero" | "pending" | "na";

export const LOW_STOCK_THRESHOLD = 5;

type AmazonContext = Pick<Survey, "amazonSource" | "includesAmazonInventory">;

export interface SurveySummary {
    productCount: number;
    skuCount: number;
    allZeroCount: number;
    partialZeroCount: number;
}

/** Amazon 自社＋FBA。どちらかが未確定なら null */
export function amazonTotal(stock: StockQuantities): number | null {
    return stock.amazonOwn === null || stock.amazonFba === null ? null : stock.amazonOwn + stock.amazonFba;
}

/** BOSS 自社＋RFC。どちらかが未確定なら null */
export function bossTotal(stock: StockQuantities): number | null {
    return stock.bossOwn === null || stock.bossRfc === null ? null : stock.bossOwn + stock.bossRfc;
}

/** Amazon の列を持つ調査か（旧ファイル調査で Amazon を取り込まなかったものは持たない） */
export function hasAmazonStock(survey: AmazonContext): boolean {
    return survey.includesAmazonInventory !== false || survey.amazonSource === "sp_api";
}

/** SP-API 調査で ASIN のない SKU は Amazon の取得対象外 */
export function isAmazonOutOfScope(asin: string, survey: AmazonContext): boolean {
    return survey.amazonSource === "sp_api" && !asin.trim();
}

/** SKU の在庫総数。未確定の倉庫があれば null（0 として足さない） */
export function skuTotal(stock: StockQuantities, asin: string, survey: AmazonContext): number | null {
    const amazon = hasAmazonStock(survey) ? (amazonTotal(stock) ?? (isAmazonOutOfScope(asin, survey) ? 0 : null)) : 0;
    const boss = bossTotal(stock);
    if (amazon === null || boss === null || stock.freeStock === null || stock.ecStock === null) return null;
    return amazon + boss + stock.freeStock + stock.ecStock;
}

export function stockState(quantity: number | null, options: { low?: boolean } = {}): StockState {
    if (quantity === null) return "pending";
    if (quantity === 0) return "zero";
    return options.low && quantity <= LOW_STOCK_THRESHOLD ? "low" : "has";
}

export function amazonStockState(stock: StockQuantities, asin: string, survey: AmazonContext): StockState {
    const quantity = amazonTotal(stock);
    if (quantity === null && isAmazonOutOfScope(asin, survey)) return "na";
    return stockState(quantity, { low: true });
}

/** 総数が確定して 0 の SKU */
export function isAllZero(sku: Pick<SurveySkuResult, "stock" | "asin">, survey: AmazonContext): boolean {
    return skuTotal(sku.stock, sku.asin, survey) === 0;
}

/** 総数が 1 以上で、値が確定している倉庫のどれかが 0 の SKU */
export function isPartialZero(sku: Pick<SurveySkuResult, "stock" | "asin">, survey: AmazonContext): boolean {
    const total = skuTotal(sku.stock, sku.asin, survey);
    if (total === null || total === 0) return false;
    const amazon = hasAmazonStock(survey) && !isAmazonOutOfScope(sku.asin, survey) ? amazonTotal(sku.stock) : null;
    return [amazon, bossTotal(sku.stock), sku.stock.freeStock, sku.stock.ecStock].some((quantity) => quantity === 0);
}

export function summarizeSurvey(survey: Survey): SurveySummary {
    const skus = survey.products.flatMap((product) => product.skus);
    return {
        productCount: survey.products.length,
        skuCount: skus.length,
        allZeroCount: skus.filter((sku) => isAllZero(sku, survey)).length,
        partialZeroCount: skus.filter((sku) => isPartialZero(sku, survey)).length,
    };
}

/** 初回取込日時（executedAt）がこの調査の直前にある調査 */
export function previousSurvey(surveys: Survey[], survey: Survey): Survey | null {
    const current = new Date(survey.executedAt).getTime();
    return surveys.reduce<Survey | null>((found, candidate) => {
        const time = new Date(candidate.executedAt).getTime();
        if (candidate.id === survey.id || time >= current) return found;
        return found === null || time > new Date(found.executedAt).getTime() ? candidate : found;
    }, null);
}
