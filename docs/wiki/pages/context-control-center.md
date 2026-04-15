---
title: "Context Control Center (CCC)"
type: entity
tags: [drupal-ai, context, schema-injection, upstream, ai-context]
sources: [drupal-ai-sync-april-15]
created: 2026-04-15
updated: 2026-04-15
---

# Context Control Center (CCC)

Separate Drupal module (`drupal/ai_context`) for managing structured context -- tone rules, domain knowledge,
guardrails, brand guidelines -- and injecting it into every AI interaction site-wide. Not a component inside
`drupal/ai`; it is its own project.

- Project: `drupal/ai_context` (drupal.org/project/ai_context)
- Current release: 1.0.0-beta1 (released 2026-03-24 at DrupalCon Chicago)
- Requires: Drupal 10.5+ or 11.2+
- Maintainers: Kristen Pol, Ahmed Jabar, Kurt Foster (Salsa Digital)
- No stable release with security coverage yet

## Architecture

### Context Items (Content Entities)

Each context item is a content entity (`AiContextItem`) with:

- Markdown content body
- Revision support, drafts, moderation workflow
- Scheduling (publish/unpublish dates)
- Multilingual support
- Subcontext hierarchy (one-level parent/child; children are either "required" -- always included -- or
  "conditional" -- LLM determines relevance)

### Context Scope Plugin System

Context items are categorized via an extensible scope plugin system (`AiContextScope` attribute, with interface, base
class, and plugin manager). Built-in scope plugins:

| Scope Plugin      | What it does                                               |
|-------------------|------------------------------------------------------------|
| **Global**        | Applied universally to any agent                           |
| **Use Case**      | Predefined scenarios (e.g. "Writing Words", "Canvas")      |
| **Language**      | Target-specific language contexts                          |
| **Tags**          | Custom vocabulary-based categorization                     |
| **Site Section**  | Path-based matching (URL alias resolution)                 |
| **Target Entity** | Bind context to specific nodes/Canvas pages                |

Custom scope plugins can be added by contrib/custom modules.

### Context Selection Pipeline

1. **`AiContextRequestFactory`** -- creates context requests with two modes:
   - `fromAgent()`: `SELECTION_MODE_MINIMAL` -- requires explicit scope subscriptions in config
   - `fromParameters()`: `SELECTION_MODE_MATCH_ALL` -- includes all items passing scope filters (for non-agent
     consumers like CKEditor)
2. **`AiContextSelector::select()`** -- finds matching context items based on the request
3. Rendered context text is appended to the system prompt via `$event->setSystemPrompt()`

### Agent Integration (Event Subscribers)

CCC integrates with agents through event subscribers, not by being baked into the agent framework:

- **`SystemPromptSubscriber`** -- subscribes to `BuildSystemPromptEvent` (from `ai_agents`). Fires on each agent
  loop iteration, runs the context selection pipeline, and appends matched context to the system prompt.
- **`AiContextCKEditorSubscriber`** -- for non-agent consumers. Subscribes to `ai_ckeditor.pre_request`, uses
  `fromParameters()` with `SELECTION_MODE_MATCH_ALL`.

### Agent Context Configuration

Per-agent context is configured via `ai_context.agents` YAML config:

- **Scope subscriptions** -- which scopes an agent opts into
- **Always include / Never include rules** -- hard overrides
- **Entity targeting** -- bind context to specific content entities
- **Item and token limits** -- cost control (max items, max tokens)
- **`loop_aware` boolean** -- when true, context is injected only on loop 0 to avoid re-injecting identical context
  on every agent loop iteration (measured 52% token reduction)

## Static vs Per-Request Context

CCC is per-request by design -- `SystemPromptSubscriber` fires on every `BuildSystemPromptEvent`. However:

- Context items themselves are static (they don't change between loops)
- The `loop_aware` optimization allows skipping injection after loop 0 for multi-loop agents
- "Always include" items are effectively static/session-level context
- Scope-based items can vary per page/path/entity but not mid-conversation

For OE's use case (fixed node type per session), CCC would work -- context would be selected once on the first loop
and skipped on subsequent loops via `loop_aware`.

## Relationship to Other Context Mechanisms

These are three separate mechanisms for injecting context into AI agents:

| Mechanism                  | Module             | How it works                                           |
|----------------------------|--------------------|--------------------------------------------------------|
| Context Control Center     | `drupal/ai_context`| Content entities + scope plugins + event subscribers   |
| Default information tools  | `drupal/ai_agents` | Pre-seeded tools in YAML, run on loop 0, Drupal tokens |
| Pre-request events         | `drupal/ai`        | `PreGenerateResponseEvent` before every provider call  |

CCC uses `BuildSystemPromptEvent` (from `ai_agents`) to inject into agent system prompts. For non-agent consumers
it uses `PreGenerateResponseEvent` metadata as a fallback.

## Key API Surface

```
AiContextRequestFactory    -- Creates context requests
  ::fromAgent()             -- For agent consumers (SELECTION_MODE_MINIMAL)
  ::fromParameters()        -- For any consumer (SELECTION_MODE_MATCH_ALL)
AiContextSelector           -- Runs context selection
  ::select()                -- Returns matching context items
AiContextUsageTracker       -- Tracks usage per context/agent/route

SystemPromptSubscriber      -- Subscribes to BuildSystemPromptEvent
AiContextCKEditorSubscriber -- Subscribes to ai_ckeditor.pre_request
GetAiRelevantContext        -- Function-call plugin exposing context to LLM tool calls
AiContextItem               -- The context content entity
```

## Key Drupal.org Issues

| Issue      | Topic                                                      |
|------------|------------------------------------------------------------|
| #3558814   | AI Context 1.0 roadmap for Drupal CMS 2.0                 |
| #3567798   | [META] CCC MVP 1.0 roadmap                                |
| #3577644   | CCC beta1 release planning                                |
| #3574359   | Refactor context selection logic (decoupling from agents)  |
| #3574910   | Harden GetAiRelevantContext plugin                         |
| #3582288   | SystemPromptSubscriber re-injects context on every loop    |
| #3556909   | Decouple AI Context from AI Agents                         |
| #3581952   | Add event hook to ai_ckeditor for context injection        |

## Relevance to OE

- OE currently uses `drupal/ai: ^1.3` and does **not** depend on `drupal/ai_context`.
- Marcus steered the April 15 conversation toward default information tools + pre-request events rather than CCC.
- However, CCC is now substantially more mature (beta1 released) and could be revisited if upstream converges on it
  as the standard context injection mechanism.
- CCC's scope plugin system (especially `TargetEntity` and `UseCase`) could map well to OE's per-content-type schema
  injection if we wanted to externalize schema as context items rather than building them programmatically.
- The `loop_aware` optimization addresses OE's concern about fixed-node-per-session context.

## See Also

- [Schema Injection Options](schema-injection-options.md)
- [Content Schema Approach](content-schema-approach.md)
- [Drupal AI Initiative](drupal-ai-initiative.md)
- [Drupal Tool API](drupal-tool-api.md)
