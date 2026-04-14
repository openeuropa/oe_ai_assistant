---
title: "Zustand State Management"
type: entity
tags: [zustand, state, frontend]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# Zustand State Management

Single Zustand store, split into slices.

## Store Structure

- **Shell slice:** active plugin, user session,
  notifications
- **Plugin slices:** each plugin owns its own slice
- Plugins can read other slices, never write to them

## Cross-Plugin Communication

- Event bus (`mitt`) for fire-and-forget events
- No direct cross-slice writes

## Persistence

- `persist` middleware writes to `localStorage`
- Transient UI state (e.g. `isSidebarOpen`) excluded
  via `partialize`
- Plugin slices hydrated before first render via
  `initializePluginSlices` in `app/src/init.tsx`

## Server-Side State Sync

- Defined in OpenAPI: `GET|PUT /api/state/{nodeId}`
- NOT yet wired in frontend
- Planned: debounced / periodic / beforeunload syncs

## Server State

- TanStack Query handles caching, background refetch,
  deduplication

## See Also

- [CMS-Agnostic Frontend](cms-agnostic-frontend.md)
- [Plugin Architecture](plugin-architecture.md)
