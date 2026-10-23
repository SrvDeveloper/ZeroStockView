import axios from "axios";

export type InventoryScope = "mallTotal" | "amazon" | "boss" | "free" | "stock" | "grandTotal";

export interface InventoryTrendProduct {
    productCode: string;
    brand: string;
    category: string;
    skuCount: number;
    hasSurveyHistory: boolean;
}

export interface InventoryTrendPoint {
    date: string;
    quantity: number | null;
    source: "survey" | "sp_api" | null;
    basisAt: string | null;
    /** 複数の倉庫を合わせる在庫区分（モール全体・総数）のときだけ、調査のある日の倉庫別の数量。null は取込待ち・未取得 */
    warehouses?: Partial<Record<"amazon" | "boss" | "free" | "stock", number | null>>;
}

export interface InventoryTrendDailySource {
    date: string;
    source: "survey" | "sp_api";
    basisAt: string;
    amazonBasisAt?: string | null;
    amazonInventoryStatus: "pending" | "partial" | "complete" | "legacy_file" | "unclassified";
    /** BOSS 在庫の取得方式。history: 在庫変動履歴から再現（9:00時点） */
    bossSource?: "report" | "history" | null;
}

/** 品番全体の倉庫別合計。null は取込待ち・未取得を含む未確定の倉庫、またはデータのない日 */
export interface InventoryTrendWarehouseTotal {
    date: string;
    amazon: number | null;
    boss: number | null;
    free: number | null;
    stock: number | null;
}

export interface InventoryTrendSeries {
    skuCode: string;
    /** TQ のカラー番号 */
    color: string | null;
    size: string;
    points: InventoryTrendPoint[];
}

/** 詳細画面の品番。groups は期間内で最新の調査に保存された所属（なければ「グループなし」）、数は同じ調査の SKU で数える */
export interface InventoryTrendDetailProduct extends Pick<InventoryTrendProduct, "productCode" | "brand" | "category" | "skuCount"> {
    groups: InventoryTrendOverviewGroup[];
    colorCount: number;
    sizeCount: number;
}

export interface InventoryTrendData {
    product: InventoryTrendDetailProduct | null;
    scope: InventoryScope;
    from: string;
    to: string;
    dates: string[];
    dailySources: InventoryTrendDailySource[];
    warehouseTotals: InventoryTrendWarehouseTotal[];
    series: InventoryTrendSeries[];
}

export interface InventoryTrendProductList {
    data: InventoryTrendProduct[];
    brands: string[];
    dateRange: { from: string; to: string } | null;
}

/** 品番一覧の注目の絞り込み。stockout: 欠品SKUあり／increased: 欠品が増えた／decreasing: 在庫が10%以上減った／long: 30日以上の欠品 */
export type InventoryTrendOverviewFocus = "stockout" | "increased" | "decreasing" | "long";

/** 品番一覧の並び順。stockout: 欠品SKUの多い順／delta: 前週比の小さい順／latest: 最新在庫の少ない順／code: 品番順 */
export type InventoryTrendOverviewSort = "stockout" | "delta" | "latest" | "code";

/** 最新の調査の SKU の状態。pending は取込待ち・未取得・データなし（未確定） */
export type InventoryTrendSkuState = "inStock" | "stockout" | "pending";

export interface InventoryTrendOverviewGroup {
    key: string;
    name: string;
}

/** 品番の、ある日の合計。quantity が null の日は未確定か、調査のない日 */
export interface InventoryTrendOverviewQuantity {
    date: string;
    quantity: number | null;
}

export interface InventoryTrendOverviewProduct {
    productCode: string;
    brand: string;
    category: string;
    skuCount: number;
    groups: InventoryTrendOverviewGroup[];
    latest: InventoryTrendOverviewQuantity;
    /** 前週比で比べる日。7日前（なければ14日前までで最も近い日）に調査がなければ null */
    weekAgo: InventoryTrendOverviewQuantity | null;
    points: InventoryTrendOverviewQuantity[];
    skuStates: InventoryTrendSkuState[];
    stockoutSkuCount: number;
    stockoutSkuCountWeekAgo: number | null;
    /** 期間内の、欠品した SKU × 日の合計 */
    stockoutSkuDays: number;
    /** 最新の調査日まで続いている欠品の、最も長い日数。欠品中の SKU がなければ null */
    longestStockoutStreak: number | null;
}

export interface InventoryTrendOverviewSummary {
    productCount: number;
    skuCount: number;
    stockoutSkuCount: number;
    stockoutSkuCountWeekAgo: number | null;
    longStockoutSkuCount: number;
    longStockoutProductCount: number;
    /** 未確定の品番を含むときは null */
    totalQuantity: number | null;
    totalQuantityWeekAgo: number | null;
    focusCounts: Record<"all" | InventoryTrendOverviewFocus, number>;
}

export interface InventoryTrendOverview {
    data: InventoryTrendOverviewProduct[];
    summary: InventoryTrendOverviewSummary;
    latestSurvey: (Pick<InventoryTrendDailySource, "amazonInventoryStatus"> & { date: string; amazonBasisAt: string | null }) | null;
    meta: { page: number; perPage: number; total: number };
    from: string;
    to: string;
    brands: string[];
    groups: InventoryTrendOverviewGroup[];
    dateRange: { from: string; to: string } | null;
}

export interface InventoryTrendOverviewParams {
    from: string;
    to: string;
    scope: InventoryScope;
    group?: string | null;
    brand?: string | null;
    search?: string | null;
    focus?: InventoryTrendOverviewFocus | null;
    sort?: InventoryTrendOverviewSort | null;
    page?: number;
}

export async function fetchInventoryTrendOverview(params: InventoryTrendOverviewParams): Promise<InventoryTrendOverview> {
    const { data } = await axios.get<InventoryTrendOverview>("/api/inventory-trends/overview", {
        params: Object.fromEntries(Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== "")),
    });
    return data;
}

export async function fetchInventoryTrendProducts(): Promise<InventoryTrendProductList> {
    const { data } = await axios.get<InventoryTrendProductList>("/api/inventory-trends/products");
    return data;
}

export async function fetchInventoryTrend(params: { productCode: string; from: string; to: string; scope: InventoryScope }): Promise<InventoryTrendData> {
    const { data } = await axios.get<{ data: InventoryTrendData }>("/api/inventory-trends", {
        params: {
            product_code: params.productCode,
            from: params.from,
            to: params.to,
            scope: params.scope,
        },
    });
    return data.data;
}

export async function exportInventoryTrend(params: { productCode?: string; from: string; to: string }): Promise<Blob> {
    const { data } = await axios.get<Blob>("/api/inventory-trends/export", {
        params: {
            ...(params.productCode ? { product_code: params.productCode } : {}),
            from: params.from,
            to: params.to,
        },
        responseType: "blob",
    });
    return data;
}
