# API Specification Directory

## Purpose

This directory holds the OpenAPI 3.1 specification that defines the contract
between the React app and whichever backend implements it. The spec is the
**single source of truth** for all API types, request/response shapes, and
error formats.

## Directory Structure

```
api/
  openapi.yaml                 # Root file: assembles everything via $ref
  shell/
    paths.yaml                 # State persistence + content type discovery
    schemas.yaml               # ApiError, AppStateBlob, ContentType, etc.
  plugins/
    echo/
      paths.yaml               # /plugins/echo/stream
      schemas.yaml             # EchoRequest, EchoStreamEvent
    notes/
      paths.yaml               # /plugins/notes, /plugins/notes/{noteId}
      schemas.yaml             # Note, NoteInput
```

The root `openapi.yaml` uses `$ref` to pull in paths and schemas from
sub-files. `openapi-typescript` resolves all refs when generating types.

## URL Structure

```
/api/...                              Shell-level (generic) endpoints (REST)
/api/plugins/{pluginId}/{action}      Plugin-specific endpoints (RPC-style)
```

Shell endpoints use REST (standard HTTP verbs, resource-oriented). Plugin
endpoints use RPC-style: all POST, verb in the URL path, parameters in the
JSON request body. This maps 1:1 to Drupal plugin methods.

Current layout:

| Prefix                         | Owner            | Style |
|---------------------------------|------------------|-------|
| `/api/state/`                   | Shell            | REST  |
| `/api/content-types/`           | Shell            | REST  |
| `/api/plugins/echo/stream`      | Echo (dev-only)  | RPC   |
| `/api/plugins/notes/{action}`   | Notes (dev-only) | RPC   |

## Adding a New Plugin's API

1. Create `api/plugins/{pluginId}/paths.yaml` and `schemas.yaml`.
2. In `api/openapi.yaml`, add `$ref` entries for the new paths (under
   `paths:`) and schemas (under `components: schemas:`).
3. Run `npm run api:generate` to regenerate `src/api/schema.d.ts`.
4. TypeScript will flag any frontend code that needs updating.

## Key Decisions

- **OpenAPI 3.1** (not 3.0) for full JSON Schema compatibility.
- **Split files with $ref.** Each plugin owns its own paths and schemas.
  The root file assembles them. This keeps plugin specs independent and
  avoids merge conflicts when multiple plugins evolve in parallel.
- **operationId on every endpoint.** The generated TypeScript types and
  TanStack Query hooks use these as keys.
- **Per-plugin SSE event schemas.** Each plugin that streams defines its
  own event payload schemas (e.g. `EchoStreamEvent`). This avoids a loose
  union type and lets each plugin evolve independently.
- **Error shape** is consistent: every error response uses the `ApiError`
  schema with `{ code, message }`.

## Workflow

1. Edit the relevant `paths.yaml` / `schemas.yaml` under `shell/` or
   `plugins/{pluginId}/`.
2. If adding new schemas or paths, wire them into `openapi.yaml`.
3. Run `npm run api:generate` to regenerate `src/api/schema.d.ts`.
4. TypeScript will flag any frontend code that no longer matches the spec.

## Rules

- Never hand-write request/response TypeScript types. Always generate from
  this spec.
- Every new endpoint needs an `operationId`, tags, and documented error
  responses.
- Plugin endpoints go under `/api/plugins/{pluginId}/`, not at the top level.
- Shell endpoints are for cross-cutting concerns only. If only one plugin
  needs it, it belongs under that plugin's prefix.
- Keep the spec self-contained enough for a backend team to implement
  without reading the React code.
- All `$ref` paths in sub-files that point back to the root use relative
  paths like `../../openapi.yaml#/components/schemas/ApiError`.
