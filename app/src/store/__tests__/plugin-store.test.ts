import { afterEach, describe, expect, it, vi } from "vitest";
import type { PluginDefinition } from "@/plugins/types";
import { loadFreshStore, persistedStoreState } from "./test-utils";

afterEach(() => {
  vi.unstubAllGlobals();
});

function plugin(
  id: string,
  storeSlice?: PluginDefinition["storeSlice"],
): PluginDefinition {
  return {
    id,
    name: id,
    description: `${id} plugin`,
    icon: (() => null) as PluginDefinition["icon"],
    component: {} as PluginDefinition["component"],
    requiredEndpoints: [],
    path: `/${id}`,
    storeSlice,
  };
}

describe("plugin store infrastructure", () => {
  it("initializes plugin slices from registered initial state", async () => {
    const { initializePluginSlices, useAppStore } = await loadFreshStore();

    initializePluginSlices([
      plugin("alpha", {
        initialState: { count: 0, mode: "idle" },
      }),
      plugin("ignored"),
    ]);

    expect(useAppStore.getState().pluginStates).toEqual({
      alpha: { count: 0, mode: "idle" },
    });
  });

  it("merges persisted values over initial state while restoring missing keys", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      initializePluginSlices,
      storage,
      useAppStore,
    } = await loadFreshStore();

    storage.setItem(
      getScopedStorageKey("session-1"),
      persistedStoreState({
        pluginStates: {
          alpha: { count: 5 },
        },
      }),
    );

    await initializeAppStoreContext("session-1");

    initializePluginSlices([
      plugin("alpha", {
        initialState: { count: 0, mode: "idle", transient: true },
      }),
    ]);

    expect(useAppStore.getState().pluginStates.alpha).toEqual({
      count: 5,
      mode: "idle",
      transient: true,
    });
  });

  it("registers plugin partializers during initialization", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      initializePluginSlices,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("session-1");

    initializePluginSlices([
      plugin("alpha", {
        initialState: { durable: "initial", transient: "initial" },
        partialize: (state: never) => ({
          durable: (state as { durable: string }).durable,
        }),
      }),
    ]);

    useAppStore.getState().setPluginState("alpha", {
      durable: "kept",
      transient: "dropped",
    });

    const persisted = JSON.parse(
      storage.getItem(getScopedStorageKey("session-1")) ?? "{}",
    );

    expect(persisted.state.pluginStates).toEqual({
      alpha: { durable: "kept" },
    });
  });

  it("reads and updates plugin slices outside React", async () => {
    const { getPluginState, initializePluginSlices, setPluginState } =
      await loadFreshStore();

    initializePluginSlices([
      plugin("alpha", {
        initialState: { count: 0, mode: "idle" },
      }),
    ]);

    setPluginState("alpha", { count: 1 });

    expect(getPluginState("alpha")).toEqual({ count: 1, mode: "idle" });
    expect(getPluginState("missing")).toBeUndefined();
  });
});
