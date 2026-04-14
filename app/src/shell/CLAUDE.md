# Shell Directory

## Purpose

The application shell: the chrome around plugins. These components compose
the top-level layout rendered by `App.tsx`.

## Key Files

| File | Purpose |
|---|---|
| `nav.tsx` | Sidebar listing registered plugins (Radix NavigationMenu). Supports expanded (icon + label) and collapsed (icon-only) states. |
| `header.tsx` | Top bar showing the active plugin's name. Matches current hash route against the plugin registry. |
| `viewport.tsx` | Renders the active plugin inside a Suspense boundary. Handles default redirect, 404, and empty-registry states. |

## Rules

- These components read from the plugin registry and global store but do
  not import anything from individual plugin directories.
- Routing lives here (viewport owns the `<Routes>` tree). Plugins declare
  their `path` in their `PluginDefinition`; the viewport wires it up.
- Styling uses Tailwind utilities. Keep the shell visually neutral so
  plugins control their own content area appearance.
