# API Client Directory

## Purpose

Contains the generated TypeScript types from the OpenAPI spec and the typed
API client used throughout the app.

## Key Files

| File | Purpose |
|---|---|
| `schema.d.ts` | Auto-generated types. **Never edit by hand.** Regenerate with `npm run api:generate`. |
| `client.ts` | Typed fetch client (openapi-fetch) and TanStack Query hooks (openapi-react-query). |
| `sse-types.ts` | TypeScript mapping of SSE event names to their payload types for the chat stream. |

## Key Decisions

- **openapi-fetch** for the runtime client: thin fetch wrapper, zero codegen
  bloat, fully typed against the spec.
- **openapi-react-query** for hooks: auto-generated TanStack Query hooks with
  typed query keys, params, and responses. No hand-written hooks per endpoint.
- **fetchClient is exported** for non-hook contexts (SSE streaming helpers,
  imperative calls outside React).
- **$api is the primary interface** for React components. Use `$api.useQuery`,
  `$api.useMutation`, etc.
- **baseUrl** comes from `loadConfig().apiBaseUrl` so the client respects the
  host page's configuration.

## Rules

- Never import from `schema.d.ts` directly in components. Use `$api` hooks
  for data fetching. Import schema types only for type annotations.
- SSE streaming does not go through openapi-fetch (EventSource/fetch-event-source
  handles the connection). Use the types from `sse-types.ts` to parse events.
- When the OpenAPI spec changes, run `npm run api:generate` and let TypeScript
  catch breakages.
