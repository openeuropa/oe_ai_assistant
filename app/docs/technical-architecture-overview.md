# AI Editorial Assistant: Technical Architecture Overview

## Vision

The **AI Editorial Assistant** is a modular, embeddable React application that
brings AI-powered editorial tools into the EWPP back-office. It is designed as
a platform: a thin shell that hosts independent plugins, each delivering a
distinct AI capability (content drafting, tagging, quality review, accessibility
checks, and more).

The architecture prioritises **independence from the host CMS**. The application
communicates exclusively through a documented, CMS-agnostic REST API. In
production it is embedded within Drupal, but it can be developed, tested, and
demonstrated without any CMS infrastructure.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────┐
│  CMS Back-Office (host page)                            │
│                                                         │
│  ┌───────────────────────────────────────────────────┐  │
│  │  React Application (mounted via <script> tag)     │  │
│  │                                                   │  │
│  │  ┌─────────────┐ ┌─────────────┐ ┌────────────┐  │  │
│  │  │  Plugin A    │ │  Plugin B    │ │  Plugin C   │  │  │
│  │  │  (drafting)  │ │  (tagging)   │ │  (review)   │  │  │
│  │  └──────┬───────┘ └──────┬───────┘ └─────┬──────┘  │  │
│  │         │                │               │         │  │
│  │  ┌──────┴────────────────┴───────────────┴──────┐  │  │
│  │  │     Application Shell (router, shared state) │  │  │
│  │  └──────────────────────┬───────────────────────┘  │  │
│  └─────────────────────────┼─────────────────────────┘  │
│                            │  REST API (OpenAPI)        │
│  ┌─────────────────────────┴─────────────────────────┐  │
│  │  CMS Module (API implementation)                  │  │
│  │  - Orchestrates AI services server-side           │  │
│  │  - Streams AI responses via SSE                   │  │
│  │  - Renders content previews                       │  │
│  │  - Persists application state                     │  │
│  └─────────────────────────┬─────────────────────────┘  │
└────────────────────────────┼────────────────────────────┘
                             │
                  ┌──────────┴──────────┐
                  │   AI Services        │
                  │   (Mistral, Deepset) │
                  └─────────────────────┘
