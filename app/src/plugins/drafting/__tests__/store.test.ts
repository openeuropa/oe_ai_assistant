import { afterEach, describe, expect, it, vi } from "vitest";
import { loadFreshStore } from "@/store/__tests__/test-utils";

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("drafting plugin state", () => {
  it("defines the expected initial slice and persisted subset", async () => {
    await loadFreshStore();
    const { draftingSliceConfig } = await import("../store");

    expect(draftingSliceConfig.initialState).toEqual({
      threadId: null,
      draftedFields: {},
      rejectedFields: new Set(),
      hasPrompted: false,
      isDrafting: false,
      streamingFieldName: null,
      updatedFields: new Set(),
    });

    expect(
      draftingSliceConfig.partialize?.({
        threadId: "thread-1",
        draftedFields: {
          title: { label: "Title", value: "Draft", type: "string" },
        },
        rejectedFields: new Set(["title"]),
        hasPrompted: true,
        isDrafting: true,
        streamingFieldName: "title",
        updatedFields: new Set(["title"]),
      }),
    ).toEqual({ threadId: "thread-1" });
  });

  it("falls back to initial state before the plugin slice is initialized", async () => {
    await loadFreshStore();
    const { getDraftingState } = await import("../store");

    expect(getDraftingState()).toEqual({
      threadId: null,
      draftedFields: {},
      rejectedFields: new Set(),
      hasPrompted: false,
      isDrafting: false,
      streamingFieldName: null,
      updatedFields: new Set(),
    });
  });

  it("updates the drafting slice through the typed setter", async () => {
    await loadFreshStore();
    const { getDraftingState, setDraftingState } = await import("../store");

    setDraftingState({
      threadId: "thread-1",
      hasPrompted: true,
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
    });

    expect(getDraftingState()).toMatchObject({
      threadId: "thread-1",
      hasPrompted: true,
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
    });
  });

  it("toggles rejected fields without mutating the previous set", async () => {
    await loadFreshStore();
    const { getDraftingState, setDraftingState, toggleFieldRejected } =
      await import("../store");

    setDraftingState({ rejectedFields: new Set(["summary"]) });
    const previousSet = getDraftingState().rejectedFields;

    toggleFieldRejected("title");

    expect(previousSet).toEqual(new Set(["summary"]));
    expect(getDraftingState().rejectedFields).toEqual(
      new Set(["summary", "title"]),
    );

    toggleFieldRejected("summary");

    expect(getDraftingState().rejectedFields).toEqual(new Set(["title"]));
  });
});
