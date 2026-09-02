import { beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import type { DraftingDocument } from "../types";

const reactState = vi.hoisted(() => ({
  values: [] as unknown[],
}));

const apiMocks = vi.hoisted(() => ({
  addDraftingDocument: vi.fn(),
  removeDraftingDocument: vi.fn(),
}));

vi.mock("react", () => ({
  useState: vi.fn((initialValue: unknown) => {
    const index = reactState.values.length;
    reactState.values.push(
      typeof initialValue === "function"
        ? (initialValue as () => unknown)()
        : initialValue,
    );

    return [
      reactState.values[index],
      (nextValue: unknown) => {
        reactState.values[index] =
          typeof nextValue === "function"
            ? (nextValue as (current: unknown) => unknown)(
                reactState.values[index],
              )
            : nextValue;
      },
    ];
  }),
}));

vi.mock("../api/drafting-api", () => apiMocks);

const initialDocument: DraftingDocument = {
  id: "initial-document",
  title: "Initial brief.md",
  meta: { type: "md", size: 1 },
};

const uploadedDocuments: DraftingDocument[] = [
  {
    id: "uploaded-a",
    title: "Uploaded A.txt",
    meta: { type: "txt", size: 12 },
  },
  {
    id: "uploaded-b",
    title: "Uploaded B.pdf",
    meta: { type: "pdf", size: 24 },
  },
];

function fileList(files: File[]): FileList {
  return files as unknown as FileList;
}

async function loadHook() {
  return import("../hooks/use-drafting-documents");
}

function selectedState(): DraftingDocument[] {
  return reactState.values[0] as DraftingDocument[];
}

function isSavingState(): boolean {
  return reactState.values[1] as boolean;
}

function errorState(): string | null {
  return reactState.values[2] as string | null;
}

describe("useDraftingDocuments", () => {
  beforeEach(() => {
    reactState.values = [];
    apiMocks.addDraftingDocument.mockReset();
    apiMocks.removeDraftingDocument.mockReset();
    setConfig({
      userId: "editor",
      sessionId: "session-42",
      pluginConfig: {
        drafting: {
          documents: {
            enabled: true,
            options: [initialDocument],
          },
        },
      },
    });
  });

  it("uploads files and appends only server-returned documents in order", async () => {
    apiMocks.addDraftingDocument
      .mockResolvedValueOnce(uploadedDocuments[0])
      .mockResolvedValueOnce(uploadedDocuments[1]);
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    await documents.uploadFiles(
      fileList([
        new File(["alpha"], "Uploaded A.txt", { type: "text/plain" }),
        new File(["bravo"], "Uploaded B.pdf", { type: "application/pdf" }),
      ]),
    );

    expect(apiMocks.addDraftingDocument).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({ name: "Uploaded A.txt" }),
      "context",
    );
    expect(apiMocks.addDraftingDocument).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({ name: "Uploaded B.pdf" }),
      "context",
    );
    expect(selectedState()).toEqual([initialDocument, ...uploadedDocuments]);
    expect(isSavingState()).toBe(false);
    expect(errorState()).toBeNull();
  });

  it("waits for backend removal success before removing the document", async () => {
    let resolveRemoval!: () => void;
    apiMocks.removeDraftingDocument.mockReturnValue(
      new Promise<void>((resolve) => {
        resolveRemoval = resolve;
      }),
    );
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    const removal = documents.removeDocument(initialDocument.id);

    expect(apiMocks.removeDraftingDocument).toHaveBeenCalledWith(
      initialDocument.id,
    );
    expect(selectedState()).toEqual([initialDocument]);
    expect(isSavingState()).toBe(true);

    resolveRemoval();
    await removal;

    expect(selectedState()).toEqual([]);
    expect(isSavingState()).toBe(false);
    expect(errorState()).toBeNull();
  });

  it("exposes upload failures without mutating selected documents", async () => {
    apiMocks.addDraftingDocument.mockRejectedValue(
      new Error("Drafting add-document error: 500"),
    );
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    await expect(
      documents.uploadFiles(
        fileList([
          new File(["alpha"], "Uploaded A.txt", { type: "text/plain" }),
        ]),
      ),
    ).rejects.toThrow("Drafting add-document error: 500");

    expect(selectedState()).toEqual([initialDocument]);
    expect(isSavingState()).toBe(false);
    expect(errorState()).toBe("Drafting add-document error: 500");
  });

  it("exposes removal failures without mutating selected documents", async () => {
    apiMocks.removeDraftingDocument.mockRejectedValue(
      new Error("Drafting remove-document error: 500"),
    );
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    await expect(documents.removeDocument(initialDocument.id)).rejects.toThrow(
      "Drafting remove-document error: 500",
    );

    expect(selectedState()).toEqual([initialDocument]);
    expect(isSavingState()).toBe(false);
    // Removal failures surface in the confirmation dialog, not the panel
    // error banner, so the hook leaves the shared error state untouched.
    expect(errorState()).toBeNull();
  });
});
