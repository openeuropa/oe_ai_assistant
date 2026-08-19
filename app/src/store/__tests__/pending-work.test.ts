import { afterEach, describe, expect, it, vi } from "vitest";
import { loadFreshStore } from "./test-utils";

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("pending work state", () => {
  it("starts with no pending work reported", async () => {
    const { useAppStore } = await loadFreshStore();

    expect(useAppStore.getState().pendingWork).toEqual({});
  });

  it("tracks pending work flags per reporting source", async () => {
    const { useAppStore } = await loadFreshStore();

    useAppStore.getState().setPendingWork("drafting", true);
    useAppStore.getState().setPendingWork("notes", false);

    expect(useAppStore.getState().pendingWork).toEqual({
      drafting: true,
      notes: false,
    });

    useAppStore.getState().setPendingWork("drafting", false);

    expect(
      Object.values(useAppStore.getState().pendingWork).some(Boolean),
    ).toBe(false);
  });

  it("excludes pending work from persisted state", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("session-20");
    useAppStore.getState().setPendingWork("drafting", true);
    // Trigger a persist write via a durable state change.
    useAppStore.getState().setActivePlugin("drafting");

    const persisted = JSON.parse(
      storage.getItem(getScopedStorageKey("session-20")) ?? "{}",
    );

    expect(persisted.state).toMatchObject({ activePluginId: "drafting" });
    expect(persisted.state).not.toHaveProperty("pendingWork");
  });
});
