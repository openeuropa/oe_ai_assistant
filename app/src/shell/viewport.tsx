/**
 * Plugin viewport.
 *
 * Renders the currently active plugin inside a Suspense boundary.
 * Each plugin is lazy-loaded as a separate JS chunk, so only the
 * active plugin's code is fetched. Also handles empty-state (no
 * active plugins) and 404 (unknown route).
 */

import { Suspense } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import { getActivePlugins } from "@/plugins/registry";

/** Shown while a plugin chunk is being loaded. */
function PluginFallback() {
  return (
    <div className="flex items-center justify-center p-8 text-gray-500">
      Loading plugin...
    </div>
  );
}

/** Shown when the hash route does not match any registered plugin. */
function NotFound() {
  return (
    <div className="flex flex-col items-center justify-center gap-2 p-8 text-gray-500">
      <p className="text-lg font-medium">Plugin not found</p>
      <p className="text-sm">The requested plugin does not exist.</p>
    </div>
  );
}

/** Shown when no plugins are active in the current environment/config. */
function NoPlugins() {
  return (
    <div className="flex flex-col items-center justify-center gap-2 p-8 text-gray-500">
      <p className="text-lg font-medium">No plugins available</p>
      <p className="text-sm">
        Check the current environment and enabled plugin configuration.
      </p>
    </div>
  );
}

export function Viewport() {
  const plugins = getActivePlugins();

  if (plugins.length === 0) {
    return <NoPlugins />;
  }

  // Redirect bare "/" to the first plugin's route.
  const defaultPath = plugins[0]?.path ?? "/";

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <Suspense fallback={<PluginFallback />}>
        <Routes>
          {plugins.map((plugin) => (
            <Route
              key={plugin.id}
              path={plugin.path}
              element={
                <div className="flex min-h-0 flex-1 flex-col overflow-auto">
                  <plugin.component />
                </div>
              }
            />
          ))}
          <Route path="/" element={<Navigate to={defaultPath} replace />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Suspense>
    </div>
  );
}
