import { defineStore } from "pinia";
import type { LocationQueryRaw } from "vue-router";

/** 在庫推移の品番一覧で最後に表示した品番の並びと条件。詳細画面の「前の品番／次の品番」と「品番一覧に戻る」に使う */
export const useInventoryTrendListStore = defineStore("inventoryTrendList", {
    state: () => ({
        productCodes: [] as string[],
        query: {} as LocationQueryRaw,
    }),
    actions: {
        remember(productCodes: string[], query: LocationQueryRaw) {
            this.productCodes = productCodes;
            this.query = query;
        },
    },
});
