/**
 * Plugin type definitions.
 *
 * Every plugin must export a PluginDefinition that the shell uses to
 * register it in the sidebar, set up its route, and lazy-load its
 * root component.
 */

import type { ComponentType, LazyExoticComponent } from "react";
import type { PluginSliceConfigBase } from "@/store/plugin-slice-config";

/** Describes a plugin so the shell can register and render it. */
export interface PluginDefinition {
  /** Unique identifier (used as route key and store reference). */
  id: string;
  /** Human-readable name shown in the sidebar and header. */
  name: string;
  /** Short description for tooltips or documentation. */
  description: string;
  /** When true, the plugin is available only in explicitly enabled dev mode. */
  devOnly?: boolean;
  /** Icon component displayed next to the name in the sidebar. Accepts an optional `size` prop (Lucide-compatible). */
  icon: ComponentType<{ size?: number }>;
  /** Lazy-loaded root component rendered when this plugin is active. */
  component: LazyExoticComponent<ComponentType>;
  /** API endpoints this plugin depends on (for documentation and validation). */
  requiredEndpoints: string[];
  /** Hash route path (e.g. "/drafting"). */
  path: string;
  /**
   * Optional store slice configuration for plugin-owned state.
   * When provided, the plugin gets its own namespace in the global
   * Zustand store under `pluginStates[id]`. The shell initializes
   * the slice at startup using initializePluginSlices().
   */
  storeSlice?: PluginSliceConfigBase;
}
