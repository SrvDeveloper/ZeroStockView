export interface NavItem {
    name: string;
    label: string;
    icon: string;
}

export const NAV_ITEMS: NavItem[] = [
    { name: "data-import", label: "データ取込", icon: "upload" },
    { name: "import-settings", label: "取込設定", icon: "settings" },
    { name: "survey-history", label: "在庫調査履歴", icon: "history" },
    { name: "file-management", label: "ファイル管理", icon: "files" },
    { name: "manual", label: "操作マニュアル", icon: "menu_book" },
];
