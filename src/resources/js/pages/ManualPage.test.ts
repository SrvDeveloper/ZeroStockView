import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualPage from "@/pages/ManualPage.vue";

describe("ManualPage", () => {
    it("renders a table of contents linked to every manual section", () => {
        const wrapper = mount(ManualPage);
        const links = wrapper.findAll('nav[aria-label="操作マニュアルの目次"] a');

        expect(links.map((link) => link.attributes("href"))).toEqual(["#introduction", "#basic-operation", "#notes", "#faq"]);
        expect(wrapper.findAll("section")).toHaveLength(4);
    });

    it("shows reusable callouts and an image placeholder", () => {
        const wrapper = mount(ManualPage);

        expect(wrapper.findAllComponents(ManualCallout)).toHaveLength(3);
        expect(wrapper.getComponent(ManualScreenshot).text()).toContain("ここに画面キャプチャを配置");
        expect(wrapper.find('[role="alert"]').text()).toContain("注意");
    });
});
