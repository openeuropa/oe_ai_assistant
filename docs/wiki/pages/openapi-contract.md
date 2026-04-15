---
title: "OpenAPI Contract"
type: entity
tags: [api, openapi, contract]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# OpenAPI Contract

Single source of truth for the API between frontend and backend. OpenAPI 3.1 spec.

## Structure

- Root assembler: `app/api/openapi.yaml` (uses `$ref`)
- Split per plugin:
  - `app/api/shell/` -- state + content type discovery
  - `app/api/plugins/drafting/` -- chat, reset, save
  - `app/api/plugins/echo/`
  - `app/api/plugins/notes/`

## Endpoint Styles

- **REST (shell):** resource-oriented, standard HTTP verbs (`GET /api/ai/content-schema/{type}/{bundle}`)
- **RPC-style (plugins):** all POST, verb in URL, params in body (`POST /api/ai/plugins/{plugin_id}/{action}`)

## Type Generation

- `npm run api:generate` regenerates TypeScript types from the spec
- TypeScript breaks on spec mismatch -- enforced in CI

## Notes

- Spec detailed enough for independent backend implementation
- Server-side state sync (`GET|PUT /api/state/{nodeId}`) defined but not yet wired in frontend

## See Also

- [CMS-Agnostic Frontend](cms-agnostic-frontend.md)
- [Plugin Architecture](plugin-architecture.md)
