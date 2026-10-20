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

        expect(sectionIds).toEqual(["introduction", "basic-operation-boss", "basic-operation-ec", "basic-operation-free", "data-import", "basic-operation-amazon", "notes"]);
        expect(links.map((link) => link.attributes("href"))).toEqual(sectionIds.map((id) => `#${id}`));
    });

    it("shows callouts and screenshots for the import instructions", () => {
        const wrapper = mount(ManualPage);

        expect(wrapper.findAllComponents(ManualCallout)).toHaveLength(7);
        expect(wrapper.getComponent(ManualScreenshot).get("img").attributes("src")).toBe("/img/manual/02-1.png");
        expect(wrapper.get("#basic-operation-amazon").text()).toContain("SP-API");
        expect(wrapper.get("#basic-operation-free").find('[role="alert"]').text()).toContain("ゼロ在庫出力のチェックを外さない");
    });

    it("introduces the daily workflow and what each role can do", () => {
        const wrapper = mount(ManualPage);
        const introduction = wrapper.get("#introduction");

        expect(introduction.text()).toContain("1日の作業の流れ");
        expect(introduction.text()).toContain("CrossWalker");
        const rows = introduction.findAll("[data-role-permissions] tbody tr");
        expect(rows.map((row) => row.find("td").text())).toContain("データ取込、保存ファイルでの再照合");
        const importRow = rows.find((row) => row.text().startsWith("データ取込"))!;
        expect(
            importRow
                .findAll("td")
                .slice(1)
                .map((cell) => cell.text()),
        ).toEqual(["－", "○", "○"]);
    });

    it("explains the data import and the same-day update", () => {
        const wrapper = mount(ManualPage);
        const dataImport = wrapper.get("#data-import");

        expect(dataImport.text()).toContain("取込・調査を実行");
        expect(dataImport.text()).toContain("1〜3件");
        expect(dataImport.text()).toContain("同じ日に追加で取り込むとき");
        expect(dataImport.find('[role="alert"]').text()).toContain("取込に失敗しました");
    });

    it("explains the Amazon inventory fetch and its statuses", () => {
        const wrapper = mount(ManualPage);
        const amazon = wrapper.get("#basic-operation-amazon");

        expect(amazon.text()).toContain("毎朝9:00");
        expect(amazon.text()).toContain("手動で取得する（特権管理者のみ）");
        expect(amazon.findAll("[data-amazon-import-statuses] tbody td:first-child").map((cell) => cell.text())).toEqual(["受付", "処理中", "成功", "失敗"]);
        expect(amazon.findAll("[data-amazon-survey-statuses] tbody td:first-child").map((cell) => cell.text())).toEqual(["取込済み", "取込待ち", "一部未取得"]);
    });

    it("no longer asks users to rename the downloaded files", () => {
        const wrapper = mount(ManualPage);

        expect(wrapper.text()).not.toContain("リネーム");
        expect(wrapper.find("#faq").exists()).toBe(false);
    });
});
