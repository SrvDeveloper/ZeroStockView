import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import ManualPage from "@/pages/ManualPage.vue";

describe("ManualPage", () => {
    it("renders a table of contents linked to every manual section", () => {
        const wrapper = mount(ManualPage);
        const links = wrapper.findAll('nav[aria-label="操作マニュアルの目次"] a');

        const sectionIds = wrapper.findAll("section").map((section) => section.attributes("id"));

        expect(sectionIds).toEqual(["introduction", "basic-operation-amazon", "basic-operation-boss", "basic-operation-ec", "basic-operation-free", "notes", "faq"]);
        expect(links.map((link) => link.attributes("href"))).toEqual(sectionIds.map((id) => `#${id}`));
    });

    it("shows callouts and screenshots for the import instructions", () => {
        const wrapper = mount(ManualPage);

        expect(wrapper.findAllComponents(ManualCallout)).toHaveLength(8);
        expect(wrapper.getComponent(ManualScreenshot).get("img").attributes("src")).toBe("/img/manual/01-1.png");
        expect(wrapper.find('[role="alert"]').text()).toContain("注意");
    });
});
