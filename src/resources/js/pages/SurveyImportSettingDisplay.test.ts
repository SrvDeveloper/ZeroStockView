import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyHistoryPage from "@/pages/SurveyHistoryPage.vue";
import SurveyResultPage from "@/pages/SurveyResultPage.vue";
import { useSurveysStore } from "@/stores/surveys";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({
    useRouter: () => ({ push }),
}));

function seedSurvey() {
    const surveys = useSurveysStore();
    surveys.surveys = [
        {
            id: "1",
            executedAt: "2026-09-16T01:00:00Z",
            importSettingName: "売上TOP20",
            products: [],
            files: [],
        },
    ];

    return surveys;
}

describe("survey import setting display", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        push.mockReset();
        seedSurvey();
    });

    it("shows the import setting in the survey history", () => {
        const wrapper = mount(SurveyHistoryPage);

        expect(wrapper.text()).toContain("取込設定");
        expect(wrapper.text()).toContain("売上TOP20");
    });

    it("shows the import setting in the survey result", () => {
        const wrapper = mount(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        expect(wrapper.text()).toContain("取込設定: 売上TOP20");
    });

    it("clears each text filter from its clear button", async () => {
        const wrapper = mount(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });
        const filters = [
            { placeholder: "品番で絞込", label: "品番の絞込をクリア" },
            { placeholder: "SKUで絞込", label: "SKUの絞込をクリア" },
            { placeholder: "ASINで絞込", label: "ASINの絞込をクリア" },
        ];

        for (const filter of filters) {
            const input = wrapper.get(`input[placeholder="${filter.placeholder}"]`);
            await input.setValue("検索値");

            await wrapper.get(`button[aria-label="${filter.label}"]`).trigger("click");

            expect((input.element as HTMLInputElement).value).toBe("");
            expect(wrapper.find(`button[aria-label="${filter.label}"]`).exists()).toBe(false);
        }
    });
});
