import type { Product } from "@/types";

/** CrossWalker 外部API v1のレスポンス。コード類は先頭のゼロを保持する文字列。 */
export interface CrossWalkerSku {
    sku_code: string;
    child_asin: string | null;
    status: "active" | "inactive";
    tq_item_no: string;
    tq_color_no: string;
    tq_size: string;
}

export interface CrossWalkerItem {
    item_no: string;
    brand: string;
    category: string;
    parent_asin: string | null;
    status: "active" | "inactive";
    skus: CrossWalkerSku[];
    updated_at: string | null;
}

/**
 * POST /items/lookup の data に相当するUI確認用データ（実在商品ではない）。
 * 未設定値、サイズなし、無効な品番・SKU、SKUなしを含む。
 * SKUコードとTQ商品キーは別々の識別子として保持し、コードから推測しない。
 * 無効な品番の所属SKUはすべて inactive。SKUの配列順はAPIの表示順として扱う。
 */
export const CROSSWALKER_ITEMS: CrossWalkerItem[] = [
    {
        item_no: "A-1001",
        brand: "ALPHA",
        category: "アウター",
        parent_asin: "B000001000",
        status: "active",
        skus: [
            {
                sku_code: "A-1001-01-S",
                child_asin: "B000000001",
                status: "active",
                tq_item_no: "A1001",
                tq_color_no: "01",
                tq_size: "S",
            },
            {
                sku_code: "A-1001-01-M",
                child_asin: "B000000002",
                status: "active",
                tq_item_no: "A1001",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "A-1001-01-L",
                child_asin: "B000000003",
                status: "inactive",
                tq_item_no: "A1001",
                tq_color_no: "01",
                tq_size: "L",
            },
            {
                sku_code: "A-1001-02-M",
                child_asin: "B000000004",
                status: "active",
                tq_item_no: "A1001",
                tq_color_no: "02",
                tq_size: "M",
            },
            {
                sku_code: "A-1001-02-L",
                child_asin: "B000000005",
                status: "active",
                tq_item_no: "A1001",
                tq_color_no: "02",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "A-1002",
        brand: "ALPHA",
        category: "アウター",
        parent_asin: null,
        status: "active",
        skus: [
            {
                sku_code: "A-1002-01-S",
                child_asin: "B000000006",
                status: "active",
                tq_item_no: "A1002",
                tq_color_no: "01",
                tq_size: "S",
            },
            {
                sku_code: "A-1002-01-M",
                child_asin: null,
                status: "active",
                tq_item_no: "A1002",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "A-1002-01-L",
                child_asin: "B000000008",
                status: "active",
                tq_item_no: "A1002",
                tq_color_no: "01",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "B-2001",
        brand: "BRAVO",
        category: "トップス",
        parent_asin: "B000001002",
        status: "active",
        skus: [
            {
                sku_code: "B-2001-01-S",
                child_asin: "B000000009",
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "01",
                tq_size: "S",
            },
            {
                sku_code: "B-2001-01-M",
                child_asin: "B000000010",
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "B-2001-01-L",
                child_asin: "B000000011",
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "01",
                tq_size: "L",
            },
            {
                sku_code: "B-2001-01-XL",
                child_asin: "B000000012",
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "01",
                tq_size: "XL",
            },
            {
                sku_code: "B-2001-02-M",
                child_asin: "B000000013",
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "02",
                tq_size: "M",
            },
            {
                sku_code: "B-2001-02-L",
                child_asin: null,
                status: "active",
                tq_item_no: "B2001",
                tq_color_no: "02",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "B-2002",
        brand: "BRAVO",
        category: "トップス",
        parent_asin: "B000001003",
        status: "active",
        skus: [
            {
                sku_code: "B-2002-01-M",
                child_asin: "B000000015",
                status: "active",
                tq_item_no: "B2002",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "B-2002-01-L",
                child_asin: "B000000016",
                status: "active",
                tq_item_no: "B2002",
                tq_color_no: "01",
                tq_size: "L",
            },
            {
                sku_code: "B-2002-03-M",
                child_asin: "B000000017",
                status: "active",
                tq_item_no: "B2002",
                tq_color_no: "03",
                tq_size: "M",
            },
            {
                sku_code: "B-2002-03-L",
                child_asin: "B000000018",
                status: "active",
                tq_item_no: "B2002",
                tq_color_no: "03",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "B-2003",
        brand: "BRAVO",
        category: "トップス",
        parent_asin: "B000001004",
        status: "active",
        skus: [
            {
                sku_code: "B-2003-01-F",
                child_asin: "B000000019",
                status: "active",
                tq_item_no: "B2003",
                tq_color_no: "01",
                tq_size: "",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "C-3001",
        brand: "CHARLIE",
        category: "パンツ",
        parent_asin: null,
        status: "active",
        skus: [
            {
                sku_code: "C-3001-01-S",
                child_asin: "B000000020",
                status: "active",
                tq_item_no: "C3001",
                tq_color_no: "01",
                tq_size: "S",
            },
            {
                sku_code: "C-3001-01-M",
                child_asin: null,
                status: "active",
                tq_item_no: "C3001",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "C-3001-01-L",
                child_asin: "B000000022",
                status: "active",
                tq_item_no: "C3001",
                tq_color_no: "01",
                tq_size: "L",
            },
            {
                sku_code: "C-3001-02-M",
                child_asin: "B000000023",
                status: "active",
                tq_item_no: "C3001",
                tq_color_no: "02",
                tq_size: "M",
            },
            {
                sku_code: "C-3001-02-L",
                child_asin: "B000000024",
                status: "active",
                tq_item_no: "C3001",
                tq_color_no: "02",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "C-3002",
        brand: "CHARLIE",
        category: "パンツ",
        parent_asin: "B000001006",
        status: "active",
        skus: [
            {
                sku_code: "C-3002-01-S",
                child_asin: "B000000025",
                status: "active",
                tq_item_no: "C3002",
                tq_color_no: "01",
                tq_size: "S",
            },
            {
                sku_code: "C-3002-01-M",
                child_asin: "B000000026",
                status: "active",
                tq_item_no: "C3002",
                tq_color_no: "01",
                tq_size: "M",
            },
            {
                sku_code: "C-3002-01-L",
                child_asin: "B000000027",
                status: "active",
                tq_item_no: "C3002",
                tq_color_no: "01",
                tq_size: "L",
            },
            {
                sku_code: "C-3002-01-XL",
                child_asin: null,
                status: "active",
                tq_item_no: "C3002",
                tq_color_no: "01",
                tq_size: "XL",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "C-3003",
        brand: "CHARLIE",
        category: "パンツ",
        parent_asin: "B000001007",
        status: "active",
        skus: [
            {
                sku_code: "C-3003-02-M",
                child_asin: "B000000029",
                status: "active",
                tq_item_no: "C3003",
                tq_color_no: "02",
                tq_size: "M",
            },
            {
                sku_code: "C-3003-02-L",
                child_asin: "B000000030",
                status: "active",
                tq_item_no: "C3003",
                tq_color_no: "02",
                tq_size: "L",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "D-4001",
        brand: "DELTA",
        category: "シューズ",
        parent_asin: "B000001008",
        status: "active",
        skus: [
            {
                sku_code: "D-4001-01-24.0",
                child_asin: "B000000031",
                status: "active",
                tq_item_no: "D4001",
                tq_color_no: "01",
                tq_size: "24.0",
            },
            {
                sku_code: "D-4001-01-25.0",
                child_asin: "B000000032",
                status: "active",
                tq_item_no: "D4001",
                tq_color_no: "01",
                tq_size: "25.0",
            },
            {
                sku_code: "D-4001-01-26.0",
                child_asin: "B000000033",
                status: "active",
                tq_item_no: "D4001",
                tq_color_no: "01",
                tq_size: "26.0",
            },
            {
                sku_code: "D-4001-01-27.0",
                child_asin: "B000000034",
                status: "active",
                tq_item_no: "D4001",
                tq_color_no: "01",
                tq_size: "27.0",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "D-4002",
        brand: "DELTA",
        category: "シューズ",
        parent_asin: null,
        status: "inactive",
        skus: [
            {
                sku_code: "D-4002-02-25.0",
                child_asin: null,
                status: "inactive",
                tq_item_no: "D4002",
                tq_color_no: "02",
                tq_size: "25.0",
            },
            {
                sku_code: "D-4002-02-26.0",
                child_asin: "B000000036",
                status: "inactive",
                tq_item_no: "D4002",
                tq_color_no: "02",
                tq_size: "26.0",
            },
            {
                sku_code: "D-4002-02-27.0",
                child_asin: "B000000037",
                status: "inactive",
                tq_item_no: "D4002",
                tq_color_no: "02",
                tq_size: "27.0",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "D-4003",
        brand: "DELTA",
        category: "シューズ",
        parent_asin: "B000001010",
        status: "active",
        skus: [
            {
                sku_code: "D-4003-01-25.0",
                child_asin: "B000000038",
                status: "active",
                tq_item_no: "D4003",
                tq_color_no: "01",
                tq_size: "25.0",
            },
            {
                sku_code: "D-4003-01-26.0",
                child_asin: "B000000039",
                status: "active",
                tq_item_no: "D4003",
                tq_color_no: "01",
                tq_size: "26.0",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "E-5001",
        brand: "ALPHA",
        category: "バッグ",
        parent_asin: "B000001011",
        status: "active",
        skus: [
            {
                sku_code: "E-5001-01-F",
                child_asin: "B000000040",
                status: "active",
                tq_item_no: "E5001",
                tq_color_no: "01",
                tq_size: "",
            },
            {
                sku_code: "E-5001-02-F",
                child_asin: "B000000041",
                status: "active",
                tq_item_no: "E5001",
                tq_color_no: "02",
                tq_size: "",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "E-5002",
        brand: "BRAVO",
        category: "バッグ",
        parent_asin: "B000001012",
        status: "active",
        skus: [
            {
                sku_code: "E-5002-01-F",
                child_asin: null,
                status: "active",
                tq_item_no: "E5002",
                tq_color_no: "01",
                tq_size: "",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "E-5003",
        brand: "CHARLIE",
        category: "バッグ",
        parent_asin: null,
        status: "active",
        skus: [
            {
                sku_code: "E-5003-03-F",
                child_asin: "B000000043",
                status: "active",
                tq_item_no: "E5003",
                tq_color_no: "03",
                tq_size: "",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
    {
        item_no: "F-6001",
        brand: "DELTA",
        category: "トップス",
        parent_asin: "B000001014",
        status: "active",
        skus: [],
        updated_at: null,
    },
    {
        item_no: "00001",
        brand: "栞",
        category: "老眼鏡",
        parent_asin: null,
        status: "active",
        skus: [
            {
                sku_code: "00001-01-00",
                child_asin: null,
                status: "active",
                tq_item_no: "00001",
                tq_color_no: "01",
                tq_size: "",
            },
        ],
        updated_at: "2026-09-02T00:00:00.000000Z",
    },
];

/**
 * 既存UI用モデルへの変換。API側のnullは上記データに保持し、表示用は空文字にする。
 * APIにカラー名称はないため、既存UI用のカラー名称は空文字にする。
 * 独立した表示用サイズはないため、サイズはTQサイズを使う。
 * 親ASINを子ASINの代わりに使わず、無効なSKUも除外しない。
 */
export const PRODUCT_MASTER: Product[] = CROSSWALKER_ITEMS.map((item) => ({
    productCode: item.item_no,
    brand: item.brand,
    category: item.category,
    parentAsin: item.parent_asin ?? "",
    skus: item.skus.map((sku) => ({
        skuCode: sku.sku_code,
        colorName: "",
        size: sku.tq_size,
        asin: sku.child_asin ?? "",
        tqCode: sku.tq_item_no,
        tqColorNo: sku.tq_color_no,
        tqSize: sku.tq_size,
    })),
}));

export const BRAND_LIST: string[] = Array.from(new Set(PRODUCT_MASTER.map((p) => p.brand)));
export const CATEGORY_LIST: string[] = Array.from(new Set(PRODUCT_MASTER.map((p) => p.category)));

export function findProduct(productCode: string): Product | undefined {
    return PRODUCT_MASTER.find((p) => p.productCode === productCode);
}

export interface ProductPageRequest {
    query: string;
    brand: string | null;
    category: string | null;
    page: number;
    perPage: number;
    excludedProductCodes: string[];
}

export interface ProductPageResponse {
    products: Product[];
    page: number;
    perPage: number;
    total: number;
    totalPages: number;
}

/** 実API接続時にHTTPクライアントへ置き換える、ページ取得の非同期境界。 */
export async function fetchProductPage(request: ProductPageRequest): Promise<ProductPageResponse> {
    await Promise.resolve();

    const query = request.query.trim().toLowerCase();
    const excludedCodes = new Set(request.excludedProductCodes);
    const products = PRODUCT_MASTER.filter((product) => !excludedCodes.has(product.productCode))
        .filter((product) => !request.brand || product.brand === request.brand)
        .filter((product) => !request.category || product.category === request.category)
        .filter((product) => !query || product.productCode.toLowerCase().includes(query));

    const total = products.length;
    const totalPages = Math.max(1, Math.ceil(total / request.perPage));
    const page = Math.min(Math.max(request.page, 1), totalPages);
    const start = (page - 1) * request.perPage;

    return {
        products: products.slice(start, start + request.perPage),
        page,
        perPage: request.perPage,
        total,
        totalPages,
    };
}
