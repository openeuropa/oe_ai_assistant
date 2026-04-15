# Wiki Log

Chronological record of wiki operations.

## [2026-04-14] ingest | Onboarding Presentation

- Created: onboarding-presentation.md, oe-ai-assistant-module.md, drafting-plugin.md, symfony-ai-agent.md,
  ui-message-stream.md, plugin-architecture.md, sse-streaming.md, cms-agnostic-frontend.md, openapi-contract.md,
  zustand-state-management.md, ddev-environment.md
- Key takeaway: bootstrapped wiki from developer onboarding deck covering full project architecture

## [2026-04-15] ingest | Drupal AI Sync -- 2026-04-15

- Created: drupal-ai-sync-april-15.md, drupal-ai-initiative.md, ai-content-review.md, drupal-tool-api.md,
  content-schema-approach.md, schema-injection-options.md
- Updated: drafting-plugin.md (added content-schema and upstream-collaboration sections, linked new pages, refreshed
  sources/updated)
- Key takeaway: OE's homegrown schema extractor has upstream alternatives (Tool API refiners, default information
  tools, pre-request events, CCC) worth evaluating before further investment

## [2026-04-15] lint

- Pages scanned: 17
- Issues found: 5 (2 fixed, 3 flagged for review)
- Fixed: added Zustand cross-reference in cms-agnostic-frontend.md and plugin-architecture.md
- Flagged: potential new pages for ChatProcessor and Context Control Center (referenced in 3+ pages); drupal.org
  issues 3575158 / 3492940 / 3542117 worth re-checking for status updates

## [2026-04-15] lint

- Pages scanned: 17
- Issues found: 5 (5 fixed, 0 need review)
- Pages updated: cms-agnostic-frontend.md, oe-ai-assistant-module.md, symfony-ai-agent.md
- Also fixed: root AGENTS.md had stale plugin dir name (OeAiAssistant -> AiEditorialAssistant) and reference to
  removed .ddev/nginx/sse-streaming.conf

## [2026-04-15] query | Context Control Center

- Filed as: context-control-center.md
- Key insight: CCC is an upstream per-request context mechanism; Marcus steered OE toward default information tools +
  events instead, but CCC remains a candidate if upstream converges on it

## [2026-04-15] query | Context Control Center (web research)

- Updated: context-control-center.md (expanded from stub to full entity page with architecture, API, issues)
- Key insight: CCC is a separate module (drupal/ai_context, beta1 released 2026-03-24), not part of drupal/ai; uses
  content entities + scope plugins + BuildSystemPromptEvent subscribers; loop_aware flag addresses OE's fixed-node
  concern
