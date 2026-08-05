import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import { resetDrafting, setDraftingTemplate } from "../api/drafting-api";

// Every request must be scoped to the current editorial session.
describe("drafting api", () => {
  beforeEach(() => {
    setConfig({ userId: "u1", sessionId: "session-42" });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts the sessionId to reset", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ status: "ok" }),
    });
    vi.stubGlobal("fetch", fetchMock);

    await resetDrafting();

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/reset",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ sessionId: "session-42" }),
      }),
    );
  });

  it("posts the template and sessionId to set-template", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ status: "ok" }),
    });
    vi.stubGlobal("fetch", fetchMock);

    await setDraftingTemplate({ template: "news_default" });

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/set-template",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          template: "news_default",
          sessionId: "session-42",
        }),
      }),
    );
  });

  it("throws when set-template is rejected", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 400 });
    vi.stubGlobal("fetch", fetchMock);

    await expect(setDraftingTemplate({ template: "bogus" })).rejects.toThrow(
      "Drafting set-template error: 400",
    );
  });
});
