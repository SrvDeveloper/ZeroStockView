import axios from "axios";

export type InventoryScope = "mallTotal" | "amazon" | "boss" | "free" | "stock" | "grandTotal";

export interface InventoryTrendProduct {
    productCode: string;
    brand: string;
    category: string;
    skuCount: number;
}

export interface InventoryTrendPoint {
    date: string;
    quantity: number | null;
}

export interface InventoryTrendSeries {
    skuCode: string;
    size: string;
    points: InventoryTrendPoint[];
}

export interface InventoryTrendData {
    product: Pick<InventoryTrendProduct, "productCode" | "brand" | "category"> | null;
    scope: InventoryScope;
    from: string;
    to: string;
    dates: string[];
    series: InventoryTrendSeries[];
}

export interface InventoryTrendProductList {
    data: InventoryTrendProduct[];
    brands: string[];
    dateRange: { from: string; to: string } | null;
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
