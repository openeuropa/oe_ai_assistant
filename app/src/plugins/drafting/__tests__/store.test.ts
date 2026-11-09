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
      runs: {},
      activeCallIds: [],
      draftedFields: {},
      liveDrafts: {},
      selections: {},
      isArtifactCollapsed: false,
      activeDraftVersion: null,
    });

    // The conversation lives on the backend; nothing is persisted locally.
    expect(
      draftingSliceConfig.partialize?.({
        runs: {},
        activeCallIds: [],
        draftedFields: {
          title: { label: "Title", value: "Draft", type: "string" },
        },
        liveDrafts: {
          1: {
            version: 1,
            context: null,
            groups: [],
            status: "drafted" as const,
            error: "",
            createdAt: null,
            fields: {},
          },
        },
        selections: { tone: "clear-professional" },
        isArtifactCollapsed: true,
        activeDraftVersion: 2,
      }),
    ).toEqual({});
  });

  it("falls back to initial state before the plugin slice is initialized", async () => {
    await loadFreshStore();
    const { getDraftingState } = await import("../store");

    expect(getDraftingState()).toEqual({
      runs: {},
      activeCallIds: [],
      draftedFields: {},
      liveDrafts: {},
      selections: {},
      isArtifactCollapsed: false,
      activeDraftVersion: null,
    });
  });

  it("updates the drafting slice through the typed setter", async () => {
    await loadFreshStore();
    const { getDraftingState, setDraftingState } = await import("../store");

    setDraftingState({
      runs: {
        call_1: {
          version: 1,
          status: "drafted" as const,
          groups: [
            {
              id: "main_fields",
              label: "Main fields",
              status: "drafted" as const,
            },
          ],
          context: null,
        },
      },
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      liveDrafts: {
        1: {
          version: 1,
          context: null,
          groups: [],
          status: "drafted" as const,
          error: "",
          createdAt: null,
          fields: { title: "Live" },
        },
      },
      selections: { tone: "clear-professional" },
    });

    expect(getDraftingState()).toEqual({
      runs: {
        call_1: {
          version: 1,
          status: "drafted" as const,
          groups: [
            {
              id: "main_fields",
              label: "Main fields",
              status: "drafted" as const,
            },
          ],
          context: null,
        },
      },
      draftedFields: {
        title: { label: "Title", value: "Draft", type: "string" },
      },
      liveDrafts: {
        1: {
          version: 1,
          context: null,
          groups: [],
          status: "drafted" as const,
          error: "",
          createdAt: null,
          fields: { title: "Live" },
        },
      },
      selections: { tone: "clear-professional" },
    });
  });
});
