---
title: "Drupal AI Sync -- 2026-04-15"
type: source
tags: [slack, drupal-ai, schema, collaboration, tool-api]
created: 2026-04-15
updated: 2026-04-15
---

# Drupal AI Sync -- 2026-04-15

Slack conversation between the OE team and M.J., lead of the Drupal AI initiative. Triggered by OE's architectural
questions around content-schema extraction for the Drafting plugin.

## Key Takeaways

- OE is building its own content-schema extractor so the LLM knows entity structure. The concern is that landing pages
  with nested Paragraphs blow up the schema exponentially and not everything is relevant.
- OE's mental model: treat the LLM as another editor -- it doesn't need data-structure internals, but does need
  validation constraints upfront (which human editors learn from form widgets / form errors).
- OE's "explicit route" idea: per content-type / bundle / field configuration that (a) toggles exposure to the LLM,
  (b) declares constraints at bundle and field level, (c) attaches per-field micro-prompts (tone, good/bad examples).
- Drupal AI's Tool API has **refiners** (e.g. `FieldSetValue`): generic tools whose input schema morphs based on
  `entity_type` / `bundle` / `field`. Does not read form descriptions. Nested Paragraphs require loops plus a
  `ShortTermMemory` plugin and consume many tokens.
- **Automators** exist for chaining field generation.
- **AI Content Review** module (`drupal/ai_content_review`, issue 3575158) is landing soon as a unified module; OE
  could layer UX above it.
- **Context-aware chat** idea (issue 3542117) -- clicking a field or context to start a chat -- matches OE's
  direction; progress to be checked with the Drupal UX team.
- **ChatProcessor** abstraction (issue 3492940) decouples chatbot from agent, enabling bring-your-own-chatbot.
- Schema injection could plausibly happen via the agent's **default information tools** (pre-seeded into the system
  prompt on first loop, token-expanded with `[entity:id]`) or via the agent's **events** (each request carries a tag;
  context can be injected pre-request). **Context Control Center (CCC)** is another candidate home.
- M.J. offered to introduce OE to **A.F.** (Drupal UX) for the content-review concept, and to **M.** for toolbelt
  scope discussions.
- OE-built generic tools could land in the toolbelt; specific ones welcomed as standalone contrib.
- Upstream already has a Claude skill for generating tools:
  `ai_agents_experimental_collection/.claude/commands/generate-tool.md` on drupalcode.org.
- POC video was shared with M.J. for off-the-record forwarding to A.F. / E.H.; must not be posted publicly.

## Open Questions Raised

- Is OE's homegrown schema extractor overkill given Tool API refiners?
- Could the schema be injected via default information tools plus events rather than as a bespoke system?
- Is an "entity-aware canvas" already in the upstream pipeline? (Closest matches: AI Content Review plus
  context-aware chat -- neither is an exact fit.)

## Derived Pages

- [Drupal AI Initiative](drupal-ai-initiative.md)
- [AI Content Review Module](ai-content-review.md)
- [Drupal Tool API](drupal-tool-api.md)
- [Content Schema Approach](content-schema-approach.md)
- [Schema Injection Options](schema-injection-options.md)
- [Drafting Plugin](drafting-plugin.md) (updated)
