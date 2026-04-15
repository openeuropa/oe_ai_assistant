---
title: "Drupal Tool API"
type: entity
tags: [drupal-ai, tool-api, toolbelt, refiners]
sources: [drupal-ai-sync-april-15]
created: 2026-04-15
updated: 2026-04-15
---

# Drupal Tool API

Upstream API for exposing callable tools to the Drupal
AI agent. Hosts the "toolbelt" -- a library of shared,
generic tools -- and the "refiner" mechanism for
dynamic tool schemas.

## Refiners

- A refiner lets a tool change its input schema based
  on earlier inputs.
- Example: `FieldSetValue` -- once the agent picks
  `entity_type: node`, `bundle: article`,
  `field: body`, the `value` parameter reshapes to the
  specifics of a Formatted Textarea.
- Limitation: does **not** read form descriptions, so
  human-facing hints are not propagated.
- Paragraphs caveat: nested entities require multi-step
  loops and benefit from a `ShortTermMemory` plugin
  for context preservation. Token-hungry.

## Automators

- Chain field generation -- ruled out early by OE but
  still part of the Tool API surface.

## Toolbelt

- Shared library of generic tools.
- Scope owner: M. (intro available via M.J.).
- Contribution guidance (2026-04-15): generic tools
  are welcome in the toolbelt; OE-specific tools
  should be contributed as standalone contrib.

## Tool Generation Skill

Upstream Claude skill for scaffolding tools:

    ai_agents_experimental_collection/.claude/commands/
      generate-tool.md

(Hosted on drupalcode.org.) Useful if we start
contributing tools.

## Open Question for OE

- Is our homegrown schema extractor overkill given that
  refiners already expose per-field structure? Captured
  in [Schema Injection Options](schema-injection-options.md).

## See Also

- [Drupal AI Initiative](drupal-ai-initiative.md)
- [Content Schema Approach](content-schema-approach.md)
- [Schema Injection Options](schema-injection-options.md)
