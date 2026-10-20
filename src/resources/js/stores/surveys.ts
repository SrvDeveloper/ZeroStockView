import axios from "axios";
import { defineStore } from "pinia";
import type { Survey, SurveySummary } from "@/types";
import { toSurveySummary } from "@/utils/stock";

/**
 * 調査履歴。一覧は概要（集計値とファイル）だけを持ち、SKU別の明細は開いた調査の分だけ取得して持つ。
 * 明細を取得・更新したときは、その調査の概要も明細から作り直して揃える。
 */
export const useSurveysStore = defineStore("surveys", {
    state: () => ({
        summaries: [] as SurveySummary[],
        details: {} as Record<string, Survey>,
        loaded: false,
    }),
    getters: {
        sortedSummaries: (state) =>
            [...state.summaries].sort((a, b) => {
                const executedDifference = new Date(b.executedAt).getTime() - new Date(a.executedAt).getTime();
                if (executedDifference !== 0) return executedDifference;

                return new Date(b.createdAt ?? b.executedAt).getTime() - new Date(a.createdAt ?? a.executedAt).getTime();
            }),
        latestSummary(): SurveySummary | null {
            return this.sortedSummaries[0] ?? null;
        },
    },
    actions: {
        async load() {
            const { data } = await axios.get<{ data: SurveySummary[] }>("/api/surveys");
            this.summaries = data.data;
            this.loaded = true;
        },
        getSummary(id: string): SurveySummary | undefined {
            return this.summaries.find((summary) => summary.id === id);
        },
        /** 取得済みの明細。まだ取得していなければ undefined（loadSurvey で取得する） */
        getSurvey(id: string): Survey | undefined {
            return this.details[id];
        },
        /** 明細を取得する。取得済みならそれを返す（force で取り直す） */
        async loadSurvey(id: string, options: { force?: boolean } = {}): Promise<Survey> {
            const cached = this.details[id];
            if (cached && !options.force) return cached;
            const { data } = await axios.get<{ data: Survey }>(`/api/surveys/${id}`);
            this.storeDetail(data.data);
            return data.data;
        },
        async refreshSurvey(id: string): Promise<Survey> {
            return this.loadSurvey(id, { force: true });
        },
        async runImport(formData: FormData): Promise<Survey> {
            const { data } = await axios.post<{ data: Survey }>("/api/surveys", formData);
            this.storeDetail(data.data);
            return data.data;
        },
        async rerunImport(surveyId: string, importSettingId: string): Promise<Survey> {
            const { data } = await axios.post<{ data: Survey }>(`/api/surveys/${surveyId}/rerun`, {
                import_setting_id: importSettingId,
            });
            this.storeDetail(data.data);
            return data.data;
        },
        async removeSurvey(id: string) {
            await axios.delete(`/api/surveys/${id}`);
            this.summaries = this.summaries.filter((summary) => summary.id !== id);
            delete this.details[id];
        },
        async updateProductMemo(surveyId: string, productCode: string, memo: string) {
            const survey = this.getSurvey(surveyId);
            const product = survey?.products.find((p) => p.productCode === productCode);
            if (!product) return;
            await axios.put(`/api/surveys/${surveyId}/products/${product.id}/memo`, { memo });
            product.memo = memo;
        },
        async updateSkuMemo(surveyId: string, skuCode: string, memo: string) {
            const survey = this.getSurvey(surveyId);
            if (!survey) return;
            for (const product of survey.products) {
                const sku = product.skus.find((s) => s.skuCode === skuCode);
                if (sku) {
                    await axios.put(`/api/surveys/${surveyId}/skus/${sku.id}/memo`, { memo });
                    sku.memo = memo;
                    return;
                }
            }
        },
        async removeFile(surveyId: string, fileId: string) {
            await axios.delete(`/api/survey-files/${fileId}`);
            for (const survey of [this.getSummary(surveyId), this.getSurvey(surveyId)]) {
                if (survey) survey.files = survey.files.filter((f) => f.id !== fileId);
            }
        },
        async removeFiles(surveyId: string) {
            await axios.delete(`/api/surveys/${surveyId}/files`);
            for (const survey of [this.getSummary(surveyId), this.getSurvey(surveyId)]) {
                if (survey) survey.files = [];
            }
        },
        /** 明細を保存し、一覧の概要も明細から作り直す（一覧にない調査は先頭に足す） */
        storeDetail(survey: Survey) {
            this.details[survey.id] = survey;
            const summary = toSurveySummary(survey);
            const index = this.summaries.findIndex((item) => item.id === survey.id);
            if (index === -1) this.summaries = [summary, ...this.summaries];
            else this.summaries.splice(index, 1, summary);
        },
    },
});
