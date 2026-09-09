import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { resetCsrfToken } from "@/api/csrf-token";
import { setConfig } from "@/config";
import {
  addDraftingDocument,
  listDraftingDocuments,
  removeDraftingDocument,
  resetDrafting,
  setDraftingTemplate,
} from "../api/drafting-api";

// Every request must be scoped to the current editorial session.
describe("drafting api", () => {
  beforeEach(() => {
    setConfig({ userId: "u1", sessionId: "session-42" });
    resetCsrfToken();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts the sessionId to reset", async () => {
    const fetchMock = vi.fn().mockImplementation(async (url: string) =>
      url === "/session/token"
        ? { ok: true, text: async () => "csrf-42" }
        : {
            ok: true,
            json: async () => ({ status: "ok" }),
          },
    );
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
    const fetchMock = vi.fn().mockImplementation(async (url: string) =>
      url === "/session/token"
        ? { ok: true, text: async () => "csrf-42" }
        : {
            ok: true,
            json: async () => ({ status: "ok" }),
          },
    );
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

  it("uploads a document as a raw body with query parameters", async () => {
    const fetchMock = vi.fn().mockImplementation(async (url: string) =>
      url === "/session/token"
        ? { ok: true, text: async () => "csrf-42" }
        : {
            ok: true,
            json: async () => ({
              document: {
                id: "12",
                title: "brief.pdf",
                meta: { type: "pdf", size: 12288 },
              },
            }),
          },
    );
    vi.stubGlobal("fetch", fetchMock);

    const file = new File(["content"], "brief.pdf", {
      type: "application/pdf",
    });
    const document = await addDraftingDocument(file);

    expect(document.id).toBe("12");
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/add-document?sessionId=session-42&category=context&filename=brief.pdf",
      expect.objectContaining({
        method: "POST",
        headers: {
          "Content-Type": "application/octet-stream",
          "X-CSRF-Token": "csrf-42",
        },
        credentials: "include",
        body: file,
      }),
    );
  });

  it("lists documents with the current sessionId", async () => {
    const fetchMock = vi.fn().mockImplementation(async (url: string) =>
      url === "/session/token"
        ? { ok: true, text: async () => "csrf-42" }
        : {
            ok: true,
            json: async () => ({
              documents: [
                {
                  id: "12",
                  title: "brief.pdf",
                  meta: { type: "pdf", size: 12288 },
                },
              ],
            }),
          },
    );
    vi.stubGlobal("fetch", fetchMock);

    const documents = await listDraftingDocuments();

    expect(documents).toHaveLength(1);
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/list-documents",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          sessionId: "session-42",
          category: "context",
        }),
      }),
    );
  });

  it("removes documents with the current sessionId", async () => {
    const fetchMock = vi.fn().mockImplementation(async (url: string) =>
      url === "/session/token"
        ? { ok: true, text: async () => "csrf-42" }
        : {
            ok: true,
            json: async () => ({ status: "ok" }),
          },
    );
    vi.stubGlobal("fetch", fetchMock);

    await removeDraftingDocument("12");

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/plugins/drafting/remove-document",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          sessionId: "session-42",
          category: "context",
          documentId: "12",
        }),
      }),
    );
  });

  it("throws when set-template is rejected", async () => {
    const fetchMock = vi
      .fn()
      .mockImplementation(async (url: string) =>
        url === "/session/token"
          ? { ok: true, text: async () => "csrf-42" }
          : { ok: false, status: 400 },
      );
    vi.stubGlobal("fetch", fetchMock);

    await expect(setDraftingTemplate({ template: "bogus" })).rejects.toThrow(
      "Drafting set-template error: 400",
    );
  });

  it.each([
    {
      label: "add-document",
      request: () =>
        addDraftingDocument(
          new File(["content"], "brief.pdf", { type: "application/pdf" }),
        ),
      message: "Drafting add-document error: 500",
    },
    {
      label: "list-documents",
      request: () => listDraftingDocuments(),
      message: "Drafting list-documents error: 500",
    },
    {
      label: "remove-document",
      request: () => removeDraftingDocument("12"),
      message: "Drafting remove-document error: 500",
    },
  ])("throws when $label fails", async ({ request, message }) => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockImplementation(async (url: string) =>
          url === "/session/token"
            ? { ok: true, text: async () => "csrf-42" }
            : { ok: false, status: 500 },
        ),
    );

    await expect(request()).rejects.toThrow(message);
  });
});
