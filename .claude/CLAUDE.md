@AGENTS.md

# OpenEuropa AI Editorial Assistant

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

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

Never reference AI coding agents, AI-assisted development tooling,
or specific AI providers in any generated files (README, docs,
comments, commit messages, PR descriptions, etc.).

AGENTS.md and CLAUDE.md files are committed to the repository as
development aids for AI coding agents.

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
    Plugin/AiEditorialAssistant/  # Backend plugins (Drafting, Echo, Notes)
    Service/                # Services (schema extraction, field mapping,
                            #   manifest reader, request validation)
  tests/
    src/                    # PHPUnit ExistingSite tests
    modules/                # Test-only modules (oe_news, oe_contact,
                            #   paragraph types)
  app/                      # React frontend (see app/AGENTS.md)
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

Backend plugins live in `src/Plugin/AiEditorialAssistant/`. Frontend plugins
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

## Drupal Development Best Practices

### Services

- Service IDs use module prefix + dot notation:
  `oe_ai_assistant.ui_message_stream`, not FQCN.
- Define an interface for every service. Type-hint the
  interface, not the concrete class.
- Use `{@inheritdoc}` on implementation methods when the
  docblock is on the interface. Only document implementation-
  specific details (e.g. constructor params, protected methods).

### Dependency Injection

- Prefer constructor injection via services.yml.
- For plugins (which use `ContainerFactoryPluginInterface`),
  inject via the `create()` factory method.
- Never call `\Drupal::service()` in service classes. Only
  acceptable in procedural hooks and legacy code.

### Configuration and State

- Use Drupal config API for persistent settings.
- Use Drupal state API for transient runtime data.
- Be aware that `$config[]` overrides in `settings.php` take
  precedence over database config and cannot be overridden
  via `configFactory()->getEditable()`.

### JSON Schema

- All schemas passed to ai_agents (structured output, tool
  definitions, sub-agent schemas) must be valid JSON Schema
  objects with `type`, `properties`, `required`, and
  `additionalProperties` fields.
- Never use shorthand like `{"title": {"type": "string"}}`.
- OpenAI rejects invalid schemas; Mistral is lenient but
  structured output only works with proper schemas.

### Coding Standards

- Follow Drupal and DrupalPractice PHPCS standards.
- Run `ddev phpcs` before committing PHP changes.
- Use `declare(strict_types=1)` in all new PHP files.

### Testing

- Use ExistingSite tests (`weitzman/drupal-test-traits`) for
  integration tests that hit real HTTP endpoints.
- ExistingSite tests run in a separate process from the web
  server -- static variables do not persist across the
  boundary. Use Drupal state API for cross-process data.
- Use `ExistingSiteConfigBackupTrait` to backup/restore config
  in tests that modify AI provider settings.

## Skills

See `app/AGENTS.md` for detailed frontend architecture, plugin
development guide, and sub-directory AGENTS.md files for specific
areas.
