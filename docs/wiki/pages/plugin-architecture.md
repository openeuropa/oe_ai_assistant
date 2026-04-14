---
title: "Plugin Architecture"
type: concept
tags: [architecture, plugins, patterns]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# Plugin Architecture

Both frontend and backend share a plugin pattern.
Features ship as self-contained plugins on each side.

## Plugins

| Plugin   | ID         | Purpose                          |
|----------|------------|----------------------------------|
| Drafting | `drafting` | AI-powered drafting + streaming  |
| Echo     | `echo`     | Dev: echoes input as SSE         |
| Notes    | `notes`    | Dev: CRUD via State API          |

## Backend

- Plugins in `src/Plugin/AiEditorialAssistant/`
- Base class: `AiAssistantPluginBase`
- Interface: `AiAssistantPluginInterface`
- Manager: `AiAssistantPluginManager`
- Dispatch: `PluginController` receives
  `/api/ai/plugins/{id}/{action}` and calls
  `$pluginManager->get($id)->$action($request)`

## Frontend

- Plugins in `app/src/plugins/`
- Registered in `app/src/plugins/registry.ts`
- Each plugin is isolated: own UI, state, types, API
  helpers, and tests
- Dispatch: hash routes activate plugins

## Isolation Rules

- Each plugin owns its own Zustand slice
- Plugins can read other slices, never write to them
- Cross-plugin communication via event bus (mitt)

## See Also

- [Drafting Plugin](drafting-plugin.md)
- [OE AI Assistant Module](oe-ai-assistant-module.md)
