/**
 * Plugin registry.
 *
 * Static list of all plugins known at build time. The shell reads this
 * to build the sidebar, set up routes, and lazy-load each plugin's
 * root component. To add a new plugin, create its directory under
 * src/plugins/ and append an entry here.
 */

import { Bot, PenLine, Radio, StickyNote } from "lucide-react";
import { lazy } from "react";
import { agentTestSliceConfig } from "./agent-test/store";
import { draftingSliceConfig } from "./drafting/store";
import { echoSliceConfig } from "./echo/store";
import { notesSliceConfig } from "./notes/store";
import type { PluginDefinition } from "./types";

export const plugins: PluginDefinition[] = [
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
    id: "agent-test",
    name: "Agent Test",
    description: "Sub-agent orchestration spike with chat and drafting",
    icon: Bot,
    component: lazy(() => import("./agent-test/root")),
    requiredEndpoints: ["/api/plugins/agent_test/chat"],
    path: "/agent-test",
    storeSlice: agentTestSliceConfig,
  },
  {
    id: "echo",
    name: "Echo",
    description: "SSE streaming test plugin",
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
    icon: StickyNote,
    component: lazy(() => import("./notes/root")),
    requiredEndpoints: ["/api/plugins/notes/list", "/api/plugins/notes/create"],
    path: "/notes",
    storeSlice: notesSliceConfig,
  },
];
