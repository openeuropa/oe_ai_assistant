import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import { apiFetch, getCsrfToken, resetCsrfToken } from "../csrf-token";

// Every API request must carry the CMS session's CSRF token.
describe("csrf token", () => {
  beforeEach(() => {
    setConfig({ userId: "u1", sessionId: "session-42" });
    resetCsrfToken();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("fetches the token once and sends it with every request", async () => {
    const fetchMock = vi
      .fn()
      .mockImplementation(async (url: string) =>
        url === "/session/token"
          ? { ok: true, text: async () => "csrf-42\n" }
          : { ok: true },
      );
    vi.stubGlobal("fetch", fetchMock);

    await apiFetch("/api/plugins/drafting/reset", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    });
    await apiFetch("/api/plugins/drafting/reset", { method: "POST" });

    const tokenCalls = fetchMock.mock.calls.filter(
      ([url]) => url === "/session/token",
    );
    expect(tokenCalls).toHaveLength(1);
    expect(tokenCalls[0]?.[1]).toEqual({ credentials: "include" });
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/reset",
      expect.objectContaining({
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": "csrf-42",
        },
      }),
    );
  });

  it("retries the token fetch after a failure", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: false, status: 500 })
      .mockResolvedValueOnce({ ok: true, text: async () => "csrf-42" });
    vi.stubGlobal("fetch", fetchMock);

    await expect(getCsrfToken()).rejects.toThrow("CSRF token error: 500");
    await expect(getCsrfToken()).resolves.toBe("csrf-42");
  });
});
