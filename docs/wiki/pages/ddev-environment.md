---
title: "DDEV Environment"
type: entity
tags: [ddev, development, environment]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# DDEV Environment

DDEV with the ddev-drupal-contrib addon. Module root
equals repo root.

## Setup

```bash
ddev start
ddev rebuild        # composer, symlink, build app, install
cp .ddev/.env.dist .ddev/.env  # set DRUPAL_MISTRAL_API_KEY
ddev restart
```

## Credentials

- URL: DDEV project URL
- User: `admin`
- Pass: `admin`

## Common Commands (Backend)

| Command                                | Purpose             |
|----------------------------------------|---------------------|
| `ddev rebuild`                         | Full rebuild        |
| `ddev install`                         | Reinstall (fast)    |
| `ddev build-app`                       | Build React bundle  |
| `ddev phpunit tests/src/ExistingSite/` | Backend tests       |
| `ddev phpcs`                           | CodeSniffer check   |
| `ddev phpcbf`                          | Auto-fix PHPCS      |

## Common Commands (Frontend)

Run from `app/`:

| Command              | Purpose                        |
|----------------------|--------------------------------|
| `npm run dev`        | Vite + Express mock API        |
| `npm run build`      | IIFE bundle to `app/dist/`     |
| `npm run lint`       | Biome check                    |
| `npm run typecheck`  | TypeScript strict check        |
| `npm run test:e2e`   | Playwright E2E tests           |
| `npm run api:generate` | Regen types from OpenAPI     |

## After PHP/Drupal Changes

```bash
docker exec ddev-oe-ai-assistant-web drush cr
```

## See Also

- [OE AI Assistant Module](oe-ai-assistant-module.md)