```

## Core Principles

### CMS-Agnostic by Design

The React application has no knowledge of Drupal. It depends solely on an
**OpenAPI 3.1 specification** that defines the API contract. This separation
delivers several benefits:

- **Independent development.** Frontend and backend teams work in parallel.
  The React app runs standalone with mocked APIs during development; no CMS
  installation or provider credential is required for the default frontend
  workflow.
- **Portability.** The same application could be mounted on a different CMS
  or served as a standalone tool. Only the API implementation would change.
- **Clear team boundaries.** The OpenAPI spec is the handoff point. The
  backend team implements it; the frontend team consumes it. Both sides can
  validate against the spec independently.
- **Testability.** The app is tested against mocked API responses derived
  from the spec, making tests fast, deterministic, and infrastructure-free.

### Embeddable

The application is built as a set of static assets (JS, CSS) produced by Vite.
The host page loads these assets via `<script>` and `<link>` tags, and the app
mounts into a designated DOM node. Configuration (API base URL, user context,
content node ID) is passed via the mount element or a global object.

Styling uses Tailwind CSS, whose utility classes avoid clashes with the host
page styles. A scoped CSS reset on the app root handles residual conflicts.

### Plugin-Based Modularity

Each AI feature is a **plugin**: a self-contained unit with its own components,
state, and API interactions. Plugins are:

- **Lazy-loaded** for performance (each is a separate JS chunk).
- **Independently testable** with their own test suites.
- **Loosely coupled** to each other. Communication happens through read-only
  access to shared state and a typed event bus, never through direct imports.

New capabilities are added by registering a new plugin. The shell, routing, and
shared infrastructure do not change.

### API-First

The OpenAPI specification is a **first-class deliverable**, not an afterthought.
It is maintained in the React app repository and defines:

- All endpoints, request/response schemas, and error formats.
- Streaming behaviour (SSE event types and payloads).
- Content type definitions that drive both the AI prompting and the preview.

TypeScript types are generated directly from the spec, ensuring the frontend
code is always aligned with the contract.

### Server-Side AI Orchestration

AI services are never called directly from the browser. The backend acts as the
sole orchestrator:

- Sends prompts to AI services and streams responses back to the app via SSE.
- Initiates processing pipelines and reports progress.
- Applies content type logic, prompt engineering, and guardrails server-side.

This keeps API keys and AI logic out of the client, and allows the backend team
to swap or update AI providers without frontend changes.

## Key Technical Decisions

| Decision | Choice | Rationale |
|---|---|---|
| API contract | OpenAPI 3.1 | Source of truth for types, mocks, and docs |
| Frontend framework | React + TypeScript | Widely adopted, strong ecosystem |
| Bundler | Vite | Fast dev server, optimised production builds |
| State (client) | Zustand | Lightweight, typed, built-in persistence |
| State (server) | TanStack Query | Caching, dedup, background refetch |
| API client | openapi-fetch + openapi-react-query | Typed hooks generated from spec |
| AI streaming | Server-Sent Events | Simple, unidirectional, session-auth compatible |
| Routing | React Router (HashRouter) | No conflict with CMS server-side routes |
| Styling | Tailwind CSS | Utility-first, minimal clash with host page |
| UI primitives | Radix UI | Accessible, headless, composable |
| Testing | Vitest, Testing Library, Playwright | Fast unit/component/E2E tests |
| API mocking | MSW | Spec-driven mocks for dev and tests |
| Authentication | CMS session cookies | Transparent, no token management |

## State Management and Persistence

Application state is managed by Zustand. Each plugin owns a state slice with
full read/write access; cross-plugin access is read-only.

State is persisted in two layers:

1. **Server-side:** the backend stores the full state as a JSON blob scoped
   per user and content node. The app hydrates from this on load and syncs
   back on change (debounced) and on `beforeunload`.
2. **Local fallback:** Zustand's `persist` middleware writes to `localStorage`
   as a safety net against connection loss.

This lets editors close the browser and resume exactly where they left off.

## Development Workflow

1. **Write the OpenAPI spec** for the endpoints the plugin needs.
2. **Generate types** from the spec (`openapi-typescript`).
3. **Create MSW mock handlers** that return fixture data and simulated streams.
4. **Build the plugin** against the mocked API using `npm run dev`.
5. **Hand off the spec** to the backend team for implementation.
6. **Integration test** against the real backend in a staging environment.

Frontend and backend development proceed in parallel from step 3 onward.

For the local reference server, the drafting plugin defaults to a fixture-backed
mock mode. Real Mistral-backed drafting is available separately via
`npm run dev:integration` when provider behaviour needs to be validated.

---

## First Plugin: Content Drafting

The content drafting plugin demonstrates the architecture in action. It provides
a split-panel interface inspired by the chat-with-artifacts pattern.

### UI Layout

- **Left panel:** a chat interface (built with assistant-ui) where editors
  describe the content they need. Additional controls for selecting content
  type, setting parameters, and uploading reference documents.
- **Right panel:** an iframe that displays the drafted content rendered by the
  backend using the site's actual frontend templates. The editor sees exactly
  how the content will look when published.

### User Flow

1. Editor opens the AI assistant on a content node
   (`/node/123/ai#/drafting`).
2. Previous session state is restored.
3. Editor describes the desired content via the chat interface.
4. The backend streams the AI response in real time (SSE).
5. Structured content (title, body, summary, metadata) is extracted from
   the response and sent to the preview iframe.
6. The backend renders the preview using real site templates and returns HTML.
7. Editor reviews, refines via follow-up prompts, or edits directly.
8. Editor explicitly saves the draft to the CMS.

### Content Model

The AI produces structured data, not raw HTML. The content type determines
which fields are available and which prompting strategies the AI uses. The data
model supports multiple content types from the start, even though the initial
release targets a single type.

### Safeguards

- No content is saved or published without explicit editorial action.
- Every AI output requires human review and approval.
- All AI interactions and editorial decisions are auditable.

---

## Drupal Backend Considerations

The React app does not depend on Drupal, but the backend implementation will
be a Drupal module. Key considerations for the Drupal team:

- **SSE streaming:** Symfony's `StreamedResponse` (inherited by Drupal)
  supports SSE natively. Each connection holds a PHP-FPM worker for the
  duration of the stream; acceptable for a back-office tool with limited
  concurrent users.
- **Reverse proxies:** Nginx/Varnish buffer responses by default. SSE
  endpoints need `X-Accel-Buffering: no` or equivalent proxy configuration.
- **Execution time limits:** streaming routes need generous timeouts
  (`set_time_limit(0)` or per-route configuration).
- **Authentication:** standard Drupal session/cookie auth. The React app
  sends cookies automatically; no special handling needed.

## Constraints

- No Node.js backend. All server-side logic is in the CMS (Drupal/PHP).
- AI services are called server-side only, never from the browser.
- English-only UI for the initial release.
