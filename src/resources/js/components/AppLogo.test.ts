import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import AppLogo from "@/components/AppLogo.vue";

describe("AppLogo", () => {
    it("draws three shelves with only the middle one in the signal color", () => {
        const wrapper = mount(AppLogo, { props: { size: 40 } });
        const shelves = wrapper.findAll("rect").slice(2);

        expect(wrapper.attributes("width")).toBe("40");
        expect(shelves.map((shelf) => shelf.attributes("fill"))).toEqual(["#e8ebee", "#f5c400", "#e8ebee"]);
    });

    it("lightens the tile on a dark background", () => {
        expect(mount(AppLogo).get("rect").attributes("fill")).toBe("#11161a");
        expect(
            mount(AppLogo, { props: { dark: true } })
                .get("rect")
                .attributes("fill"),
        ).toBe("#262c32");
    });
});
