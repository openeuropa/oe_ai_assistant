---
title: "Drupal AI Initiative"
type: entity
tags: [drupal-ai, upstream, collaboration]
sources: [drupal-ai-sync-april-15]
created: 2026-04-15
updated: 2026-04-15
---

# Drupal AI Initiative

Umbrella effort in the Drupal community to provide
AI-powered capabilities as contrib modules. Led by
M.J.; tracked on drupal.org.

## Relevant Upstream Pieces

| Piece                      | Status / Issue             | Relevance to OE                                    |
|----------------------------|----------------------------|----------------------------------------------------|
| AI Content Review module   | drupal/ai_content_review,  | Unified review UX; OE could layer its own UI on top |
|                            | issue 3575158 (landing)    |                                                    |
| Context-aware chat         | issue 3542117              | Click a field/context to chat -- aligned with OE    |
| ChatProcessor abstraction  | issue 3492940              | Bring-your-own-chatbot; decouples chatbot + agent   |
| Tool API refiners          | in core AI tooling         | Generic tools (e.g. `FieldSetValue`) with dynamic   |
|                            |                            | input schema per entity/bundle/field                |
| Automators                 | existing                   | Chain field generation                             |
| Context Control Center (CCC) | existing                 | Possible home for per-request context injection    |

## Key Contacts

- **M.J.** -- initiative lead
- **A.F.** -- UX, owns the content-review concept
- **E.H.** -- UX
- **M.** -- toolbelt scope owner

## Claude Skill for Tool Generation

Upstream provides a Claude skill for scaffolding tools:

    ai_agents_experimental_collection/.claude/commands/
      generate-tool.md

Hosted on drupalcode.org. Worth cross-referencing if we
start contributing tools.

## Collaboration Signals (2026-04-15)

- OE should get in touch with A.F. for the review
  concept before finalizing our own review UX.
- Generic OE tools could land in the toolbelt;
  OE-specific ones are welcome as standalone contrib.
- Coordination preferred before we reinvent upstream
  pieces.

## See Also

- [AI Content Review Module](ai-content-review.md)
- [Drupal Tool API](drupal-tool-api.md)
- [Content Schema Approach](content-schema-approach.md)
