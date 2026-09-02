import { beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import type { DocumentUpload } from "../hooks/use-drafting-documents";
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

function uploadsState(): DocumentUpload[] {
  return reactState.values[1] as DocumentUpload[];
}

function isSavingState(): boolean {
  return reactState.values[2] as boolean;
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

  it("uploads files concurrently and appends server-returned documents", async () => {
    apiMocks.addDraftingDocument
      .mockResolvedValueOnce(uploadedDocuments[0])
      .mockResolvedValueOnce(uploadedDocuments[1]);
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    const upload = documents.uploadFiles(
      fileList([
        new File(["alpha"], "Uploaded A.txt", { type: "text/plain" }),
        new File(["bravo"], "Uploaded B.pdf", { type: "application/pdf" }),
      ]),
    );

    // Every file gets an uploading slot before any request settles.
    expect(uploadsState()).toHaveLength(2);
    expect(uploadsState().map((slot) => slot.status)).toEqual([
      "uploading",
      "uploading",
    ]);

    await upload;

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
    // Finished uploads release their slots.
    expect(uploadsState()).toEqual([]);
    expect(isSavingState()).toBe(false);
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
  });

  it("keeps failed uploads as dismissible error slots", async () => {
    apiMocks.addDraftingDocument
      .mockRejectedValueOnce(new Error("Drafting add-document error: 500"))
      .mockResolvedValueOnce(uploadedDocuments[1]);
    const { useDraftingDocuments } = await loadHook();
    const documents = useDraftingDocuments();

    await documents.uploadFiles(
      fileList([
        new File(["alpha"], "Uploaded A.txt", { type: "text/plain" }),
        new File(["bravo"], "Uploaded B.pdf", { type: "application/pdf" }),
      ]),
    );

    // The successful file lands in the list; the failed one stays as an
    // error slot carrying the endpoint message.
    expect(selectedState()).toEqual([initialDocument, uploadedDocuments[1]]);
    expect(uploadsState()).toHaveLength(1);
    const failedSlot = uploadsState()[0];
    expect(failedSlot).toMatchObject({
      title: "Uploaded A.txt",
      status: "error",
      error: "Drafting add-document error: 500",
    });

    documents.dismissUpload(failedSlot?.id ?? "");

    expect(uploadsState()).toEqual([]);
    expect(isSavingState()).toBe(false);
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
    // Removal failures surface in the confirmation dialog and never touch
    // the upload slots.
    expect(uploadsState()).toEqual([]);
  });
});
