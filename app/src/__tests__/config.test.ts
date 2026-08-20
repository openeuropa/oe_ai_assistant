import { beforeEach, describe, expect, it } from "vitest";
import { getConfig, setConfig } from "../config";

describe("config", () => {
  beforeEach(() => {
    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
    });
  });

  it("requires a non-empty userId", () => {
    expect(() =>
      setConfig({
        userId: "   ",
        sessionId: "session-1",
      }),
    ).toThrow("[ai-editorial-assistant] init() requires a non-empty userId");
  });

  it("requires a non-empty sessionId", () => {
    expect(() =>
      setConfig({
        userId: "editor-7",
        sessionId: "   ",
      }),
    ).toThrow("[ai-editorial-assistant] init() requires a non-empty sessionId");
  });

  it("defaults sessionTitle, exitUrl, and userName to empty strings", () => {
    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
    });

    expect(getConfig().sessionTitle).toBe("");
    expect(getConfig().exitUrl).toBe("");
    expect(getConfig().userName).toBe("");
  });

  it("passes sessionTitle and exitUrl through from the host config", () => {
    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
      sessionTitle: "March newsletter",
      exitUrl: "/admin/content/ai",
    });

    expect(getConfig().sessionTitle).toBe("March newsletter");
    expect(getConfig().exitUrl).toBe("/admin/content/ai");
  });

  it("trims the configured userId and sessionId", () => {
    setConfig({
      userId: "  editor-12  ",
      sessionId: "  session-9  ",
      nodeId: "123",
    });

    expect(getConfig()).toMatchObject({
      userId: "editor-12",
      sessionId: "session-9",
      nodeId: "123",
    });
  });
});
