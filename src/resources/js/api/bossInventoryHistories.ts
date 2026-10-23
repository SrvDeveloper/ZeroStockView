import axios from "axios";
import type { SurveySummary } from "@/types";

/** NULL（データなし）になる理由。mismatch: 基準の調査と合わない、chainBreak: 履歴のつながりが切れている、negative: マイナス、noData: 基準の調査にもない */
export type BossHistoryNullReason = "mismatch" | "chainBreak" | "negative" | "noData";

export interface BossHistoryBaseSurvey {
    date: string;
    surveyId: string;
    /** 推定したレポートの出力時刻。使えない基準の調査は null */
    estimatedReportAt: string | null;
    mismatchCount: number;
    unusableReason: string | null;
}

export interface BossHistoryDay {
    date: string;
    basisAt: string;
    surveyExists: boolean;
    surveyId: string | null;
    filledSkuCount: number;
    nullSkuCount: number;
    nullReasons: Partial<Record<BossHistoryNullReason, number>>;
}

/** 在庫変動履歴で、どの日をどう補完するか（確認の応答。適用の応答の summary も同じ形） */
export interface BossHistoryPreview {
    periodFrom: string;
    periodTo: string;
    rowCount: number;
    typeCounts: Record<string, number>;
    baseSurveys: BossHistoryBaseSurvey[];
    days: BossHistoryDay[];
    unfillableDates: { date: string; reason: string }[];
    /** 合わない SKU・倉庫（最大 100件） */
    mismatches: { skuCode: string; warehouse: string; reason: BossHistoryNullReason }[];
    mismatchCount: number;
}

export interface BossHistoryApplyResult {
    surveys: SurveySummary[];
    summary: BossHistoryPreview;
}

function formData(file: File): FormData {
    const data = new FormData();
    data.append("file", file);
    return data;
}

export async function previewBossInventoryHistory(file: File): Promise<BossHistoryPreview> {
    const { data } = await axios.post<{ data: BossHistoryPreview }>("/api/boss-inventory-histories/preview", formData(file));
    return data.data;
}

export async function applyBossInventoryHistory(file: File): Promise<BossHistoryApplyResult> {
    const { data } = await axios.post<{ data: BossHistoryApplyResult }>("/api/boss-inventory-histories", formData(file));
    return data.data;
}
