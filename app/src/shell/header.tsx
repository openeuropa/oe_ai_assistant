/**
 * Plugin header.
 *
 * Displays the name of the currently active plugin at the top of
 * the main content area. Determines which plugin is active by
 * matching the current hash route against the active plugin list.
 * Returns null if no plugin matches (e.g. on 404 routes).
 */

import { useLocation } from "react-router-dom";
import { getActivePlugins } from "@/plugins/registry";

export function Header() {
  const location = useLocation();
  const plugins = getActivePlugins();

  // Find the plugin whose path matches the current route.
  const activePlugin = plugins.find((p) =>
    location.pathname.startsWith(p.path),
  );

  if (!activePlugin) {
    return null;
  }

  return (
    <header className="border-b border-gray-200 px-6 py-4">
      <h1 className="text-lg font-semibold text-gray-900">
        {activePlugin.name}
      </h1>
    </header>
  );
}
