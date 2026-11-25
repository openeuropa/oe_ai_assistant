import { describe, expect, it } from "vitest";
import { isFormattedItem } from "../formatted-text";

// Drafted items carry no text format, so markup marks formatted text.
describe("isFormattedItem", () => {
  it("accepts a value containing an HTML tag", () => {
    expect(isFormattedItem({ value: "<p>Body</p>" })).toBe(true);
    expect(isFormattedItem({ value: "Line one<br>line two" })).toBe(true);
  });

  it("rejects plain text, including a lone angle bracket", () => {
    expect(isFormattedItem({ value: "Plain text" })).toBe(false);
    expect(isFormattedItem({ value: "3 < 5 and 7 > 6" })).toBe(false);
  });

  it("rejects items without a string value", () => {
    expect(isFormattedItem({ target_id: 12 })).toBe(false);
    expect(isFormattedItem({ value: 3 })).toBe(false);
    expect(isFormattedItem(null)).toBe(false);
    expect(isFormattedItem("<p>Body</p>")).toBe(false);
  });
});
