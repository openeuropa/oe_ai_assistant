import { afterEach, describe, expect, it, vi } from "vitest";
import { loadFreshStore, persistedStoreState } from "./test-utils";

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("global app store", () => {
  it("initializes shared app state with durable and transient defaults", async () => {
    const { useAppStore } = await loadFreshStore();

    expect(useAppStore.getState()).toMatchObject({
      activePluginId: null,
      userId: null,
      nodeId: null,
      notifications: [],
      pluginStates: {},
      isSidebarOpen: true,
    });
  });

  it("updates active plugin, user context, notifications, and sidebar state", async () => {
    const { useAppStore } = await loadFreshStore();
    const notification = {
      id: "n-1",
      type: "success" as const,
      message: "Draft saved",
    };

    useAppStore.getState().setActivePlugin("drafting");
    useAppStore.getState().setUserContext("user-1", "node-9");
    useAppStore.getState().addNotification(notification);
    useAppStore.getState().setSidebarOpen(false);

    expect(useAppStore.getState()).toMatchObject({
      activePluginId: "drafting",
      userId: "user-1",
      nodeId: "node-9",
      notifications: [notification],
      isSidebarOpen: false,
    });

    useAppStore.getState().removeNotification("n-1");
    expect(useAppStore.getState().notifications).toEqual([]);

    useAppStore.getState().addNotification(notification);
    useAppStore.getState().clearNotifications();
    expect(useAppStore.getState().notifications).toEqual([]);
  });

  it("shallow-merges plugin state by plugin id", async () => {
    const { useAppStore } = await loadFreshStore();

    useAppStore
      .getState()
      .setPluginState("example", { persisted: true, counter: 1 });
    useAppStore.getState().setPluginState("example", { counter: 2 });
    useAppStore.getState().setPluginState("other", { enabled: true });

    expect(useAppStore.getState().pluginStates).toEqual({
      example: { persisted: true, counter: 2 },
      other: { enabled: true },
    });
  });

  it("persists durable state and excludes transient sidebar and context state", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("user-2", "node-10");
    useAppStore.getState().setActivePlugin("notes");
    useAppStore.getState().setSidebarOpen(false);

    const persisted = JSON.parse(
      storage.getItem(getScopedStorageKey("user-2", "node-10")) ?? "{}",
    );

    expect(persisted.state).toMatchObject({
      activePluginId: "notes",
      notifications: [],
      pluginStates: {},
    });
    expect(persisted.state).not.toHaveProperty("isSidebarOpen");
    expect(persisted.state).not.toHaveProperty("userId");
    expect(persisted.state).not.toHaveProperty("nodeId");
  });

  it("filters persisted plugin slices through registered partializers", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      registerPluginPartialize,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("user-1", "node-1");
    registerPluginPartialize("drafting", (state) => ({
      threadId: state.threadId,
    }));
    registerPluginPartialize("notes", () => ({}));

    useAppStore.getState().setPluginState("drafting", {
      threadId: "thread-1",
      draftedFields: { title: { value: "Draft" } },
    });
    useAppStore.getState().setPluginState("notes", {
      view: { mode: "edit", noteId: "note-1" },
    });
    useAppStore.getState().setPluginState("unregistered", {
      value: "persisted",
    });

    const persisted = JSON.parse(
      storage.getItem(getScopedStorageKey("user-1", "node-1")) ?? "{}",
    );

    expect(persisted.state.pluginStates).toEqual({
      drafting: { threadId: "thread-1" },
      unregistered: { value: "persisted" },
    });
  });
});

describe("app store persistence scoping", () => {
  it("rehydrates persisted state for the active user/node scope", async () => {
    const storageKey = "ai-editorial-assistant:user:editor-7:node:123";
    const { initializeAppStoreContext, useAppStore } = await loadFreshStore({
      [storageKey]: persistedStoreState({
        activePluginId: "drafting",
        notifications: [
          {
            id: "n-1",
            type: "info",
            message: "Resume drafting",
          },
        ],
        pluginStates: {
          drafting: {
            threadId: "thread-123",
          },
        },
      }),
    });

    await initializeAppStoreContext("editor-7", "123");

    expect(useAppStore.getState()).toMatchObject({
      activePluginId: "drafting",
      userId: "editor-7",
      nodeId: "123",
      notifications: [
        {
          id: "n-1",
          type: "info",
          message: "Resume drafting",
        },
      ],
      pluginStates: {
        drafting: {
          threadId: "thread-123",
        },
      },
    });
  });

  it("does not reuse persisted state from another user on the same node", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    storage.setItem(
      getScopedStorageKey("editor-7", "123"),
      persistedStoreState({
        activePluginId: "drafting",
        notifications: [],
        pluginStates: {
          drafting: {
            threadId: "thread-123",
          },
        },
      }),
    );

    await initializeAppStoreContext("editor-7", "123");
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "thread-123",
      },
    });

    await initializeAppStoreContext("editor-8", "123");

    expect(useAppStore.getState()).toMatchObject({
      activePluginId: null,
      userId: "editor-8",
      nodeId: "123",
      notifications: [],
      pluginStates: {},
    });
  });

  it("uses a dedicated create-flow scope when nodeId is absent", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    storage.setItem(
      getScopedStorageKey("editor-7", null),
      persistedStoreState({
        activePluginId: "drafting",
        notifications: [],
        pluginStates: {
          drafting: {
            threadId: "create-thread",
          },
        },
      }),
    );

    storage.setItem(
      getScopedStorageKey("editor-7", "987"),
      persistedStoreState({
        activePluginId: "drafting",
        notifications: [],
        pluginStates: {
          drafting: {
            threadId: "saved-thread",
          },
        },
      }),
    );

    await initializeAppStoreContext("editor-7", null);
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "create-thread",
      },
    });

    await initializeAppStoreContext("editor-7", "987");
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "saved-thread",
      },
    });
  });
});
