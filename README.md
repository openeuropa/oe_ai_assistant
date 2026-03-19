# OpenEuropa AI Editorial Assistant

Drupal 11 module that embeds an AI-powered editorial assistant into the CMS back-office. Provides a plugin-based
architecture for AI features including content drafting, schema extraction, and streaming chat via AG-UI.

## Requirements

- Drupal 11
- PHP 8.3+
- [AI module](https://www.drupal.org/project/ai) (^1.3)
- [AI Provider Mistral](https://www.drupal.org/project/ai_provider_mistral) (^1.1)
- [AG-UI](https://www.drupal.org/project/agui) (^1.0)
- Content Moderation (core)

## Installation

Install via Composer:

```bash
composer require openeuropa/oe_ai_assistant
drush en oe_ai_assistant
```

## Features

- **Plugin system** -- extensible architecture for AI-powered editorial tools
- **Content drafting** -- AI-assisted content creation with structured field output
- **Schema extraction** -- generates content type schemas (data and form-aware modes) for LLM consumption
- **Field mapping** -- maps AI-drafted fields to Drupal entities, including paragraphs
- **SSE streaming** -- real-time streaming of AI responses via AG-UI protocol
- **Request validation** -- validates API requests against OpenAPI schemas

### Bundled plugins

| Plugin | ID | Description |
|--------|----|-------------|
| Drafting | `drafting` | AI-powered content drafting with streaming chat and tool calls |
| Echo | `echo` | Development plugin that echoes input as a word-by-word SSE stream |
| Notes | `notes` | Development plugin with CRUD operations via State API |

### API endpoints

| Route | Method | Description |
|-------|--------|-------------|
| `/api/ai/plugins/{plugin_id}/{action}` | POST | Dispatch plugin actions |
| `/node/{node}/ai-assistant` | GET | AI Assistant tab on node pages |
| `/api/ai/content-schema/{entity_type_id}/{bundle}` | GET | Content type schema (supports `?mode=form` and `?mode=data`) |

## Development

The module uses [DDEV](https://ddev.com/) with the
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) addon for local development. The module root is
the repository root -- no nested project structure needed.

### First-time setup

```bash
ddev add-on get ddev/ddev-drupal-contrib
ddev start
ddev rebuild
```

### Mistral API key

Copy the environment template and add your API key:

```bash
cp .ddev/.env.dist .ddev/.env
```

Edit `.ddev/.env` and set `DRUPAL_MISTRAL_API_KEY` to your Mistral API key, then restart:

```bash
ddev restart
```

### DDEV commands

| Command | Description |
|---------|-------------|
| `ddev rebuild` | Full rebuild: composer install, symlink, build app, install site |
| `ddev install` | Reinstall the Drupal site (fast, no composer or build) |
| `ddev build-app` | Build the React app production bundle |
| `ddev phpunit tests/src/ExistingSite/` | Run ExistingSite tests |
| `ddev phpcs` | Run PHP CodeSniffer with Drupal standards |

### Site credentials

After installation, log in at the DDEV URL with:
- **Username:** admin
- **Password:** admin

### Test module

The `oe_ai_assistant_test` module (in `tests/modules/`) provides test content types for development:

- **oe_news** -- news content type with text, date, select, inline entity, and paragraph fields
- **oe_contact** -- contact content type for inline entity reference testing
- **text_block** / **quote_block** -- paragraph types for paragraph field testing

It also configures Mistral as the default LLM provider and sets up the API key via the Key module.

### Running tests

Tests use [Drupal Test Traits](https://github.com/weitzman/drupal-test-traits) (ExistingSite pattern) and run
inside the DDEV container against a live Drupal site:

```bash
ddev phpunit tests/src/ExistingSite/
```

### React app

The React frontend lives in `app/` and produces an IIFE bundle consumed by the Drupal module. The Drupal library
definition points directly to `app/dist/`.

```bash
cd app
npm install
npm run dev          # Vite + Express mock API (standalone, no Drupal needed)
npm run build        # Production IIFE bundle -> app/dist/
npm run lint         # Biome check
npm run typecheck    # TypeScript strict
npm run test         # Vitest
npm run api:generate # Regenerate types from OpenAPI spec
```

Or build via DDEV:

```bash
ddev build-app
```

### Coding standards

```bash
ddev phpcs
ddev phpcbf    # Auto-fix violations
```
