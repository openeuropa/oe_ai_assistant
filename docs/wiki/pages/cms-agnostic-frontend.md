---
title: "CMS-Agnostic Frontend"
type: concept
tags: [frontend, architecture, react]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# CMS-Agnostic Frontend

The React app has zero knowledge of Drupal. It depends only on the OpenAPI contract.

## Key Facts

- React 19 + TypeScript (strict mode)
- Runs standalone with Express mock API (`npm run dev`)
- Mock server in `app/server/` provides in-memory state, fake SSE, and real Mistral calls for drafting
- Cookie auth inherited from CMS -- no API keys in the frontend
- IIFE bundle built by Vite, loaded by Drupal from `app/dist/`
- CI runs Playwright against the standalone setup

## Production Flow

1. Drupal page loads IIFE bundle from `app/dist/`
2. Bundle mounts into a DOM node
3. React shell (Zustand + TanStack Query + Router) activates plugin from hash route
4. API calls use cookie auth inherited from Drupal
5. SSE stream parsed as [UI Message Stream](ui-message-stream.md) events

## See Also

- [OpenAPI Contract](openapi-contract.md)
- [OE AI Assistant Module](oe-ai-assistant-module.md)
- [Zustand State Management](zustand-state-management.md)
