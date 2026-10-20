import { describe, expect, it } from "vitest";
import { normalizeForSearch, searchManual } from "@/utils/manualSearch";

function keys(query: string): string[] {
    return searchManual(query, "admin").map((result) => result.key);
}

describe("searchManual", () => {
    it("ignores width, case, kana and spaces when comparing", () => {
        expect(normalizeForSearch("ＡＰＩ キー")).toBe(normalizeForSearch("apiきー"));
        expect(keys("apiきー")[0]).toBe("api-settings");
        expect(keys("ふりー在庫")).toContain("basic-operation-free");
    });

    it("finds subheadings and callouts, not only sections", () => {
        expect(keys("再照合")).toContain("survey-result#rerun");
        expect(keys("保存期限")).toContain("notes#file-retention");
        expect(searchManual("再照合", "admin").find((result) => result.key === "survey-result#rerun")!.path).toBe("結果を確認する › 在庫調査結果");
    });

    it("requires every word and ranks titles above keywords and points", () => {
        expect(keys("取込 失敗")[0]).toBe("data-import#import-errors");
        expect(keys("取込 失敗")).not.toContain("inventory-trend");
        expect(keys("取込待ち").slice(0, 2)).toEqual(expect.arrayContaining(["notes#pending-vs-zero"]));
    });

    it("shows the matching point as a snippet", () => {
        const result = searchManual("名前を変えずに", "admin").find((item) => item.key === "basic-operation-ec")!;

        expect(result.snippet).toContain("名前を変えずに");
    });

    it("returns nothing for an empty query or an unknown word", () => {
        expect(searchManual("   ", "admin")).toEqual([]);
        expect(searchManual("存在しないことば", "admin")).toEqual([]);
        expect(searchManual("在庫", "admin").length).toBeLessThanOrEqual(8);
    });

    it("leaves out sections the role cannot view", () => {
        expect(keys("APIキー")).toEqual(expect.arrayContaining(["api-settings"]));
        expect(searchManual("APIキー", "editor").map((result) => result.sectionId)).not.toContain("api-settings");
        expect(searchManual("取込に失敗", "viewer")).toEqual([]);
        expect(searchManual("取込待ち", "viewer").map((result) => result.sectionId)).not.toContain("data-import");
        expect(searchManual("取込待ち", "viewer").length).toBeGreaterThan(0);
    });
});
