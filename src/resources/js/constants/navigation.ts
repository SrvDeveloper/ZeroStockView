import { ADMIN_ROLES, ALL_ROLES, EDITOR_ROLES, canAccess, type UserRole } from "@/constants/roles";

export interface NavItem {
    name: string;
    label: string;
    icon: string;
    roles: readonly UserRole[];
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
            { name: "data-import", label: "データ取込", icon: "upload", roles: EDITOR_ROLES },
            { name: "survey-history", label: "在庫調査履歴", icon: "history", roles: ALL_ROLES, children: ["survey-result"] },
            { name: "inventory-trends", label: "在庫推移", icon: "show_chart", roles: ALL_ROLES },
        ],
    },
    {
        label: "設定",
        items: [
            { name: "import-settings", label: "品番グループ", icon: "category", roles: EDITOR_ROLES, children: ["import-setting-new", "import-setting-edit"] },
            { name: "file-management", label: "ファイル管理", icon: "folder_open", roles: ADMIN_ROLES },
            { name: "api-connection-settings", label: "API連携設定", icon: "api", roles: ADMIN_ROLES },
            { name: "user-management", label: "ユーザー管理", icon: "manage_accounts", roles: ADMIN_ROLES },
        ],
    },
    {
        label: "ヘルプ",
        items: [{ name: "manual", label: "操作マニュアル", icon: "menu_book", roles: ALL_ROLES }],
    },
];

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((group) => group.items);

export function navGroupsForRole(role: UserRole | null): NavGroup[] {
    return NAV_GROUPS.map((group) => ({ ...group, items: group.items.filter((item) => canAccess(role, item.roles)) })).filter((group) => group.items.length > 0);
}

export function isNavItemActive(item: NavItem, routeName: string | symbol | null | undefined): boolean {
    if (typeof routeName !== "string") return false;
    return item.name === routeName || (item.children?.includes(routeName) ?? false);
}
