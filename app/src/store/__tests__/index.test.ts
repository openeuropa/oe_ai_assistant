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
      notifications: [],
      pluginStates: {},
      // Collapsed by default: every session opens with the sidebar closed.
      isSidebarOpen: false,
    });
  });

  it("updates active plugin, notifications, and sidebar state", async () => {
    const { useAppStore } = await loadFreshStore();
    const notification = {
      id: "n-1",
      type: "success" as const,
      message: "Draft saved",
    };

    useAppStore.getState().setActivePlugin("drafting");
    useAppStore.getState().addNotification(notification);
    useAppStore.getState().setSidebarOpen(false);

    expect(useAppStore.getState()).toMatchObject({
      activePluginId: "drafting",
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

  it("persists durable state and excludes transient sidebar state", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("session-10");
    useAppStore.getState().setActivePlugin("notes");
    useAppStore.getState().setSidebarOpen(false);

    const persisted = JSON.parse(
      storage.getItem(getScopedStorageKey("session-10")) ?? "{}",
    );

    expect(persisted.state).toMatchObject({
      activePluginId: "notes",
      notifications: [],
      pluginStates: {},
    });
    expect(persisted.state).not.toHaveProperty("isSidebarOpen");
  });

  it("filters persisted plugin slices through registered partializers", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      registerPluginPartialize,
      storage,
      useAppStore,
    } = await loadFreshStore();

    await initializeAppStoreContext("session-1");
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
      storage.getItem(getScopedStorageKey("session-1")) ?? "{}",
    );

    expect(persisted.state.pluginStates).toEqual({
      drafting: { threadId: "thread-1" },
      unregistered: { value: "persisted" },
    });
  });
});

describe("app store persistence scoping", () => {
  it("rehydrates persisted state for the active session scope", async () => {
    const storageKey = "ai-editorial-assistant:session:42";
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

    await initializeAppStoreContext("42");

    expect(useAppStore.getState()).toMatchObject({
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
    });
  });

  it("shares persisted state when the same session is reopened", async () => {
    // Sessions are collaborative: the scope is keyed by session only,
    // so any user (or browser tab) opening the same session resumes
    // the same persisted state.
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    storage.setItem(
      getScopedStorageKey("42"),
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

    await initializeAppStoreContext("42");
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "thread-123",
      },
    });

    await initializeAppStoreContext("42");

    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "thread-123",
      },
    });
  });

  it("does not reuse persisted state from another session", async () => {
    const {
      getScopedStorageKey,
      initializeAppStoreContext,
      storage,
      useAppStore,
    } = await loadFreshStore();

    storage.setItem(
      getScopedStorageKey("42"),
      persistedStoreState({
        activePluginId: "drafting",
        notifications: [],
        pluginStates: {
          drafting: {
            threadId: "news-thread",
          },
        },
      }),
    );

    storage.setItem(
      getScopedStorageKey("43"),
      persistedStoreState({
        activePluginId: "drafting",
        notifications: [],
        pluginStates: {
          drafting: {
            threadId: "landing-page-thread",
          },
        },
      }),
    );

    await initializeAppStoreContext("42");
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "news-thread",
      },
    });

    await initializeAppStoreContext("43");
    expect(useAppStore.getState().pluginStates).toEqual({
      drafting: {
        threadId: "landing-page-thread",
      },
    });
  });
});
