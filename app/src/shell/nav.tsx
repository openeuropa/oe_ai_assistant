/**
 * Sidebar plugin navigation.
 *
 * Lists the active plugins as nav links. Supports two states:
 * - Expanded: shows icon + label, with a "Plugins" heading and a
 *   collapse button.
 * - Collapsed: shows icons only with tooltips, and an expand button.
 *
 * Built with Radix NavigationMenu for accessibility and React Router
 * NavLink for active-state styling.
 */

import * as NavigationMenu from "@radix-ui/react-navigation-menu";
import { PanelLeftClose, PanelLeftOpen } from "lucide-react";
import { NavLink } from "react-router-dom";
import { getActivePlugins } from "@/plugins/registry";
import { useAppStore } from "@/store";

export function Nav() {
  const isSidebarOpen = useAppStore((s) => s.isSidebarOpen);
  const setSidebarOpen = useAppStore((s) => s.setSidebarOpen);
  const plugins = getActivePlugins();

  // Nothing to render if no plugins are registered.
  if (plugins.length === 0) {
    return null;
  }

  // Collapsed state: icon-only sidebar with expand button.
  if (!isSidebarOpen) {
    return (
      <div className="shrink-0 border-r border-gray-200 bg-gray-50 p-2">
        <button
          type="button"
          onClick={() => setSidebarOpen(true)}
          className="rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900"
          aria-label="Open sidebar"
        >
          <PanelLeftOpen size={16} />
        </button>
        <nav className="mt-2 flex flex-col gap-1">
          {plugins.map((plugin) => (
            <NavLink
              key={plugin.id}
              to={plugin.path}
              title={plugin.name}
              className={({ isActive }) =>
                `rounded-md p-2 ${
                  isActive
                    ? "bg-gray-200 text-gray-900"
                    : "text-gray-500 hover:bg-gray-100 hover:text-gray-900"
                }`
              }
            >
              <plugin.icon size={16} />
            </NavLink>
          ))}
        </nav>
      </div>
    );
  }

  // Expanded state: full sidebar with icon, label, and collapse button.
  return (
    <NavigationMenu.Root
      orientation="vertical"
      className="flex w-56 shrink-0 flex-col border-r border-gray-200 bg-gray-50"
    >
      <div className="flex items-center justify-between p-2">
        <span className="px-1 text-xs font-medium uppercase tracking-wider text-gray-400">
          Plugins
        </span>
        <button
          type="button"
          onClick={() => setSidebarOpen(false)}
          className="rounded-md p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-900"
          aria-label="Collapse sidebar"
        >
          <PanelLeftClose size={16} />
        </button>
      </div>
      <NavigationMenu.List className="flex flex-col gap-1 px-2">
        {plugins.map((plugin) => (
          <NavigationMenu.Item key={plugin.id}>
            <NavLink
              to={plugin.path}
              className={({ isActive }) =>
                `flex items-center gap-2 rounded-md px-3 py-2 text-sm ${
                  isActive
                    ? "bg-gray-200 font-medium text-gray-900"
                    : "text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                }`
              }
            >
              <plugin.icon size={16} />
              <span>{plugin.name}</span>
            </NavLink>
          </NavigationMenu.Item>
        ))}
      </NavigationMenu.List>
    </NavigationMenu.Root>
  );
}
