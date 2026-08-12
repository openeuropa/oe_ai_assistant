import { afterEach, describe, expect, it, vi } from "vitest";
import { loadFreshStore } from "@/store/__tests__/test-utils";

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("drafting plugin state", () => {
  it("defines the expected initial slice and persists nothing", async () => {
    await loadFreshStore();
    const { draftingSliceConfig } = await import("../store");

    expect(draftingSliceConfig.initialState).toEqual({
      plan: [],
      draftedFields: {},
      selections: {},
      timelineVersion: 0,
    });

    // The conversation lives on the backend; nothing is persisted locally.
    // timelineVersion is also excluded: it resets to 0 on every page load.
    expect(
      draftingSliceConfig.partialize?.({
        plan: [],
        draftedFields: {
          title: { label: "Title", value: "Draft", type: "string" },
        },
        selections: { tone: "clear-professional" },
        timelineVersion: 3,
      }),
    ).toEqual({});
  });

  it("falls back to initial state before the plugin slice is initialized", async () => {
    await loadFreshStore();
    const { getDraftingState } = await import("../store");

    expect(getDraftingState()).toEqual({
      plan: [],
      draftedFields: {},
      selections: {},
      timelineVersion: 0,
    });
  });

  it("updates the drafting slice through the typed setter", async () => {
    await loadFreshStore();
    const { getDraftingState, setDraftingState } = await import("../store");

    setDraftingState({
      plan: [{ stepId: "s1", label: "Step 1", status: "done" }],
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      selections: { tone: "clear-professional" },
    });

    expect(getDraftingState()).toMatchObject({
      plan: [{ stepId: "s1", label: "Step 1", status: "done" }],
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      selections: { tone: "clear-professional" },
    });
  });
});
