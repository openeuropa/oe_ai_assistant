# AI Editorial Assistant

A React application that orchestrates AI-powered editorial features through a
plugin-based architecture. Designed to run standalone or embedded within a CMS
back-office, communicating exclusively through a CMS-agnostic REST API.

## Prerequisites

- Node.js >= 22
- pnpm (the exact version is pinned in `package.json`)

## Getting Started

Use pnpm for all frontend dependencies and commit `pnpm-lock.yaml` when they
change. We use pnpm 10 for compatibility with the Corepack bundled in Node.js 22.
Enable Corepack once:

```bash
corepack enable
```

Run these commands from `app/`; Corepack selects the pinned pnpm version:

```bash
# Install dependencies
pnpm install

# Start standalone frontend development
# Drafting runs against fixture-backed mock data by default.
pnpm run dev

# Optional: run the real Mistral-backed drafting flow instead
cp .env.dist .env
# then set MISTRAL_API_KEY in app/.env
pnpm run dev:integration

# Or run the pieces individually
pnpm run dev:client              # Vite dev server only
pnpm run dev:server              # Mock API server only
pnpm run dev:server:integration  # Mistral-backed API server only
```

### Switching an existing checkout from npm

Replace the old dependency directory once, then install from the pnpm lockfile:

```bash
rm -rf node_modules
pnpm install --frozen-lockfile
```

CI, releases, and `ddev build-app` use `--frozen-lockfile` so dependency changes
must include an updated lockfile. After pulling this migration, run `ddev restart`
to enable Corepack and Node.js 22 in the web container.

Dependency install scripts are configured in `pnpm-workspace.yaml`. Review new
scripts with `pnpm approve-builds` and commit any resulting configuration changes.
For future dependency patches, use `pnpm patch` and `pnpm patch-commit`.

### Supported Development Modes

- `pnpm run dev`: default standalone workflow. No provider credentials are
  required. The drafting plugin uses deterministic fixture data from
  `server/fixtures/drafting/`.
- `pnpm run dev:integration`: opt-in provider-backed workflow. This keeps the
  frontend running locally, but the drafting route uses the real Mistral client
  and requires `MISTRAL_API_KEY` in `app/.env`.

The standalone mock mode is the expected default for frontend-only work. Use
integration mode only when you need to validate prompt/provider behaviour.

## Scripts

| Command              | Description                                   |
|----------------------|-----------------------------------------------|
| `pnpm run dev`        | Start client + standalone mock API server      |
| `pnpm run dev:integration` | Start client + Mistral-backed API server |
| `pnpm run build`      | Type-check and build for production            |
| `pnpm run typecheck`  | Run TypeScript type checking                   |
| `pnpm run lint`       | Lint with Biome                                |
| `pnpm run lint:fix`   | Lint and auto-fix                              |
| `pnpm run format`     | Format with Biome                              |
| `pnpm run api:generate` | Regenerate TypeScript types from OpenAPI spec |
| `pnpm run storybook`  | Start Storybook on port 6006                   |

## Project Structure

```
src/
  main.tsx              # Dev entry point (calls init with defaults)
  init.tsx              # Public init() API for host page embedding
  app.tsx               # Application shell: layout, routing, providers
  config.ts             # Runtime configuration store (set/get)
  plugins/              # Plugin directory (each plugin is self-contained)
  api/                  # API client, generated types, SSE type helpers
  components/           # Shared UI primitives
  shell/                # App shell layout components
  store/                # Zustand global state with plugin slice support
server/                 # Express mock API server for development
  fixtures/             # Content schema + drafting fixtures for mock mode
api/                    # OpenAPI 3.1 specification (source of truth)
```

## Embedding in a Host Site

The production build produces a single IIFE bundle that exposes
`window.AiEditorialAssistant`. The host page loads it and calls `init()` to
mount the app into any DOM element.

### Build

```bash
pnpm run build
```

To include dev-only plugins (`echo`, `notes`) in a local build that will be
imported into Drupal, build with:

```bash
DEV_PLUGINS=true pnpm run build
```

This produces:

- `dist/ai-editorial-assistant.iife.js` -- the application bundle
- `dist/style.css` -- the application styles
- `dist/.vite/manifest.json` -- maps entry points to hashed filenames

### Embed

Load the built assets and call `init()` with a CSS selector (or DOM element)
and a configuration object:

