# AI Editorial Assistant

## Project Overview

The **AI Editorial Assistant** is a React application that
orchestrates AI-powered editorial features. The application is
designed and developed in isolation from any CMS, communicating
exclusively through a well-documented, CMS-agnostic REST API. In
production, the app is mounted within the EWPP Drupal back-office,
and a Drupal module implements the API contract, but the React app
has no knowledge of or dependency on Drupal.

The application follows a plugin-based architecture where each
functionality is a self-contained plugin that can be independently
developed, tested, and deployed.

## Terminology

| This project (React app) | CMS side (Drupal)    |
|--------------------------|----------------------|
| Plugin                   | Module               |
| Application shell        | Host page            |
| API contract             | API implementation   |

Using "plugin" on the app side avoids confusion with Drupal
modules.

## Confidentiality

Never reference AI coding agents, AI-assisted development tooling,
or specific AI providers in any generated files (README, docs,
comments, commit messages, PR descriptions, etc.). The client
should not know we use AI coding agents.

AGENTS.md and CLAUDE.md files are committed to the repository as
development aids for AI coding agents.

## Writing Conventions

- **ASCII only in generated files.** Never use Unicode characters
  that are hard to type on a US QWERTY keyboard: no arrows, no
  long dashes (use `:` instead), no curly quotes, no ellipsis
  character, no box-drawing characters. Stick to plain ASCII
  punctuation.
- **Always comment generated code.** Every file, function, type,
  and non-obvious block of code must have clear comments
  explaining what it does and why.
- **Kebab-case file naming.** All source files use kebab-case
  (e.g. `plugin-viewport.tsx`, `plugin-nav.tsx`). This aligns
  with the assistant-ui convention. Directories also use
  kebab-case.
- **Markdown files: wrap lines at 80 characters maximum.**

## Key Principles

- **No automatic content modification:** AI generates drafts, but
  content is never saved or published without explicit editorial
  action.
- **Explicit editorial validation:** every AI output requires
  human review and approval before it enters the CMS.
- **Auditability:** all AI interactions and editorial decisions
  are traceable.
- **CMS-agnostic:** the app depends only on a documented API
  contract, not on any CMS-specific behaviour. It can run
  standalone during development using mocked or stubbed API
  endpoints.

## Decisions

- **Authentication:** cookie-based, inherited from the CMS
  session. No token management in the app. Unauthenticated API
  requests receive a 403.
- **Content types:** multi-content-type support designed from the
  start. Initial release targets a single content type.
- **Localisation:** English only for now.
- **Preview rendering:** server-side. The backend renders HTML
  from structured content; the app just displays it in an iframe.
- **Offline resilience:** Zustand `persist` middleware writes to
  `localStorage` as a fallback alongside server-side state
  persistence.

---

## Project Setup

### Build Tooling

**Vite** is the recommended bundler. It provides fast HMR during
development and produces optimised builds for production. The
output is a set of static assets (JS, CSS) that the CMS can load
on any page.

- `vite build` produces a manifest (`manifest.json`) mapping
  entry points to hashed filenames. The CMS module reads this
  manifest to inject the correct `<script>` and `<link>` tags.
- During development, `vite dev` runs a standalone server with
  mocked APIs, completely independent of the CMS.

### Language

TypeScript throughout. Strict mode enabled. All API types are
generated from the OpenAPI spec, so frontend and backend share a
single source of truth for data shapes.

### Package Manager

npm is the package manager for this project.

### Monorepo Consideration

If the project grows to include shared libraries or multiple apps,
consider a npm workspace. For now, a single-package repo is
sufficient. Plugins live inside the app as internal directories,
not separate packages.

---

## React Application Architecture

### Application Shell

The shell is the thin outer layer that:

1. Bootstraps the React app into a DOM node provided by the host
   page.
2. Loads configuration (API base URL, user context, enabled
   plugins) from a global object or data attributes on the mount
   node.
3. Discovers and registers plugins.
4. Renders the top-level layout (navigation, plugin viewport).

```
src/
  main.tsx              # entry point, mounts <App /> into DOM
  app.tsx               # shell: config, plugin registry, layout
  config.ts             # reads runtime config from host page
  shell/                # layout chrome (nav, header, viewport)
  plugins/              # plugin directory
  api/                  # generated API client + hooks
  lib/                  # non-React utilities (event bus, etc.)
  store/                # Zustand global store + plugin slices
```

### State Management

**Zustand** for global application state (active plugin, user
session, shared notifications). It is lightweight,
TypeScript-friendly, and has no boilerplate.

