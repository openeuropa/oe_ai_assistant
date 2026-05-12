import { beforeEach, describe, expect, it } from "vitest";
import { getConfig, setConfig } from "../config";

describe("config", () => {
  beforeEach(() => {
    setConfig({
      userId: "editor-7",
    });
  });

  it("requires a non-empty userId", () => {
    expect(() =>
      setConfig({
        userId: "   ",
      }),
    ).toThrow("[ai-editorial-assistant] init() requires a non-empty userId");
  });

  it("trims the configured userId", () => {
    setConfig({
      userId: "  editor-12  ",
      nodeId: "123",
    });

    expect(getConfig()).toMatchObject({
      userId: "editor-12",
      nodeId: "123",
    });
  });
});
