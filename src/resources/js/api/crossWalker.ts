import axios from "axios";
import type { Product } from "@/types";

export interface CrossWalkerProductPage {
    products: Product[];
    page: number;
    perPage: number;
    total: number;
    totalPages: number;
}

export async function fetchCrossWalkerProducts(keyword: string, page: number, perPage: number): Promise<CrossWalkerProductPage> {
    const { data } = await axios.get<CrossWalkerProductPage>("/api/crosswalker/items", {
        params: {
            keyword: keyword || undefined,
            page,
            per_page: perPage,
        },
    });

    return data;
}
