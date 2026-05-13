import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setConfig } from "@/config";
import { getActivePlugins, registeredPlugins } from "../registry";

describe("plugin registry", () => {
  beforeEach(() => {
    vi.stubGlobal("__DEV_PLUGINS__", false);
    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("registers plugins with unique ids and paths", () => {
    const ids = registeredPlugins.map((plugin) => plugin.id);
    const paths = registeredPlugins.map((plugin) => plugin.path);

    expect(new Set(ids).size).toBe(ids.length);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it("keeps every registered path rooted at its plugin id", () => {
    expect(
      registeredPlugins.map((plugin) => ({ id: plugin.id, path: plugin.path })),
    ).toEqual([
      { id: "drafting", path: "/drafting" },
      { id: "echo", path: "/echo" },
      { id: "notes", path: "/notes" },
    ]);
  });

  it("declares required metadata and store slices for shell initialization", () => {
    for (const plugin of registeredPlugins) {
      expect(plugin.name).not.toEqual("");
      expect(plugin.description).not.toEqual("");
      expect(plugin.requiredEndpoints.length).toBeGreaterThan(0);
      expect(plugin.storeSlice).toBeDefined();
      expect(plugin.storeSlice?.initialState).toBeDefined();
    }
  });

  it("uses drafting as the first plugin for the default route", () => {
    expect(registeredPlugins[0]?.id).toBe("drafting");
    expect(registeredPlugins[0]?.path).toBe("/drafting");
  });

  it("exposes only non-dev plugins by default", () => {
    expect(getActivePlugins().map((plugin) => plugin.id)).toEqual([
      "drafting",
    ]);
  });

  it("keeps dev-only plugins hidden from the host allowlist unless the dev flag is enabled", () => {
    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
      enabledPlugins: ["drafting", "echo", "missing"],
    });

    expect(getActivePlugins().map((plugin) => plugin.id)).toEqual([
      "drafting",
    ]);
  });

  it("exposes dev-only plugins when explicitly enabled in dev", () => {
    vi.stubGlobal("__DEV_PLUGINS__", true);

    setConfig({
      userId: "editor-7",
      sessionId: "session-1",
      enabledPlugins: ["echo"],
    });

    expect(getActivePlugins().map((plugin) => plugin.id)).toEqual(["echo"]);
  });
});
