import { mount, RouterLinkStub } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import ManualCallout from "@/components/manual/ManualCallout.vue";
import ManualScreenshot from "@/components/manual/ManualScreenshot.vue";
import { MANUAL_SECTIONS, adjacentManualSections } from "@/constants/manual";
import type { UserRole } from "@/constants/roles";
import ManualPage from "@/pages/ManualPage.vue";
import { useAuthStore } from "@/stores/auth";

function signIn(role: UserRole) {
    useAuthStore().user = { id: "1", loginId: role, role };
}

function mountManual(section?: string) {
    return mount(ManualPage, { props: { section }, global: { stubs: { RouterLink: RouterLinkStub } }, attachTo: document.body });
}

function linkTargets(wrapper: ReturnType<typeof mountManual>, selector: string): (string | undefined)[] {
    const container = wrapper.get(selector).element;
    return wrapper
        .findAllComponents(RouterLinkStub)
        .filter((link) => container.contains(link.element))
        .map((link) => (link.props("to") as { params?: { section?: string } }).params?.section);
}

describe("ManualPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        signIn("admin");
    });

    afterEach(() => {
        document.body.innerHTML = "";
        vi.restoreAllMocks();
    });

    it("shows one section per page and links every section from the table of contents", () => {
        const wrapper = mountManual("data-import");

        expect(wrapper.findAll("section").map((section) => section.attributes("id"))).toEqual(["data-import"]);
        expect(linkTargets(wrapper, 'nav[aria-label="操作マニュアルの目次"]')).toEqual(MANUAL_SECTIONS.map((section) => section.id));
        expect(wrapper.get('[data-toc-section="data-import"]').attributes("aria-current")).toBe("page");
    });

    it("shows the introduction on the manual top and for an unknown section", () => {
        expect(mountManual().find("section").attributes("id")).toBe("introduction");
        expect(mountManual("no-such-section").find("section").attributes("id")).toBe("introduction");
    });

    it("groups the table of contents into chapters and marks restricted screens", () => {
        const wrapper = mountManual("user-management");

        expect(wrapper.findAll("[data-toc-chapter]").map((chapter) => chapter.text())).toEqual(["取込ファイルの準備", "毎日の作業", "結果を確認する", "設定"]);
        expect(wrapper.get("[data-section-chapter]").text()).toBe("設定");
        expect(wrapper.get("[data-section-audience]").text()).toBe("特権管理者のみ");
        expect(mountManual("introduction").find("[data-section-audience]").exists()).toBe(false);
    });

    it.each(MANUAL_SECTIONS.map((section) => [section.id, section] as const))("keeps the %s subheadings in sync with the section data", (id, section) => {
        const wrapper = mountManual(id);

        const headings = wrapper.findAll("[data-manual-heading]").map((heading) => ({ id: heading.attributes("id"), title: heading.text() }));
        expect(headings).toEqual(section.headings);
    });

    it("lists the subheadings of the current section and jumps to them", async () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        const wrapper = mountManual("survey-result");

        const outline = wrapper.get("[data-page-outline]");
        expect(outline.findAll("a").map((link) => link.text())).toContain("在庫表の見方");
        await outline
            .findAll("a")
            .find((link) => link.text() === "在庫表の見方")!
            .trigger("click");

        expect(scrollIntoView).toHaveBeenCalled();
        expect(window.location.hash).toBe("#stock-table");
        expect(outline.get('[aria-current="location"]').text()).toBe("在庫表の見方");
        expect(wrapper.findAll("[data-toc-headings] a").map((link) => link.text())).toContain("在庫表の見方");
    });

    it("links to the previous and next sections and to related sections", () => {
        const wrapper = mountManual("data-import");

        expect(linkTargets(wrapper, 'nav[aria-label="前後の節"]')).toEqual(["basic-operation-free", "basic-operation-amazon"]);
        expect(linkTargets(wrapper, "[data-related-links]")).toEqual(["basic-operation-amazon", "survey-result", "notes"]);
        expect(adjacentManualSections("introduction").previous).toBeNull();
        expect(adjacentManualSections("notes").next).toBeNull();
    });

    it("opens the table of contents from the current-section bar on small screens", async () => {
        const wrapper = mountManual("basic-operation-amazon");
        const bar = wrapper.get("[data-mobile-toc] button");

        expect(bar.text()).toContain("毎日の作業");
        expect(bar.text()).toContain("Amazon在庫の取得");
        await bar.trigger("click");
        expect(wrapper.find("#manual-mobile-toc").exists()).toBe(true);
        await wrapper.get('#manual-mobile-toc [data-toc-section="notes"]').trigger("click");
        expect(wrapper.find("#manual-mobile-toc").exists()).toBe(false);
    });

    it("keeps every callout and the screenshots for the import instructions", () => {
        const callouts = MANUAL_SECTIONS.reduce((count, section) => count + mountManual(section.id).findAllComponents(ManualCallout).length, 0);

        expect(callouts).toBe(15);
        expect(mountManual("basic-operation-boss").getComponent(ManualScreenshot).get("img").attributes("src")).toBe("/img/manual/02-1.png");
        expect(mountManual("basic-operation-free").get('[role="alert"]').text()).toContain("ゼロ在庫出力のチェックを外さない");
    });

    it("introduces the daily workflow and what each role can do", () => {
        const introduction = mountManual("introduction").get("#introduction");

        expect(introduction.text()).toContain("1日の作業の流れ");
        expect(introduction.text()).toContain("CrossWalker");
        const rows = introduction.findAll("[data-role-permissions] tbody tr");
        const importRow = rows.find((row) => row.text().startsWith("データ取込"))!;
        expect(
            importRow
                .findAll("td")
                .slice(1)
                .map((cell) => cell.text()),
        ).toEqual(["－", "○", "○"]);
    });

    it("explains the data import and the same-day update", () => {
        const dataImport = mountManual("data-import").get("#data-import");

        expect(dataImport.text()).toContain("取込・調査を実行");
        expect(dataImport.text()).toContain("1〜3件");
        expect(dataImport.find('[role="alert"]').text()).toContain("取込に失敗しました");
    });

    it("explains the Amazon inventory fetch and its statuses", () => {
        const amazon = mountManual("basic-operation-amazon").get("#basic-operation-amazon");

        expect(amazon.text()).toContain("SP-API");
        expect(amazon.findAll("[data-amazon-import-statuses] tbody td:first-child").map((cell) => cell.text())).toEqual(["受付", "処理中", "成功", "失敗"]);
        expect(amazon.findAll("[data-amazon-survey-statuses] tbody td:first-child").map((cell) => cell.text())).toEqual(["取込済み", "取込待ち", "一部未取得"]);
    });

    it("explains the survey history views and who can delete", () => {
        const history = mountManual("survey-history").get("#survey-history");

        expect(history.text()).toContain("一括ダウンロード");
        expect(history.findAll("[data-calendar-marks] tbody td:first-child").map((cell) => cell.text())).toContain("全ゼロ n");
        expect(history.find('[role="alert"]').text()).toContain("調査の削除（特権管理者のみ）");
    });

    it("explains how to read the survey result and when rerun is unavailable", () => {
        const result = mountManual("survey-result").get("#survey-result");

        const terms = result.findAll("[data-stock-displays] tbody td:first-child").map((cell) => cell.text());
        expect(terms).toEqual(expect.arrayContaining(["0（色付き）", "—", "対象外"]));
        expect(result.text()).toContain("Ctrlキー");
        expect(result.find('[role="alert"]').text()).toContain("旧方式（5ファイル）");
    });

    it("explains the inventory trend views, summary and CSV export", () => {
        const trend = mountManual("inventory-trend").get("#inventory-trend");

        expect(trend.findAll("[data-inventory-scopes] tbody td:first-child").map((cell) => cell.text())).toEqual(["モール全体", "Amazon", "BOSS", "フリー", "ストック", "総数"]);
        expect(trend.text()).toContain("全品番の日次CSV");
        expect(trend.findAll("[data-trend-csv-columns] tbody td:first-child").map((cell) => cell.text())).toContain("ecStock");
    });

    it("explains the settings screens with their admin-only operations", () => {
        expect(mountManual("import-settings").text()).toContain("このグループを保存");
        expect(mountManual("import-settings").get('[role="alert"]').text()).toContain("グループの削除（特権管理者のみ）");
        expect(mountManual("file-management").text()).toContain("特権管理者だけが使えます");
        expect(mountManual("api-settings").get('[role="alert"]').text()).toContain("1回だけ表示");
        expect(mountManual("user-management").get('[role="alert"]').text()).toContain("特権管理者が1人だけ");
    });

    it("lists the points that are easy to get wrong in the notes", () => {
        const notes = mountManual("notes").get("#notes");

        expect(notes.text()).toContain("取込待ち（—）は在庫0ではありません");
        expect(notes.findAll("[data-integration-statuses] tbody td:first-child").map((cell) => cell.text())).toEqual(["Amazon SP-API", "CrossWalker", "保存ファイル"]);
    });

    it("starts each section with its chapter, title, audience and key points", () => {
        const wrapper = mountManual("data-import");

        expect(wrapper.get("h1").text()).toBe("データ取込");
        const points = wrapper.findAll("[data-section-points] li").map((point) => point.text());
        const expected = MANUAL_SECTIONS.find((section) => section.id === "data-import")!.points;
        expect(points).toHaveLength(expected.length);
        expected.forEach((point, index) => expect(points[index]).toContain(point));
        for (const section of MANUAL_SECTIONS) expect(section.points.length, section.id).toBeGreaterThanOrEqual(2);
    });

    it("shows button and menu names as labels and the outcome of an operation", () => {
        const wrapper = mountManual("data-import");

        expect(wrapper.findAll('[data-ui-label="menu"]').map((label) => label.text())).toContain("データ取込");
        expect(wrapper.get('[data-ui-label="primary"]').text()).toContain("取込・調査を実行");
        expect(wrapper.get("[data-manual-result]").text()).toContain("在庫調査結果画面が開きます");
        expect(wrapper.findAll("[data-import-errors] tbody td:first-child").map((cell) => cell.text())).toContain("〜のファイルが複数あります。");
        expect(wrapper.findAll("[data-manual-step]")).toHaveLength(3);
    });

    it("links to the explained screen only for users who can open it", () => {
        expect(mountManual("file-management").find("[data-open-screen]").exists()).toBe(true);

        signIn("editor");
        expect(mountManual("file-management").find("[data-open-screen]").exists()).toBe(false);
        const dataImport = mountManual("data-import").get("[data-open-screen]");
        expect(dataImport.text()).toContain("データ取込画面を開く");
        expect(dataImport.findComponent(RouterLinkStub).props("to")).toEqual({ name: "data-import" });

        signIn("viewer");
        expect(mountManual("data-import").find("[data-open-screen]").exists()).toBe(false);
        expect(mountManual("survey-history").find("[data-open-screen]").exists()).toBe(true);
        expect(mountManual("notes").find("[data-open-screen]").exists()).toBe(false);
    });

    it("no longer asks users to rename the downloaded files", () => {
        for (const section of MANUAL_SECTIONS) expect(mountManual(section.id).text(), section.id).not.toContain("リネーム");
    });
});
