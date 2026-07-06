import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import { resetDrafting } from "../api/drafting-api";

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
});
