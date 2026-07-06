import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import { getSessionMessages } from "../session-messages";

// The transcript is scoped to the current editorial session.
describe("session messages api", () => {
  beforeEach(() => {
    setConfig({ userId: "u1", sessionId: "session-42" });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts the sessionId to get-messages and returns the transcript", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        messages: [
          { role: "user", content: "Hi" },
          { role: "assistant", content: "Hello" },
        ],
      }),
    });
    vi.stubGlobal("fetch", fetchMock);

    const messages = await getSessionMessages();

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/get-messages",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ sessionId: "session-42" }),
      }),
    );
    expect(messages).toEqual([
      { role: "user", content: "Hi" },
      { role: "assistant", content: "Hello" },
    ]);
  });
});
