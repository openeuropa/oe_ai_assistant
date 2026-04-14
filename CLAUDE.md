# OpenEuropa AI Editorial Assistant

## Project Overview

Drupal 11 module (`oe_ai_assistant`) that embeds an AI-powered
editorial assistant into the CMS back-office. Two codebases live in
one repo:

- **PHP backend** (`src/`): Drupal module implementing a plugin-based
  REST API with SSE streaming, content schema extraction, and field
  mapping.
- **React frontend** (`app/`): standalone React app with its own
  plugin system, communicating exclusively through a CMS-agnostic
  REST API defined by an OpenAPI 3.1 spec.

The React app has no knowledge of Drupal. The OpenAPI spec
(`app/api/openapi.yaml`) is the single source of truth for the
contract between frontend and backend.

## Confidentiality

Never reference Claude Code, Anthropic, or AI-assisted development
tooling in any generated files (README, docs, comments, commit
messages, PR descriptions, etc.).

CLAUDE.md files are local development aids only. They are listed in
`.git/info/exclude`. Never commit them or override with `git add -f`.

## Writing Conventions

- **ASCII only in generated files.** No Unicode arrows, long dashes,
  curly quotes, ellipsis characters, or box-drawing characters. Stick
  to plain ASCII punctuation.
- **Always comment generated code.** Every file, function, type, and
  non-obvious block must have clear comments.
- **Kebab-case file naming** for all frontend source files and
  directories (e.g. `plugin-viewport.tsx`).
- **Markdown: wrap lines at 80 characters maximum.**

## Tech Stack

### Backend (PHP)

| Concern          | Technology                          |
|------------------|-------------------------------------|
| CMS              | Drupal 11                           |
| Language         | PHP 8.3+                            |
| AI integration   | drupal/ai (^1.3)                    |
| AI provider      | drupal/ai_provider_mistral (^1.1)   |
| Streaming        | drupal/agui (AG-UI protocol)        |
| Validation       | justinrainbow/json-schema           |
| Testing          | PHPUnit + Drupal Test Traits        |
| Coding standards | drupal/coder (PHPCS)                |

### Frontend (React)

| Concern          | Technology                          |
|------------------|-------------------------------------|
| Framework        | React 19 + TypeScript (strict)      |
| Bundler          | Vite (IIFE output for CMS)          |
| State (client)   | Zustand                             |
| State (server)   | TanStack Query                      |
| API types        | openapi-typescript                  |
| API client       | openapi-fetch + openapi-react-query |
| SSE client       | @microsoft/fetch-event-source       |
| Chat UI          | assistant-ui                        |
| UI primitives    | Radix UI + shadcn/ui                |
| Styling          | Tailwind CSS                        |
| Routing          | React Router (HashRouter)           |
| Linting          | Biome                               |
| Testing          | Vitest + Testing Library            |
| E2E              | Playwright                          |

## Project Structure

```
/                           # Drupal module root
  src/
    Annotation/             # Plugin annotations
    Controller/             # Route controllers
    Exception/              # Custom exceptions
    Plugin/OeAiAssistant/   # Backend plugins (Drafting, Echo, Notes)
    Service/                # Services (schema extraction, field mapping,
                            #   manifest reader, request validation)
  tests/
    src/                    # PHPUnit ExistingSite tests
    modules/                # Test-only modules (oe_news, oe_contact,
                            #   paragraph types)
  app/                      # React frontend (see app/CLAUDE.md)
    api/                    # OpenAPI spec (split per plugin)
    src/
      api/                  # Generated types + API client
      shell/                # App shell (nav, header, viewport)
      plugins/              # Frontend plugins (drafting, echo, notes)
      store/                # Zustand store + plugin slice infra
      components/           # Shared UI (shadcn/ui primitives)
      lib/                  # Non-React utilities (event bus)
    dist/                   # Built IIFE bundle (consumed by Drupal)
  docs/                     # Architecture docs (local only)
```

## Development Environment

