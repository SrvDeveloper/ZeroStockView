import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import StockCell from "@/components/ui/StockCell.vue";

describe("StockCell", () => {
    it("shows the breakdown under a positive quantity", () => {
        const wrapper = mount(StockCell, { props: { quantity: 12, state: "has", breakdown: [4, 8] } });
        expect(wrapper.attributes("data-stock-state")).toBe("has");
        expect(wrapper.text()).toContain("12");
        expect(wrapper.get("[data-breakdown]").text()).toBe("（内訳 4 / 8）");
    });

    it("hides the breakdown for zero and shows a zero chip", () => {
        const wrapper = mount(StockCell, { props: { quantity: 0, state: "zero", breakdown: [0, 0] } });
        expect(wrapper.text()).toBe("0");
        expect(wrapper.find("[data-breakdown]").exists()).toBe(false);
        expect(wrapper.attributes("data-stock-state")).toBe("zero");
    });

    it("distinguishes pending, labelled pending and outside scope", () => {
        expect(mount(StockCell, { props: { quantity: null, state: "pending" } }).text()).toBe("—");
        expect(mount(StockCell, { props: { quantity: null, state: "pending", pendingLabel: "取込待ち" } }).text()).toBe("取込待ち");
        expect(mount(StockCell, { props: { quantity: null, state: "na" } }).text()).toBe("対象外");
    });
});

describe("SegmentedControl", () => {
    it("marks the selected option and emits the chosen value", async () => {
        const wrapper = mount(SegmentedControl, {
            props: {
                modelValue: "and",
                label: "結合",
                options: [
                    { value: "and", label: "AND" },
                    { value: "or", label: "OR" },
                ],
            },
        });

        expect(wrapper.attributes("role")).toBe("radiogroup");
        expect(wrapper.get('[data-value="and"]').attributes("aria-checked")).toBe("true");
        expect(wrapper.get('[data-value="or"]').attributes("aria-checked")).toBe("false");

        await wrapper.get('[data-value="or"]').trigger("click");
        expect(wrapper.emitted("update:modelValue")).toEqual([["or"]]);
    });
});
