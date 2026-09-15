import type { ImportFileType, Survey, SurveyFile, SurveyProductResult, SurveySkuResult } from "@/types";
import { IMPORT_FILE_TYPES } from "@/types";
import { findProduct } from "@/constants/masterData";
import { seededChance, seededRandomInt } from "@/utils/random";
import { formatFileTimestamp } from "@/utils/format";

// 直近から過去にさかのぼった経過日数。後半は30日を超え、ファイル自動削除の見え方も確認できるようにする。
const DAYS_AGO = [0, 3, 6, 9, 13, 18, 23, 28, 33, 39, 45, 52, 60, 68, 77];

function executedAtFor(daysAgo: number): string {
    const d = new Date();
    d.setDate(d.getDate() - daysAgo);
    d.setHours(seededRandomInt(`hour-${daysAgo}`, 9, 18), seededRandomInt(`min-${daysAgo}`, 0, 59), 0, 0);
    return d.toISOString();
}

function buildStock(surveyId: string, skuCode: string) {
    const seed = (field: string) => `${surveyId}-${skuCode}-${field}`;
    const value = (field: string, zeroChance: number, max: number) => (seededChance(seed(field), zeroChance) ? 0 : seededRandomInt(seed(field + "-v"), 1, max));
    return {
        amazonOwn: value("amazonOwn", 0.35, 40),
        amazonFba: value("amazonFba", 0.3, 120),
        bossOwn: value("bossOwn", 0.3, 60),
        bossRfc: value("bossRfc", 0.45, 30),
        freeStock: value("freeStock", 0.5, 25),
        ecStock: value("ecStock", 0.55, 15),
    };
}

const SAMPLE_MEMOS = ["次回入荷まで欠品注意", "セール対象品番のため要監視", "廃盤予定、在庫消化を優先", "人気サイズのみ在庫僅少"];

function buildProductResults(surveyId: string, productCodes: string[], memoSeedActive: boolean): SurveyProductResult[] {
    return productCodes
        .map((code) => findProduct(code))
        .filter((p): p is NonNullable<typeof p> => Boolean(p))
        .map((product, productIndex) => {
            const skus: SurveySkuResult[] = product.skus.map((sku, skuIndex) => {
                const memo = memoSeedActive && seededChance(`${surveyId}-${sku.skuCode}-memo`, 0.12) ? SAMPLE_MEMOS[(productIndex + skuIndex) % SAMPLE_MEMOS.length] : "";
                return {
                    id: `${surveyId}-sku-${skuIndex}`,
                    skuCode: sku.skuCode,
                    colorName: sku.colorName,
                    size: sku.size,
                    asin: sku.asin,
                    tqCode: sku.tqCode,
                    tqColorNo: sku.tqColorNo,
                    tqSize: sku.tqSize,
                    stock: buildStock(surveyId, sku.skuCode),
                    memo,
                };
            });
            const productMemo = memoSeedActive && seededChance(`${surveyId}-${product.productCode}-memo`, 0.15) ? SAMPLE_MEMOS[productIndex % SAMPLE_MEMOS.length] : "";
            return {
                id: `${surveyId}-product-${productIndex}`,
                productCode: product.productCode,
                brand: product.brand,
                category: product.category,
                parentAsin: product.parentAsin,
                memo: productMemo,
                skus,
            };
        });
}

function buildFiles(surveyId: string, executedAt: string): SurveyFile[] {
    return IMPORT_FILE_TYPES.map((fileType, index) => buildFile(surveyId, executedAt, fileType.type, index));
}

function buildFile(surveyId: string, executedAt: string, type: ImportFileType, index: number): SurveyFile {
    const spec = IMPORT_FILE_TYPES.find((f) => f.type === type)!;
    const timestamp = formatFileTimestamp(executedAt);
    return {
        id: `${surveyId}-file-${index}`,
        type,
        fileName: `${type}_${timestamp}.${spec.extension}`,
        uploadedAt: executedAt,
        sizeKb: seededRandomInt(`${surveyId}-${type}-size`, 18, 480),
    };
}

export function createSurveyRecord(id: string, executedAt: string, productCodes: string[], seedMemos = false): Survey {
    return {
        id,
        executedAt,
        products: buildProductResults(id, productCodes, seedMemos),
        files: buildFiles(id, executedAt),
    };
}

export function createDummySurveys(productCodes: string[]): Survey[] {
    return DAYS_AGO.map((daysAgo, index) => {
        const executedAt = executedAtFor(daysAgo);
        const id = `SV-${index + 1}`;
        return createSurveyRecord(id, executedAt, productCodes, index < 3);
    });
}