The project uses [DDEV](https://ddev.com/) with the ddev-drupal-contrib
addon. The module root is the repository root.

### Setup

```bash
ddev start
ddev rebuild          # Full: composer, symlink, build app, install site
```

### Credentials

- **URL:** DDEV project URL
- **Username:** admin
- **Password:** admin

### Mistral API Key

```bash
cp .ddev/.env.dist .ddev/.env
# Edit .ddev/.env, set DRUPAL_MISTRAL_API_KEY
ddev restart
```

## Post-edit Workflow

After finishing any PHP/Drupal changes (code, config, services,
routing, etc.), always clear the Drupal cache:

```bash
docker exec ddev-oe-ai-assistant-web drush cr
```

## Common Commands

### Backend (run via DDEV)

| Command                                  | What it does                 |
|------------------------------------------|------------------------------|
| `ddev rebuild`                           | Full rebuild                 |
| `ddev install`                           | Reinstall site (fast)        |
| `ddev build-app`                         | Build React app bundle       |
| `ddev phpunit tests/src/ExistingSite/`   | Run backend tests            |
| `ddev phpcs`                             | PHP CodeSniffer check        |
| `ddev phpcbf`                            | Auto-fix PHPCS violations    |

### Frontend (run from `app/`)

| Command              | What it does                          |
|----------------------|---------------------------------------|
| `npm run dev`        | Vite + Express mock API (standalone)  |
| `npm run build`      | Production IIFE bundle to `app/dist/` |
| `npm run lint`       | Biome check                           |
| `npm run typecheck`  | TypeScript strict check               |
| `npm run test`       | Vitest                                |
| `npm run api:generate` | Regenerate types from OpenAPI spec |

## API Architecture

- **Shell endpoints (REST):** `/api/ai/content-schema/...`,
  state persistence, content type discovery. Standard HTTP verbs.
- **Plugin endpoints (RPC-style):**
  `/api/ai/plugins/{plugin_id}/{action}`. All POST, verb in URL,
  parameters in JSON body.
- **SSE streaming:** AI responses streamed via Server-Sent Events
  using the AG-UI protocol.

## Plugin System

Both frontend and backend share a plugin architecture:

| Plugin   | ID         | Purpose                                    |
|----------|------------|--------------------------------------------|
| Drafting | `drafting` | AI-powered content drafting with streaming  |
| Echo     | `echo`     | Dev: echoes input as word-by-word SSE       |
| Notes    | `notes`    | Dev: CRUD via State API                     |

Backend plugins live in `src/Plugin/OeAiAssistant/`. Frontend plugins
live in `app/src/plugins/`. Each frontend plugin is registered in
`app/src/plugins/registry.ts`.

## Key Design Principles

- **CMS-agnostic frontend:** the React app depends only on the
  OpenAPI contract, not on Drupal.
- **No automatic content modification:** AI generates drafts, but
  nothing is saved without explicit editorial action.
- **Server-side AI orchestration:** AI services are never called from
  the browser.
- **API-first:** the OpenAPI spec is a first-class deliverable,
  detailed enough for any backend to implement independently.

## Patches

Patches to contrib modules are managed via `cweagans/composer-patches`
and stored in `patches/`. They are declared in `composer.json` under
`extra.patches`.

| Module                  | Issue     | Patch file                                          |
|-------------------------|-----------|-----------------------------------------------------|
| drupal/ai               | #3579967  | `patches/ai-3579967-streaming-buffer-relative-urls.patch` |
| drupal/ai_provider_mistral | --     | `patches/ai-provider-mistral-streaming-chunk-loss.patch`  |

The Mistral patch fixes two streaming bugs:

1. `MistralChatMessageIterator` advanced the underlying generator
   before capturing chunk data. The SSE client reuses a single
   Response object, so `next()` overwrites `getChunk()`, causing
   the first token to be lost and text to repeat.
2. `MistralProvider::loadClient()` now injects a Guzzle client with
   `'stream' => true`. Without this, the default PSR-18 adapter
   buffers the entire HTTP response body before returning, so all
   SSE chunks arrive at once instead of progressively.

To apply patches after a fresh `composer install`, they are applied
automatically. To re-apply after editing a patch:

```bash
docker exec ddev-oe-ai-assistant-web bash -c \
  "cd /var/www/html && composer reinstall drupal/ai"
```

## Nginx SSE Config

`.ddev/nginx/sse-streaming.conf` disables FastCGI buffering for
SSE streaming endpoints (`/api/ai/plugins/*/chat` and `stream`).
Without this, nginx buffers ~16KB before sending to the client,
causing tokens to arrive in bursts.

## Git Workflow

- Branch naming: `OEL-{ticket}-{short-description}`
- Commit prefix: `OEL-{ticket}: message`
- Commit messages must be a single line. No body, no bullet
  list, no trailing paragraph -- subject line only.
- Check `.sink/tickets/` for ticket context when working on a ticket.

## Wiki

A project knowledge base lives in `docs/wiki/`. Before
starting work on a new ticket or investigation, read
`docs/wiki/index.md` to check if the wiki has relevant
context -- prior investigations, architectural decisions,
or comparisons that inform the work.

The wiki is maintained via slash commands:

- `/wiki:ingest` -- process a raw source into wiki pages
- `/wiki:query` -- answer a question using the wiki
- `/wiki:lint` -- health-check and fix wiki issues

## Skills

See `app/CLAUDE.md` for detailed frontend architecture, plugin
development guide, and sub-directory CLAUDE.md files for specific
areas.
