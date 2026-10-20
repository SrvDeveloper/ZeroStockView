import type { IconName } from "@/constants/icons";
export interface Sku {
    skuCode: string;
    colorName: string;
    size: string;
    asin: string;
    tqCode: string;
    tqColorNo: string;
    tqSize: string;
}

export interface Product {
    productCode: string;
    brand: string;
    category: string;
    parentAsin: string;
    skus: Sku[];
}

export interface ImportSetting {
    id: string;
    name: string;
    productCodes: string[];
    products: Product[];
    lastSyncedAt: string | null;
}

export interface StockQuantities {
    amazonOwn: number | null;
    amazonFba: number | null;
    bossOwn: number | null;
    bossRfc: number | null;
    freeStock: number | null;
    ecStock: number | null;
}

export const STOCK_FIELDS: { key: keyof StockQuantities; label: string; group: "Amazon" | "BOSS" | "その他" }[] = [
    { key: "amazonOwn", label: "Amazon:自社", group: "Amazon" },
    { key: "amazonFba", label: "Amazon:FBA", group: "Amazon" },
    { key: "bossOwn", label: "BOSS:自社", group: "BOSS" },
    { key: "bossRfc", label: "BOSS:RFC", group: "BOSS" },
    { key: "freeStock", label: "フリー在庫", group: "その他" },
    { key: "ecStock", label: "ECストック", group: "その他" },
];

/** 調査のSKU。在庫の合計（Amazon・モール全体・総数）は返さず、utils/stock.ts で計算する */
export interface SurveySkuResult {
    skuCode: string;
    id: string;
    size: string;
    asin: string;
    stock: StockQuantities;
    memo: string;
}

export interface SurveyProductResult {
    productCode: string;
    id: string;
    brand: string;
    category: string;
    parentAsin: string;
    memo: string;
    groups?: { key: string; name: string; sortOrder: number }[];
    skus: SurveySkuResult[];
}

export interface SurveyGroup {
    key: string;
    name: string;
}

export type ImportFileType = "在庫商品レポート" | "FBA在庫管理レポート" | "倉庫毎の在庫数レポート" | "KEEP一覧表" | "在庫一覧照会表";
export type UploadFileType = Exclude<ImportFileType, "在庫商品レポート" | "FBA在庫管理レポート">;

export const IMPORT_FILE_TYPES: { type: UploadFileType; format: string; stockScope: string; extension: string; icon: IconName }[] = [
    { type: "倉庫毎の在庫数レポート", format: "CSV", stockScope: "BOSS:自社／BOSS:RFC", extension: "csv", icon: "warehouse" },
    { type: "KEEP一覧表", format: "CSV", stockScope: "ECストック", extension: "csv", icon: "description" },
    { type: "在庫一覧照会表", format: "CSV", stockScope: "フリー在庫", extension: "csv", icon: "fact_check" },
];

export interface SurveyFile {
    id: string;
    type: ImportFileType;
    fileName: string;
    uploadedAt: string;
    sizeKb: number;
}

export interface Survey {
    id: string;
    executedAt: string;
    createdAt?: string;
    updatedAt?: string;
    products: SurveyProductResult[];
    importSettingName: string | null;
    groups?: SurveyGroup[];
    includesAmazonInventory?: boolean;
    amazonSource?: "survey" | "sp_api" | null;
    amazonInventoryStatus?: "pending" | "partial" | "complete" | "legacy_file" | "unclassified";
    amazonInventoryImportId?: string | null;
    amazonBasisAt?: string | null;
    files: SurveyFile[];
}

export type ZeroStockFilterKey = "amazonZero" | "bossZero" | "freeZero" | "ecZero";
export type HasStockFilterKey = "freeHas" | "ecHas";

export interface SelectOption {
    value: string | number;
    label: string;
}
