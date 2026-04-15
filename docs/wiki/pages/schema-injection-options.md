---
title: "Schema Injection Options"
type: investigation
tags: [schema, agent, system-prompt, drupal-ai]
sources: [drupal-ai-sync-april-15]
created: 2026-04-15
updated: 2026-04-15
---

# Schema Injection Options

Where and how to put the content schema so the agent
sees it on the first turn of a drafting session.

## Context

- OE currently extracts a schema itself and inlines it
  into the system prompt via `DraftingPromptBuilder`.
- M.J. suggested upstream primitives that may
  replace or complement this. See
  [content-schema-approach.md](content-schema-approach.md).

## Options

### 1. Default Information Tools (upstream)

- Pre-seeded tools that appear in the system prompt on
  the first agent loop.
- Support Drupal tokens in arguments
  (e.g. `nid = [entity:id]`), so the agent starts with
  node-specific context already bound.
- Would need a hook to pass custom context from the
  chatbot to the agent.
- Natural fit because the drafting agent runs against a
  fixed node and therefore a fixed node type.

### 2. Pre-Request Events (upstream)

- Each agent request carries a tag.
- Subscribers can match the tag and inject context
  (schema) before the request goes out.
- Example: pre-request event hook from ai 1.3.x docs.

### 3. Context Control Center (CCC)

- Upstream mechanism for per-request context.
- Open: is the schema dynamically fetched per request,
  or set once at session start? OE side is the latter
  (one fixed node per session), which makes CCC
  feasible.

### 4. Status Quo: Homegrown Schema in System Prompt

- Works today via `FormSchemaExtractor` +
  `DraftingPromptBuilder`.
- Pros: full control, no upstream coupling.
- Cons: duplicates effort if upstream converges on the
  same problem; text-format constraints require
  additional wiring.

## Open Questions

- Is our homegrown extractor overkill now that Tool API
  refiners exist?
- Can default information tools plus pre-request events
  fully replace the bespoke schema, or are they only
  partial solutions?
- Would migrating to upstream primitives simplify the
  "explicit route" per-field config, or make it harder
  to attach?

## Next Steps

- Sync with A.F. on the review concept.
- Sync with M. on toolbelt scope before
  contributing anything schema-related.
- Prototype schema injection via default information
  tools on a branch to compare token cost and quality
  vs status quo.

## See Also

- [Content Schema Approach](content-schema-approach.md)
- [Drupal Tool API](drupal-tool-api.md)
- [Drupal AI Initiative](drupal-ai-initiative.md)