```html
<!-- Load the app assets -->
<link rel="stylesheet" href="/path/to/dist/style.css" />
<script src="/path/to/dist/ai-editorial-assistant.iife.js"></script>

<!-- Provide a mount point -->
<div id="ai-assistant"></div>

<!-- Initialize -->
<script>
  AiEditorialAssistant.init('#ai-assistant', {
    apiBaseUrl: '/api',
    sessionId: '42',
    userId: 'editor-12',
    enabledPlugins: ['drafting'],
  });
</script>
```

### Configuration Options

| Option           | Type       | Default  | Description                                      |
|------------------|------------|----------|--------------------------------------------------|
| `apiBaseUrl`     | `string`   | `"/api"` | Base URL for all API requests.                   |
| `sessionId`      | `string`   | Required | Editorial session ID the app is mounted on.      |
| `nodeId`         | `string`   | `null`   | CMS content node the editor is working on.       |
| `userId`         | `string`   | Required | Authenticated user ID from the CMS session.      |
| `enabledPlugins` | `string[]` | `[]`     | Plugin IDs to activate (empty = all non-dev plugins; dev-only plugins also require `DEV_PLUGINS=true` at build/dev-server startup). |

`userId` and `sessionId` are required. Persisted state (localStorage) is
scoped per editorial session only: sessions are collaborative, so every
user with access to a session shares its state. Sensible defaults are
applied for any other omitted values.

### Return Value

`init()` returns a `Promise<AppHandle>` with a method to tear down the app:

```js
const app = await AiEditorialAssistant.init('#ai-assistant', { ... });

// Later, to unmount cleanly:
app.unmount();
```

### Drupal Integration

In a Drupal module, attach the assets to the page and call `init()` from a
`drupalSettings`-driven inline script:

```php
// In a render array or hook_page_attachments():
$build['#attached']['html_head'][] = [
  [
    '#type' => 'html_tag',
    '#tag' => 'link',
    '#attributes' => [
      'rel' => 'stylesheet',
      'href' => '/modules/custom/ai_assistant/dist/style.css',
    ],
  ],
  'ai_assistant_css',
];

$build['#attached']['html_head'][] = [
  [
    '#type' => 'html_tag',
    '#tag' => 'script',
    '#attributes' => [
      'src' => '/modules/custom/ai_assistant/dist/ai-editorial-assistant.iife.js',
    ],
  ],
  'ai_assistant_js',
];
```

```twig
{# In the Twig template: #}
<div id="ai-assistant"></div>
<script>
  AiEditorialAssistant.init('#ai-assistant', {
    apiBaseUrl: drupalSettings.aiAssistant.apiBaseUrl,
    nodeId: drupalSettings.aiAssistant.nodeId,
    userId: drupalSettings.aiAssistant.userId,
  });
</script>
```

### Authentication

The app relies on cookie-based authentication inherited from the CMS session.
API requests carry the session cookie automatically -- no token management is
needed on the frontend. Unauthenticated requests receive a `403` response.

### Routing

The app uses hash-based routing (`HashRouter`) so its internal routes
(e.g. `#/drafting`, `#/tagging`) never conflict with the CMS server-side
routing. The CMS serves the host page at a fixed path, and the hash fragment
is handled entirely by the React app.

## API Contract

The OpenAPI 3.1 specification in `api/openapi.yaml` defines every endpoint the
app depends on. TypeScript types are generated from it:

```bash
pnpm run api:generate
```

This produces `src/api/schema.d.ts`. The typed API client in `src/api/client.ts`
uses these types so all fetch calls are validated at compile time.

## Tech Stack

- **React 19** with TypeScript (strict mode)
- **Vite** for bundling and dev server (IIFE output for CMS embedding)
- **Zustand** for client state management
- **TanStack Query** for server state
- **openapi-fetch** + **openapi-react-query** for typed API calls
- **React Router** (HashRouter) for CMS-safe routing
- **Radix UI** for accessible component primitives
- **Tailwind CSS** for styling
- **MSW** for API mocking in development and tests
- **Biome** for linting and formatting
- **Vitest** and **Playwright** for testing
- **Storybook** for component development

## Plugin Architecture

Plugins live under `src/plugins/` as self-contained directories. Each plugin
exports a `PluginDefinition` and is lazy-loaded via `React.lazy()`. The
application shell discovers and registers plugins through a static registry
at build time.

See `src/plugins/CLAUDE.md` for the plugin development guide and
`docs/technical-architecture-overview.md` for the full architectural overview.
