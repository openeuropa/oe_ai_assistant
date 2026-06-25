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
      plan: [],
      draftedFields: {},
      generationSettings: null,
    });

    expect(
      draftingSliceConfig.partialize?.({
        threadId: "thread-1",
        plan: [],
        draftedFields: {
          title: { label: "Title", value: "Draft", type: "string" },
        },
        generationSettings: {
          toneId: "clear-professional",
        },
      }),
    ).toEqual({
      threadId: "thread-1",
      generationSettings: {
        toneId: "clear-professional",
      },
    });
  });

  it("falls back to initial state before the plugin slice is initialized", async () => {
    await loadFreshStore();
    const { getDraftingState } = await import("../store");

    expect(getDraftingState()).toEqual({
      threadId: null,
      plan: [],
      draftedFields: {},
      generationSettings: null,
    });
  });

  it("updates the drafting slice through the typed setter", async () => {
    await loadFreshStore();
    const { getDraftingState, setDraftingState } = await import("../store");

    setDraftingState({
      threadId: "thread-1",
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      generationSettings: {
        toneId: "clear-professional",
      },
    });

    expect(getDraftingState()).toMatchObject({
      threadId: "thread-1",
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      generationSettings: {
        toneId: "clear-professional",
      },
    });
  });

  it("only persists thread ID and confirmed settings via partialize", async () => {
    await loadFreshStore();
    const { draftingSliceConfig } = await import("../store");

    const full = {
      threadId: "thread-1",
      plan: [{ stepId: "s1", label: "Step 1", status: "done" as const }],
      draftedFields: { title: [{ value: "Test" }] },
      generationSettings: {
        toneId: "clear-professional",
      },
    };

    expect(draftingSliceConfig.partialize?.(full)).toEqual({
      threadId: "thread-1",
      generationSettings: {
        toneId: "clear-professional",
      },
    });
  });
});
