import { describe, expect, it } from "vitest";
import { saveRequestVersion } from "../services/mock-drafting-service";

describe("saveRequestVersion", () => {
  it("reads the version out of the message the Save button sends", () => {
    expect(saveRequestVersion("Save draft 2.")).toBe("2");
  });

  it("reads a minor version out of the message the Save button sends", () => {
    expect(saveRequestVersion("Save draft 2.1.")).toBe("2.1");
  });

  it("ignores a prompt that asks for a draft", () => {
    expect(saveRequestVersion("Draft an article about trade")).toBeNull();
    expect(saveRequestVersion("Please revise the draft")).toBeNull();
  });
});
