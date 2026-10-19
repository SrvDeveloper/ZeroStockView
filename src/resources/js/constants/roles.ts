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
