export type UserRole = "admin" | "editor" | "viewer";

export const ALL_ROLES: readonly UserRole[] = ["admin", "editor", "viewer"];
export const EDITOR_ROLES: readonly UserRole[] = ["admin", "editor"];
export const ADMIN_ROLES: readonly UserRole[] = ["admin"];

export function isUserRole(value: unknown): value is UserRole {
    return ALL_ROLES.includes(value as UserRole);
}

export function canAccess(role: UserRole | null | undefined, allowedRoles: readonly UserRole[]): boolean {
    return role !== null && role !== undefined && allowedRoles.includes(role);
}

export function homeRouteName(role: UserRole): "data-import" | "survey-history" {
    return role === "viewer" ? "survey-history" : "data-import";
}

/** 画面に出す権限名。並びは権限の強い順 */
export const ROLE_LABELS: Record<UserRole, string> = { admin: "特権管理者", editor: "編集者", viewer: "閲覧者" };

/** 権限を選ぶときの説明（docs/ユーザー管理実装/README.md の確定要件） */
export const ROLE_DESCRIPTIONS: Record<UserRole, string> = {
    admin: "すべての操作とユーザー管理",
    editor: "データ取込・再照合・品番グループの追加と編集",
    viewer: "履歴・推移の閲覧とファイル出力",
};
