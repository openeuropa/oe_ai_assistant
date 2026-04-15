---
title: "Onboarding Presentation"
type: source
tags: [onboarding, architecture, overview]
created: 2026-04-14
updated: 2026-04-14
---

# Onboarding Presentation

Developer onboarding deck covering the full project architecture of the OpenEuropa AI Editorial Assistant.

## Key Takeaways

- Drupal 11 module with two codebases (PHP + React) in one repo, connected by an OpenAPI 3.1 contract
- React frontend is CMS-agnostic -- runs standalone with Express mock API, no Drupal knowledge
- Plugin architecture on both sides: drafting, echo, notes
- Drafting plugin is the flagship feature -- chat-based content drafting with real-time SSE streaming
- Backend uses Symfony AI Agent (0.x) for LLM orchestration and tool-calling loop
- SSE streaming uses the Vercel AI SDK UI Message Stream Protocol v1
- Zustand store with slices for state management; server-side sync planned but not yet wired
- DDEV with ddev-drupal-contrib addon for local dev
- Symfony AI is still 0.x -- breaking changes possible, but impact is contained to six files

## Derived Pages

- [OE AI Assistant Module](oe-ai-assistant-module.md)
- [Drafting Plugin](drafting-plugin.md)
- [Symfony AI Agent](symfony-ai-agent.md)
- [UI Message Stream Protocol](ui-message-stream.md)
- [Plugin Architecture](plugin-architecture.md)
- [SSE Streaming](sse-streaming.md)
- [CMS-Agnostic Frontend](cms-agnostic-frontend.md)
- [OpenAPI Contract](openapi-contract.md)
- [Zustand State Management](zustand-state-management.md)
- [DDEV Environment](ddev-environment.md)
