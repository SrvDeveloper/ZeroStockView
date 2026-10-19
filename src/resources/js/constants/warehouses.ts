import type { InventoryScope } from "@/api/inventoryTrends";

export type WarehouseKey = "amazon" | "boss" | "free" | "stock";

export interface Warehouse {
    key: WarehouseKey;
    label: string;
    /** SVG の塗りに使うため値で持つ。`app.css` の `--color-wh-*` と同じ値にする */
    color: string;
}

/** 積み上げは必ずこの順にする（隣り合う 2 色が色覚の違いでも見分けられる並び） */
export const WAREHOUSES: readonly Warehouse[] = [
    { key: "amazon", label: "Amazon", color: "#e08a2e" },
    { key: "boss", label: "BOSS", color: "#3a6fb8" },
    { key: "free", label: "フリー在庫", color: "#5f9e3a" },
    { key: "stock", label: "ECストック", color: "#8e5bb8" },
];

const SCOPE_WAREHOUSES: Record<InventoryScope, readonly WarehouseKey[]> = {
    mallTotal: ["amazon", "boss"],
    amazon: ["amazon"],
    boss: ["boss"],
    free: ["free"],
    stock: ["stock"],
    grandTotal: ["amazon", "boss", "free", "stock"],
};

/** 在庫区分に含まれる倉庫を、積み上げの順で返す */
export function warehousesOfScope(scope: InventoryScope): Warehouse[] {
    return WAREHOUSES.filter((warehouse) => SCOPE_WAREHOUSES[scope].includes(warehouse.key));
}
