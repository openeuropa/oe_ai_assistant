import { describe, expect, it } from "vitest";
import { plugins } from "../registry";

describe("plugin registry", () => {
  it("registers plugins with unique ids and paths", () => {
    const ids = plugins.map((plugin) => plugin.id);
    const paths = plugins.map((plugin) => plugin.path);

    expect(new Set(ids).size).toBe(ids.length);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it("keeps every registered path rooted at its plugin id", () => {
    expect(
      plugins.map((plugin) => ({ id: plugin.id, path: plugin.path })),
    ).toEqual([
      { id: "drafting", path: "/drafting" },
      { id: "agent-test", path: "/agent-test" },
      { id: "echo", path: "/echo" },
      { id: "notes", path: "/notes" },
    ]);
  });

  it("declares required metadata and store slices for shell initialization", () => {
    for (const plugin of plugins) {
      expect(plugin.name).not.toEqual("");
      expect(plugin.description).not.toEqual("");
      expect(plugin.requiredEndpoints.length).toBeGreaterThan(0);
      expect(plugin.storeSlice).toBeDefined();
      expect(plugin.storeSlice?.initialState).toBeDefined();
    }
  });

  it("uses drafting as the first plugin for the default route", () => {
    expect(plugins[0]?.id).toBe("drafting");
    expect(plugins[0]?.path).toBe("/drafting");
  });
});