Each plugin manages its own internal state. Plugins can subscribe
to slices of the global store but should not mutate state owned by
other plugins.

### State Persistence

The full Zustand state is persisted to the backend as a JSON blob,
scoped per user and content node. This allows users to resume
where they left off when revisiting the application.

- **API endpoints:** the backend exposes a simple key-value store
  for app state (`GET /api/state/{nodeId}` to load,
  `PUT /api/state/{nodeId}` to save).
- **Initialisation:** on app load, the store is hydrated from the
  API response before rendering. Plugins must account for their
  slice being pre-populated with persisted data.
- **Sync strategy:** state is saved on change (debounced) and/or
  on a periodic interval (e.g. every 10 seconds). Also saved on
  `beforeunload` as a safety net.
- **Partial persistence:** not all state should be persisted.
  Transient UI state (loading flags, open menus, SSE connection
  status) is excluded using Zustand's `partialize` option.
- **Multi-tab:** if a user opens multiple tabs for the same node,
  the most recent write wins. This is acceptable for an editorial
  tool where concurrent self-editing is unlikely.
- **Local fallback:** Zustand's `persist` middleware also writes
  to `localStorage` as a safety net against connection loss. The
  server-side state is the source of truth; local storage is a
  resilience measure only.

### Server State

For server state (API data fetching, caching, optimistic updates),
use **TanStack Query** (React Query). It handles:

- Request deduplication and caching.
- Background refetching and stale-while-revalidate.
- Streaming/real-time updates via its `queryClient` invalidation.

### Routing

The app uses **React Router** with `HashRouter`. The CMS serves
the host page at a fixed path (e.g. `/node/123/ai`), and
hash-based routing gives each plugin its own route without
conflicting with server-side routing:

- `/node/123/ai#/drafting`
- `/node/123/ai#/tagging`
- `/node/123/ai#/quality-review`

The CMS is unaware of the hash portion. The shell reads the hash
to determine which plugin to activate. The node ID (`123`) is read
from the host page context and passed to plugins as the current
content reference.

### UI Components

Use a headless component library such as **Radix UI** or **React
Aria** for accessible primitives (dialogs, menus, tabs, tooltips).
Style with **Tailwind CSS**. Tailwind's utility classes are
namespaced enough to avoid clashes with the host CMS styles. A
scoped CSS reset on the app root element handles any residual
conflicts (e.g. base typography, heading resets).

---

## API Layer

### API Style: Hybrid REST + RPC

The API uses two styles depending on the endpoint's nature:

- **Shell endpoints (REST):** state persistence and content type
  discovery are resource-oriented. They use standard HTTP verbs
  (GET, PUT) and path-based resource identifiers
  (`/api/state/{nodeId}`, `/api/content-types`).
- **Plugin endpoints (RPC-style over HTTP):** plugin actions are
  operations, not resources. All plugin endpoints use POST with
  the verb in the URL path
  (`/api/plugins/{pluginId}/{action}`). Parameters (including
  IDs) go in the JSON request body, not in path segments. All
  responses use HTTP 200.

This maps 1:1 to Drupal's plugin dispatch pattern: a single
parameterized route (`/api/plugins/{plugin_id}/{action}`) delegates
to `$pluginManager->get($pluginId)->$action($request)`.

See `.sink/api-style-decision.md` for the full decision record
including analysis of gRPC, tRPC, and other alternatives.

### OpenAPI Specification

The API contract is defined in an OpenAPI 3.1 YAML file maintained
in this repository. This is the single source of truth. The API
specification is a first-class deliverable: it must be detailed
enough for any backend team (Drupal or otherwise) to implement the
endpoints independently.

The spec is split into per-plugin files assembled by the root
file:

```
api/
  openapi.yaml                 # root assembler (uses $ref)
  shell/
    paths.yaml                 # state + content type discovery
    schemas.yaml               # ApiError, AppStateBlob, etc.
  plugins/
    echo/
      paths.yaml               # /plugins/echo/stream
      schemas.yaml             # EchoRequest, EchoStreamEvent
    notes/
      paths.yaml               # /plugins/notes/{action}
      schemas.yaml             # Note, per-action request schemas
```

### Client Generation

Use **openapi-typescript** to generate TypeScript types from the
spec, **openapi-fetch** as the runtime client, and
**openapi-react-query** to integrate with TanStack Query. All
three are maintained by the same team.

- Zero-runtime-overhead type generation (types only, no codegen
  bloat).
- A thin fetch wrapper that is fully typed against the spec.
- Auto-generated TanStack Query hooks with typed query keys,
  params, and responses.
