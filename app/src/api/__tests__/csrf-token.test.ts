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

  // A login elsewhere in the browser replaces the session and its CSRF
  // seed while the cookie this tab sends stays valid: the CMS answers 403
  // to the cached token, so the request must be retried with a new one.
  it("refreshes the token and retries once after a 403", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: true, text: async () => "csrf-old" })
      .mockResolvedValueOnce({ ok: false, status: 403 })
      .mockResolvedValueOnce({ ok: true, text: async () => "csrf-new" })
      .mockResolvedValueOnce({ ok: true, status: 200 });
    vi.stubGlobal("fetch", fetchMock);

    const response = await apiFetch("/api/plugins/drafting/set-tone", {
      method: "POST",
      body: "{}",
    });

    // The caller sees the successful retry, not the 403.
    expect(response.status).toBe(200);
    // Token, request, token again, request again: the retry carries the
    // token fetched after the 403, not the cached one.
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      "/session/token",
      "/api/plugins/drafting/set-tone",
      "/session/token",
      "/api/plugins/drafting/set-tone",
    ]);
    expect(fetchMock.mock.calls[1]?.[1]).toEqual(
      expect.objectContaining({ headers: { "X-CSRF-Token": "csrf-old" } }),
    );
    expect(fetchMock.mock.calls[3]?.[1]).toEqual(
      expect.objectContaining({ headers: { "X-CSRF-Token": "csrf-new" } }),
    );
  });

  // A permission denial is also a 403. It must reach the caller after a
  // single retry rather than loop on token refreshes.
  it("returns a 403 that survives the retry", async () => {
    const fetchMock = vi
      .fn()
      .mockImplementation(async (url: string) =>
        url === "/session/token"
          ? { ok: true, text: async () => "csrf-42" }
          : { ok: false, status: 403 },
      );
    vi.stubGlobal("fetch", fetchMock);

    const response = await apiFetch("/api/plugins/drafting/set-tone", {
      method: "POST",
    });

    expect(response.status).toBe(403);
    // Two token fetches and two requests, then the caller gets the answer.
    expect(fetchMock).toHaveBeenCalledTimes(4);
  });

  // A transport failure must not poison the cache, or every later call
  // would fail until the page is reloaded.
  it("retries the token fetch after a network failure", async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce({ ok: true, text: async () => "csrf-42" });
    vi.stubGlobal("fetch", fetchMock);

    await expect(getCsrfToken()).rejects.toThrow("Failed to fetch");
    await expect(getCsrfToken()).resolves.toBe("csrf-42");
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
