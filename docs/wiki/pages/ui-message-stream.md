---
title: "UI Message Stream Protocol"
type: entity
tags: [protocol, streaming, vercel, sse]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# UI Message Stream Protocol

Vercel AI SDK UI Message Stream Protocol v1. The SSE event format used between backend and frontend.

## Key Facts

- Backend advertises via response header: `x-vercel-ai-ui-message-stream: v1`
- Frontend (assistant-ui) parses events natively
- Emitted from PHP via Symfony `ServerEvent` objects yielded into an `EventStreamResponse`

## Event Types

| Event                  | Purpose                         |
|------------------------|---------------------------------|
| `start`               | Stream begins (messageId)       |
| `start-step`          | New agent step begins           |
| `text-start`          | Opens text segment (with id)    |
| `text-delta`          | Streamed text token             |
| `text-end`            | Closes current text segment     |
| `tool-call-start`     | Tool call starting              |
| `tool-call-delta`     | Tool arguments streaming        |
| `tool-call-end`       | Tool call complete              |
| `tool-result`         | Raw tool result payload         |
| `data-drafted-fields` | Drafted-field snapshot (custom) |
| `finish-step`         | Agent step complete             |
| `finish`              | Stream complete                 |
| `error`               | Emitted on failure              |
| `[DONE]`              | Terminator frame                |

## Notes

- `data-drafted-fields` is a custom event type not in the Vercel spec -- carries the full drafted field map
- The "typing" effect comes from `text-delta` events, not from field-level deltas

## See Also

- [Drafting Plugin](drafting-plugin.md)
- [SSE Streaming](sse-streaming.md)
