import axios from "axios";
import { defineStore } from "pinia";
import type { Survey } from "@/types";

export const useSurveysStore = defineStore("surveys", {
    state: () => ({
        surveys: [] as Survey[],
        loaded: false,
    }),
    getters: {
        sortedSurveys: (state) =>
            [...state.surveys].sort((a, b) => {
                const executedDifference = new Date(b.executedAt).getTime() - new Date(a.executedAt).getTime();
                if (executedDifference !== 0) return executedDifference;

                return new Date(b.createdAt ?? b.executedAt).getTime() - new Date(a.createdAt ?? a.executedAt).getTime();
            }),
        latestSurvey(): Survey | null {
            return this.sortedSurveys[0] ?? null;
        },
    },
    actions: {
        async load() {
            const { data } = await axios.get<{ data: Survey[] }>("/api/surveys");
            this.surveys = data.data;
            this.loaded = true;
        },
        getSurvey(id: string): Survey | undefined {
            return this.surveys.find((s) => s.id === id);
        },
        async runImport(formData: FormData): Promise<Survey> {
            const { data } = await axios.post<{ data: Survey }>("/api/surveys", formData);
            const survey = data.data;
            this.surveys.unshift(survey);
            return survey;
        },
        async rerunImport(surveyId: string, importSettingId: string): Promise<Survey> {
            const { data } = await axios.post<{ data: Survey }>(`/api/surveys/${surveyId}/rerun`, {
                import_setting_id: importSettingId,
            });
            const survey = data.data;
            this.surveys.unshift(survey);
            return survey;
        },
        async removeSurvey(id: string) {
            await axios.delete(`/api/surveys/${id}`);
            this.surveys = this.surveys.filter((s) => s.id !== id);
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
            const survey = this.getSurvey(surveyId);
            if (!survey) return;
            survey.files = survey.files.filter((f) => f.id !== fileId);
        },
        async removeFiles(surveyId: string) {
            await axios.delete(`/api/surveys/${surveyId}/files`);
            const survey = this.getSurvey(surveyId);
            if (!survey) return;
            survey.files = [];
        },
    },
});
