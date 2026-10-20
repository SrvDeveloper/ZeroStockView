import { describe, expect, it } from "vitest";
import type { StockQuantities, Survey, SurveySkuResult } from "@/types";
import { amazonStockState, isAllZero, isPartialZero, previousSurvey, skuTotal, stockState, summarizeSurvey } from "@/utils/stock";

const SP_API = { amazonSource: "sp_api" as const, includesAmazonInventory: true };
const LEGACY = { amazonSource: "survey" as const, includesAmazonInventory: true };
const NO_AMAZON = { amazonSource: null, includesAmazonInventory: false };

function stock(overrides: Partial<StockQuantities> = {}): StockQuantities {
    return { amazonOwn: 1, amazonFba: 2, bossOwn: 3, bossRfc: 4, freeStock: 5, ecStock: 6, ...overrides };
}

function sku(overrides: Partial<StockQuantities> = {}, asin = "B000000001"): Pick<SurveySkuResult, "stock" | "asin"> {
    return { stock: stock(overrides), asin };
}

function survey(id: string, executedAt: string, skus: Pick<SurveySkuResult, "stock" | "asin">[] = [], context: Partial<Survey> = SP_API): Survey {
    return {
        id,
        executedAt,
        importSettingName: null,
        files: [],
        ...context,
        products: [
            {
                id: `${id}-p`,
                productCode: "A-1",
                brand: "B",
                category: "C",
                parentAsin: "",
                memo: "",
                skus: skus.map((item, index) => ({ ...item, id: `${id}-${index}`, skuCode: `S-${index}`, size: "", memo: "" })),
            },
        ],
    };
}

describe("skuTotal", () => {
    it("adds every warehouse when all values are known", () => {
        expect(skuTotal(stock(), "B1", SP_API)).toBe(21);
    });

    it("returns null while any warehouse is pending instead of treating it as zero", () => {
        expect(skuTotal(stock({ amazonFba: null }), "B1", SP_API)).toBeNull();
        expect(skuTotal(stock({ freeStock: null }), "B1", SP_API)).toBeNull();
    });

    it("excludes Amazon for an ASIN-less SP-API SKU", () => {
        expect(skuTotal(stock({ amazonOwn: null, amazonFba: null }), " ", SP_API)).toBe(18);
    });

    it("keeps a missing Amazon value pending for legacy file surveys", () => {
        expect(skuTotal(stock({ amazonOwn: null, amazonFba: null }), "", LEGACY)).toBeNull();
    });

    it("ignores Amazon for surveys without Amazon stock", () => {
        expect(skuTotal(stock({ amazonOwn: null, amazonFba: null }), "B1", NO_AMAZON)).toBe(18);
    });
});

describe("stock states", () => {
    it("classifies quantities", () => {
        expect(stockState(null)).toBe("pending");
        expect(stockState(0)).toBe("zero");
        expect(stockState(5)).toBe("has");
        expect(stockState(5, { low: true })).toBe("low");
        expect(stockState(6, { low: true })).toBe("has");
    });

    it("marks Amazon as outside scope only for ASIN-less SP-API SKUs", () => {
        expect(amazonStockState(stock({ amazonOwn: null, amazonFba: null }), "", SP_API)).toBe("na");
        expect(amazonStockState(stock({ amazonOwn: null, amazonFba: null }), "B1", SP_API)).toBe("pending");
        expect(amazonStockState(stock({ amazonOwn: 1, amazonFba: 2 }), "B1", SP_API)).toBe("low");
    });
});

describe("zero detection", () => {
    it("counts all-zero only when the total is known", () => {
        const zeros = { amazonOwn: 0, amazonFba: 0, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 };
        expect(isAllZero(sku(zeros), SP_API)).toBe(true);
        expect(isAllZero(sku({ ...zeros, amazonOwn: null }), SP_API)).toBe(false);
    });

    it("counts partial zero when a known warehouse is empty but the SKU still has stock", () => {
        expect(isPartialZero(sku({ ecStock: 0 }), SP_API)).toBe(true);
        expect(isPartialZero(sku(), SP_API)).toBe(false);
        expect(isPartialZero(sku({ ecStock: 0, amazonOwn: null }), SP_API)).toBe(false);
    });

    it("does not treat an out-of-scope Amazon value as a zero warehouse", () => {
        expect(isPartialZero(sku({ amazonOwn: null, amazonFba: null }, ""), SP_API)).toBe(false);
    });
});

describe("survey helpers", () => {
    it("summarizes a survey", () => {
        const zeros = { amazonOwn: 0, amazonFba: 0, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 };
        const summary = summarizeSurvey(survey("1", "2026-10-16T00:00:00Z", [sku(zeros), sku({ freeStock: 0 }), sku(), sku({ bossRfc: null })]));
        expect(summary).toEqual({ productCount: 1, skuCount: 4, allZeroCount: 1, partialZeroCount: 1 });
    });

    it("finds the survey just before the given one", () => {
        const surveys = [survey("1", "2026-10-14T00:00:00Z"), survey("2", "2026-10-15T00:00:00Z"), survey("3", "2026-10-16T00:00:00Z")];
        expect(previousSurvey(surveys, surveys[2]!)?.id).toBe("2");
        expect(previousSurvey(surveys, surveys[0]!)).toBeNull();
    });
});