- Automatic alignment: when the spec changes, types update on
  rebuild.

```bash
npx openapi-typescript api/openapi.yaml -o src/api/schema.d.ts
```

```typescript
// src/api/client.ts
import createFetchClient from 'openapi-fetch'
import createClient from 'openapi-react-query'
import type { paths } from './schema'

const fetchClient = createFetchClient<paths>({
  baseUrl: '/api',
})
export const $api = createClient(fetchClient)
```

```typescript
// In components: path, params, and response are all
// inferred from the spec.
const { data, isLoading } = $api.useQuery(
  'get', '/drafts/{id}',
  { params: { path: { id: '123' } } }
)

const mutation = $api.useMutation(
  'post', '/chat/completions',
)
mutation.mutate({
  body: { message: 'Write a summary...' },
})
```

No hand-written hooks per endpoint. Query keys are generated
automatically, so cache invalidation is type-safe.

### Streaming

For endpoints that stream AI-generated content, the API uses
**Server-Sent Events (SSE)**. SSE is a good fit because:

- Unidirectional (server to client), which matches the "AI
  generates, user reads" pattern.
- Native browser support via `EventSource`, with automatic
  reconnection.
- Simple to implement server-side in any language/framework.
- Works through proxies and load balancers better than
  WebSockets.

Since the app runs inside the CMS back-office, users are already
authenticated via session cookies. API requests (including SSE)
carry the session cookie automatically, so no token management is
needed. However, the native `EventSource` API does not support
sending custom headers or cookies reliably in all scenarios. Use
**@microsoft/fetch-event-source** for SSE consumption, which uses
`fetch` under the hood and inherits cookie-based auth naturally.

Each SSE event carries a typed JSON payload. Event types are
documented in the OpenAPI spec using the `x-sse-events` extension
or a companion markdown document.

### Dev Server

During development an Express server (`server/`) provides mock
API endpoints (state persistence, content types, plugin actions).
Vite proxies `/api` requests to it. Run both together with
`npm run dev`.

---

## Plugin System

### Structure

Each plugin lives in its own directory under `src/plugins/`:

```
src/plugins/
  content-drafting/
    index.ts            # plugin registration
    components/         # React components
    hooks/              # custom hooks (API calls, state)
    api/                # plugin-specific API helpers
    types.ts            # plugin-specific types
    __tests__/          # plugin tests
  content-tagging/
    index.ts
    ...
```

### Plugin Definition

Each plugin exports a `PluginDefinition` object:

```typescript
interface PluginDefinition {
  id: string
  name: string
  description: string
  icon: ComponentType
  // The root component rendered when this plugin is active.
  component: ComponentType
  // API endpoints this plugin requires.
  requiredEndpoints: string[]
  // Optional: route path if using routing.
  path?: string
}
```

### Plugin Registry

The application shell imports plugin definitions and builds a
registry at startup. Plugins are loaded lazily using
`React.lazy()` and `Suspense` to keep the initial bundle small.

```typescript
// src/plugins/registry.ts
import { lazy } from 'react'
import type { PluginDefinition } from './types'

export const plugins: PluginDefinition[] = [
  {
    id: 'content-drafting',
    name: 'Content Drafting',
    description: 'AI-assisted content creation',
    icon: PenIcon,
    component: lazy(() =>
      import('./content-drafting/components/Root')
    ),
    requiredEndpoints: [
      '/api/chat/completions',
      '/api/drafts',
    ],
  },
]
```

This is a static registry (plugins are known at build time).
There is no need for dynamic plugin discovery at runtime: the set
of plugins is controlled by the development team. If this changes
in the future, the registry can be extended to support dynamic
imports from a configuration endpoint.

### Plugin Communication

Plugins should be loosely coupled. When they need to share data:

1. **Zustand store slices:** each plugin owns its own slice and
   has full read/write access to it. Plugins can read other
   plugins' slices (e.g. current user, active content type) but
   should not write to them.
2. **Event bus:** for fire-and-forget notifications between
   plugins, use a simple typed event emitter (e.g. **mitt**,
   ~200 bytes). Example: the drafting plugin emits
   `draft:created`, the tagging plugin listens and suggests tags.
3. **URL state:** for shareable navigation state, encode in the
   URL hash.

Avoid direct imports between plugin directories.

---

## Content Drafting Plugin

### Chat Interface

Use the **assistant-ui** library (assistant-ui.com) for the chat
panel. It provides:

- A React component library purpose-built for AI chat UIs.
- Built-in support for streaming responses.
- Message threading, markdown rendering, code blocks.
- Customisable message components (to render structured content,
  actions).

