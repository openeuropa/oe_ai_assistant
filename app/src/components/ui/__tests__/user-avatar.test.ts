import { describe, expect, it } from "vitest";
import { avatarColorClass } from "../user-avatar";

describe("avatarColorClass", () => {
  it("assigns distinct palette colors to the first participants", () => {
    const colors = [0, 1, 2, 3, 4].map(avatarColorClass);

    expect(new Set(colors).size).toBe(colors.length);
  });

  it("cycles through the palette past ten participants", () => {
    expect(avatarColorClass(10)).toBe(avatarColorClass(0));
  });

  it("falls back to gray for unknown participants", () => {
    expect(avatarColorClass(-1)).toBe("bg-gray-400");
  });
});
