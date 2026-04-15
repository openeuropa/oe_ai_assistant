---
title: "Drafting Plugin"
type: entity
tags: [plugin, drafting, ai, streaming]
sources: [onboarding-presentation, drupal-ai-sync-april-15]
created: 2026-04-14
updated: 2026-04-15
---

# Drafting Plugin

Flagship plugin. ID: `drafting`. Chat-based content
drafting with real-time field streaming.

## User Flow

- Editor opens a node, clicks "AI Assistant"
- Types a prompt (e.g. "Draft a news article about X")
- Sees tool calls and assistant reply stream token by
  token via `text-delta` events
- When `draft_content` tool completes, form fields are
  populated from a `data-drafted-fields` event
- Editor reviews, edits, clicks "Save draft"

## Backend

- `DraftingPlugin.php` drives the agent loop
- `DraftingPromptBuilder` builds system prompt + schema
- `DraftContentTool` is the single tool in the toolbox
- `FormSchemaExtractor` provides content type schema
- Chat history persisted in `DrupalTempMessageStore`
  (PrivateTempStore)
- `ToolCallSucceeded` listener emits tool lifecycle
  events and `data-drafted-fields` via SSE

## Frontend

- Chat UI + SSE consumer in `app/src/plugins/drafting/`
- assistant-ui runtime adapter parses UI Message Stream
  events natively
- `data-drafted-fields` merged into plugin Zustand slice
- Artifact panel subscribes to drafted fields

## Drafted-Field Snapshots

- `draft_content` tool is the single channel for fields
- On `ToolCallSucceeded`: emits `tool-call-start`,
  `tool-call-end`, `tool-result`, then
  `data-drafted-fields` with full field map
- No word-by-word splitting -- "typing" effect comes
  from `text-delta` stream of the assistant reply

## Content Schema

- `FormSchemaExtractor` builds a per-form schema so the
  LLM sees only relevant fields.
- Constraints (max length, allowed HTML) are surfaced
  explicitly because the LLM has no form-validation
  feedback loop.
- Design rationale and upstream alternatives:
  [Content Schema Approach](content-schema-approach.md).
- Open question on injection mechanism:
  [Schema Injection Options](schema-injection-options.md).

## Upstream Collaboration

- Closest upstream match for OE's review UX:
  [AI Content Review Module](ai-content-review.md).
- Tool API / refiners may eventually replace parts of
  the homegrown schema: [Drupal Tool API](drupal-tool-api.md).
- Coordination channel and contacts:
  [Drupal AI Initiative](drupal-ai-initiative.md).

## See Also

- [Symfony AI Agent](symfony-ai-agent.md)
- [UI Message Stream Protocol](ui-message-stream.md)
- [SSE Streaming](sse-streaming.md)
- [Content Schema Approach](content-schema-approach.md)
