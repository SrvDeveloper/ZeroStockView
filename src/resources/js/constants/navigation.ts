export interface NavItem {
    name: string;
    label: string;
    icon: string;
    /** この項目を選択中として扱う子画面のルート名 */
    children?: string[];
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

export const NAV_GROUPS: NavGroup[] = [
    {
        label: "調査",
        items: [
            { name: "data-import", label: "データ取込", icon: "upload" },
            { name: "survey-history", label: "在庫調査履歴", icon: "history", children: ["survey-result"] },
            { name: "inventory-trends", label: "在庫推移", icon: "show_chart" },
        ],
    },
    {
        label: "設定",
        items: [
            { name: "import-settings", label: "品番グループ", icon: "category", children: ["import-setting-new", "import-setting-edit"] },
            { name: "file-management", label: "ファイル管理", icon: "folder_open" },
            { name: "api-connection-settings", label: "API連携設定", icon: "api" },
        ],
    },
    {
        label: "ヘルプ",
        items: [{ name: "manual", label: "操作マニュアル", icon: "menu_book" }],
    },
];

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((group) => group.items);

export function isNavItemActive(item: NavItem, routeName: string | symbol | null | undefined): boolean {
    if (typeof routeName !== "string") return false;
    return item.name === routeName || (item.children?.includes(routeName) ?? false);
}
