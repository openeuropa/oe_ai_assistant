---
title: "OE AI Assistant Module"
type: entity
tags: [drupal, module, architecture]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# OE AI Assistant Module

Drupal 11 module (`oe_ai_assistant`) that embeds an AI-powered editorial assistant into the CMS back-office.

## Key Facts

- Repo root is the module root
- Two codebases in one repo:
  - PHP backend in `src/` (Drupal 11, PHP 8.3+)
  - React frontend in `app/` (React 19, TypeScript)
- Plugin-based REST API with SSE streaming
- AI calls happen server-side only -- never in browser
- Nothing saved without explicit editorial action
- Patches to contrib modules managed via `cweagans/composer-patches` in `patches/`

## Backend Structure

- `src/Annotation/` -- plugin annotations
- `src/Controller/` -- route controllers (PluginController dispatches to plugins)
- `src/Plugin/AiEditorialAssistant/` -- backend plugins
- `src/Service/` -- schema extraction, field mapping, agent factory, request validation
- `src/Store/` -- DrupalTempMessageStore (chat history)
- `src/Tool/` -- CompositeToolbox, CustomSchemaToolFactory

## See Also

- [Drafting Plugin](drafting-plugin.md)
- [Symfony AI Agent](symfony-ai-agent.md)
- [Plugin Architecture](plugin-architecture.md)
- [OpenAPI Contract](openapi-contract.md)
- [SSE Streaming](sse-streaming.md)
- [DDEV Environment](ddev-environment.md)
