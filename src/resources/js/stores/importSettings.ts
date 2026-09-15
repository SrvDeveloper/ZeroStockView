import axios from "axios";
import { defineStore } from "pinia";
import type { Product } from "@/types";

export const MAX_TARGET_PRODUCTS = 50;

export const useImportSettingsStore = defineStore("importSettings", {
    state: () => ({
        selectedProductCodes: [] as string[],
        products: [] as Product[],
        lastSyncedAt: null as string | null,
        loaded: false,
    }),
    getters: {
        isConfigured: (state) => state.selectedProductCodes.length > 0,
        count: (state) => state.selectedProductCodes.length,
    },
    actions: {
        findProduct(productCode: string): Product | undefined {
            return this.products.find((product) => product.productCode === productCode);
        },
        async load() {
            const { data } = await axios.get<{ productCodes: string[]; products: Product[]; lastSyncedAt: string | null }>("/api/import-targets");
            this.selectedProductCodes = data.productCodes;
            this.products = data.products;
            this.lastSyncedAt = data.lastSyncedAt;
            this.loaded = true;
        },
        async save(productCodes: string[]) {
            const { data } = await axios.put<{ productCodes: string[]; products: Product[]; lastSyncedAt: string | null }>("/api/import-targets", {
                product_codes: productCodes,
            });
            this.selectedProductCodes = data.productCodes;
            this.products = data.products;
            this.lastSyncedAt = data.lastSyncedAt;
            this.loaded = true;
        },
    },
});