assistant-ui supports custom runtimes, so it can be wired to the
backend API without depending on any specific AI provider SDK.

The integration pattern:

1. Define a custom `RuntimeAdapter` that sends user messages to
   the backend API and consumes the SSE stream for assistant
   responses.
2. Use assistant-ui's `AssistantProvider` and `Thread` components
   for the chat panel.
3. Extend message rendering to include action buttons (e.g.
   "Apply to draft", "Regenerate", "Edit prompt").

### UI Layout

Split-panel layout inspired by the chat-with-artifacts pattern:

- **Left panel:** conversational chat interface for prompting.
  Additional UI elements for user operations (e.g. selecting
  content type, setting parameters, uploading reference
  documents) live here as well.
- **Right panel (artifact):** the drafted content, rendered using
  the site's actual frontend templates via an iframe.

### Artifact Panel

The right panel renders the drafted content. Key considerations:

- **Structured content model:** the AI produces structured data
  (title, body, summary, metadata), not raw HTML. The content
  type determines which fields are available and which prompting
  strategies the AI uses. The data model supports multiple
  content types from the start, even though the initial release
  targets a single type.
- **Iframe-based preview:** the backend exposes a preview API
  endpoint that accepts structured content and returns fully
  rendered HTML. The iframe loads this endpoint, so all
  templating logic stays on the backend. The app never needs to
  know about templates, CSS, or theme assets. Content updates
  are sent to the iframe via `postMessage` to trigger
  re-renders.

### Draft Lifecycle

```
[User sends prompt]
    |
    v
[API: POST /api/chat/completions]  -->  SSE stream
    |
    v
[Chat panel renders streamed response]
    |
    v
[Structured content extracted from response]
    |
    v
[Preview panel updates in real time]
    |
    v
[User reviews, edits, or re-prompts]
    |
    v
[User explicitly saves draft]
    |
    v
[API: POST /api/drafts]  -->  Draft stored in CMS
```

---

## Testing Strategy

### Unit Tests

**Vitest** (aligned with Vite) for unit and component tests. Use
**Testing Library** (@testing-library/react) for component tests
that focus on user behaviour rather than implementation details.

### E2E Tests

**Playwright** for end-to-end tests. Run against the Vite dev
server with the Express mock API. Use Playwright's built-in
`page.route()` to intercept and mock specific responses when
needed. Optionally run a subset against the real backend in a
staging environment.

---

## Recommended Packages

| Concern            | Package                       |
|--------------------|-------------------------------|
| Bundler            | Vite                          |
| State (client)     | Zustand                       |
| State (server)     | TanStack Query                |
| API types          | openapi-typescript            |
| API client         | openapi-fetch                 |
| API + TanStack     | openapi-react-query           |
| SSE client         | @microsoft/fetch-event-source |
| Chat UI            | assistant-ui                  |
| UI primitives      | Radix UI                      |
| Styling            | Tailwind CSS                  |
| Event bus          | mitt                          |
| Routing            | React Router (HashRouter)     |
| Testing            | Vitest + Testing Library      |
| E2E                | Playwright                    |

---

## Drupal Backend Implementation Notes

This section captures implementation considerations specific to
Drupal. The React app does not depend on any of this, but the team
building the Drupal module that implements the API contract should
be aware of these points.

### SSE Streaming

Drupal inherits Symfony's `StreamedResponse`, which supports SSE
natively. A controller can flush AI-generated chunks
incrementally:

```php
$response = new StreamedResponse(function () {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');

    foreach ($this->aiService->streamCompletion($prompt)
      as $chunk) {
        echo "data: " . json_encode($chunk) . "\n\n";
        ob_flush();
        flush();
    }
});
```

**Considerations:**

- **PHP worker blocking:** each SSE connection holds a PHP-FPM
  process for the duration of the stream. Acceptable for a
  back-office tool with limited concurrent editors. Not suitable
  for public-facing traffic.
- **Reverse proxies:** Nginx and Varnish buffer responses by
  default. SSE endpoints need `X-Accel-Buffering: no` header or
  proxy-level config to disable buffering.
- **Execution time limits:** long streams require
  `set_time_limit(0)` or a generous timeout on SSE routes
  specifically.
- **Authentication:** SSE connections are standard HTTP requests,
  so Drupal's session/cookie authentication works without extra
  setup.

---

## Related Documents

- Functional spec:
  `archive/01-content-creation-with-ai-assistance.md`
- Implementation ideas:
  `archive/01a-content-tagging-implementation-ideas.md`
- Source PDFs: `sources/use-case-01/`
