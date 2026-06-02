import { afterEach, describe, expect, it, vi } from "vitest";
import { loadFreshStore } from "./test-utils";

const STORAGE_KEY = "ai-editorial-assistant";

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

  it("persists durable state and excludes transient sidebar state", async () => {
    const { storage, useAppStore } = await loadFreshStore();

    useAppStore.getState().setActivePlugin("notes");
    useAppStore.getState().setUserContext("user-2", "node-10");
    useAppStore.getState().setSidebarOpen(false);

    const persisted = JSON.parse(storage.getItem(STORAGE_KEY) ?? "{}");

    expect(persisted.state).toMatchObject({
      activePluginId: "notes",
      userId: "user-2",
      nodeId: "node-10",
      notifications: [],
      pluginStates: {},
    });
    expect(persisted.state).not.toHaveProperty("isSidebarOpen");
  });

  it("filters persisted plugin slices through registered partializers", async () => {
    const { registerPluginPartialize, storage, useAppStore } =
      await loadFreshStore();

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

    const persisted = JSON.parse(storage.getItem(STORAGE_KEY) ?? "{}");

    expect(persisted.state.pluginStates).toEqual({
      drafting: { threadId: "thread-1" },
      unregistered: { value: "persisted" },
    });
  });
});
