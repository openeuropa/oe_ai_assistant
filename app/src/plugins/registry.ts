/**
 * Plugin registry.
 *
 * Static list of all plugins known at build time. The shell reads this
 * to build the sidebar, set up routes, and lazy-load each plugin's
 * root component. Availability is resolved at runtime from host config
 * (`enabledPlugins`) plus the dev-only plugin environment flag.
 *
 * To add a new plugin, create its directory under src/plugins/ and
 * append an entry here.
 */

import { PenLine, Radio, StickyNote } from "lucide-react";
import { lazy } from "react";
import { getConfig } from "@/config";
import { draftingSliceConfig } from "./drafting/store";
import { echoSliceConfig } from "./echo/store";
import { notesSliceConfig } from "./notes/store";
import type { PluginDefinition } from "./types";

/** Full plugin catalog known at build time. */
export const registeredPlugins: PluginDefinition[] = [
  {
    id: "drafting",
    name: "Drafting",
    description: "AI-powered content drafting assistant",
    icon: PenLine,
    component: lazy(() => import("./drafting/root")),
    requiredEndpoints: ["/api/plugins/drafting/chat"],
    path: "/drafting",
    storeSlice: draftingSliceConfig,
  },
  {
    id: "echo",
    name: "Echo",
    description: "SSE streaming test plugin",
    devOnly: true,
    icon: Radio,
    component: lazy(() => import("./echo/root")),
    requiredEndpoints: ["/api/plugins/echo/stream"],
    path: "/echo",
    storeSlice: echoSliceConfig,
  },
  {
    id: "notes",
    name: "Notes",
    description: "RPC-style CRUD test plugin for TanStack Query",
    devOnly: true,
    icon: StickyNote,
    component: lazy(() => import("./notes/root")),
    requiredEndpoints: ["/api/plugins/notes/list", "/api/plugins/notes/create"],
    path: "/notes",
    storeSlice: notesSliceConfig,
  },
];

/**
 * Runtime plugin availability.
 *
 * - Host config (`enabledPlugins`) can allowlist plugins by ID.
 * - Dev-only plugins are only exposed when the Vite dev flag is enabled.
 * - Unknown IDs in `enabledPlugins` are ignored safely.
 */
export function getActivePlugins(): PluginDefinition[] {
  const { enabledPlugins } = getConfig();
  const enabledIds = new Set(enabledPlugins);
  const devPluginsEnabled =
    import.meta.env.DEV && import.meta.env.VITE_DEV_PLUGINS === "true";

  return registeredPlugins.filter((plugin) => {
    if (plugin.devOnly && !devPluginsEnabled) {
      return false;
    }

    if (enabledIds.size === 0) {
      return true;
    }

    return enabledIds.has(plugin.id);
  });
}
