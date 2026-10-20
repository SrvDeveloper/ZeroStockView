import { FILE_EXPIRY_WARNING_DAYS, FILE_RETENTION_DAYS } from "@/constants/files";
import type { Survey } from "@/types";

const DAY_MS = 24 * 60 * 60 * 1000;

/** 保存期限までの残り日数（日単位に切り上げ）。0 以下は期限切れ */
export function fileRemainingDays(uploadedAt: string, now: Date = new Date()): number {
    const expiresAt = new Date(uploadedAt).getTime() + FILE_RETENTION_DAYS * DAY_MS;
    return Math.ceil((expiresAt - now.getTime()) / DAY_MS);
}

export function isFileExpiringSoon(uploadedAt: string, now: Date = new Date()): boolean {
    const remaining = fileRemainingDays(uploadedAt, now);
    return remaining > 0 && remaining <= FILE_EXPIRY_WARNING_DAYS;
}

/** 期限まで {@link FILE_EXPIRY_WARNING_DAYS} 日以内の保存ファイル数 */
export function countExpiringFiles(surveys: Pick<Survey, "files">[], now: Date = new Date()): number {
    return surveys.reduce((count, survey) => count + survey.files.filter((file) => isFileExpiringSoon(file.uploadedAt, now)).length, 0);
}
