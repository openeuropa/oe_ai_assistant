# Lib Directory

## Purpose

Non-React infrastructure utilities shared across the application. Code here
has no UI concerns and no React imports.

## Key Files

| File | Purpose |
|---|---|
| `events.ts` | Typed event bus (mitt) for inter-plugin communication. Plugins emit and listen to events without importing each other. |

## Rules

- No React imports. If it needs React, it belongs in `shell/` or a plugin.
- Keep modules small and dependency-free where possible.
- Add new event types to the `AppEvents` map in `events.ts` as the plugin
  catalog grows.
