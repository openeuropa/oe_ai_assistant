---
title: "Content Schema Approach"
type: concept
tags: [schema, drafting, llm, architecture]
sources: [drupal-ai-sync-april-15]
created: 2026-04-15
updated: 2026-04-15
---

# Content Schema Approach

How the Drafting plugin teaches the LLM about the shape and constraints of Drupal entities it is drafting.

## Mental Model

Treat the LLM as another editor:

- It does **not** need data-structure internals.
- It **does** need validation constraints upfront (human editors get them from form widgets and form errors; the LLM
  has no equivalent feedback loop).

## OE Approach: Explicit Route

Per content-type / bundle / field configuration that:

- Toggles whether a field is exposed to the LLM at all.
- Declares constraints at bundle level and field level (max length, allowed HTML, required, etc.).
- Attaches per-field micro-prompts -- desired tone, good/bad examples -- passed when asking the LLM to generate
  content for that field.

Rationale: on a landing page with nested Paragraphs, the full auto-generated schema balloons exponentially and much
of it is irrelevant. Explicit configuration keeps the prompt focused and lets editors tune quality without touching
code.

## Alternatives Considered

| Alternative              | What it does                          | Verdict for OE                                |
|--------------------------|---------------------------------------|-----------------------------------------------|
| Tool API refiners        | Schema morphs per entity/bundle/field | No form descriptions; Paragraphs need loops   |
| Automators               | Chain field generation                | Ruled out early -- wrong UX shape             |
| Agent default info tools | Pre-seeded system-prompt tools        | Candidate; see [schema-injection-options.md]  |
| Agent events             | Inject context pre-request via tags   | Candidate injection mechanism                 |
| Context Control Center   | Upstream per-request context          | Possible future home if OE converges upstream |

[schema-injection-options.md]: schema-injection-options.md

## Current State

- OE has a working schema extractor driven from the entity **form** (not the entity type) to scope the field set
  naturally.
- Text-format constraints (allowed HTML) still need propagation from configs outside the form.
- Open question (see [schema-injection-options.md](schema-injection-options.md)): keep homegrown, or migrate to
  upstream primitives.

## See Also

- [Drafting Plugin](drafting-plugin.md)
- [Drupal Tool API](drupal-tool-api.md)
- [Schema Injection Options](schema-injection-options.md)
