---
title: "Symfony AI Agent"
type: entity
tags: [symfony, ai, agent, llm]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# Symfony AI Agent

`symfony/ai-agent` + `symfony/ai-platform` (^0.6).
Drives the agentic tool-call loop in DraftingPlugin.

## Key Facts

- Toolbox with `DraftContentTool`
- Streaming via `symfony/ai-mistral-platform` and
  `symfony/ai-open-ai-platform`
- Agent built by `AgentFactory::createAgent()`
- Tool-call loop handled internally by Symfony AI
  `AgentProcessor`; we observe via
  `ToolCallSucceeded` listener and stream deltas

## Files Touched

Six files contain Symfony AI integration:

- `Service/AgentFactory.php` -- core agent builder
- `Plugin/AiEditorialAssistant/DraftingPlugin.php`
- `Plugin/AiEditorialAssistant/Drafting/DraftingPromptBuilder.php`
- `Tool/CompositeToolbox.php`
- `Tool/CustomSchemaToolFactory.php`
- `Store/DrupalTempMessageStore.php`

## Stability Risk

- Still 0.x -- breaking changes possible between
  minor releases
- Impact contained to the six files above
- React app never sees Symfony AI types -- only
  [UI Message Stream](ui-message-stream.md) events
  over SSE
- `drupal/ai` still used for Drupal-side plugin
  discovery; Symfony AI handles LLM orchestration

## See Also

- [Drafting Plugin](drafting-plugin.md)
- [SSE Streaming](sse-streaming.md)
