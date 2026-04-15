---
title: "SSE Streaming"
type: concept
tags: [sse, streaming, infrastructure]
sources: [onboarding-presentation]
created: 2026-04-14
updated: 2026-04-14
---

# SSE Streaming

Server-Sent Events via Symfony `EventStreamResponse`.

## How It Works

- Plugins return an `EventStreamResponse` created by `AiAssistantPluginBase::createSseResponse()`
- Response tagged with `x-vercel-ai-ui-message-stream: v1`
- `createSseResponse()` disables `zlib.output_compression`, enables `implicit_flush`, disables Apache `mod_deflate`
- `DraftingPlugin::chat()` calls `set_time_limit(0)` so long streams are not killed by PHP timeout
- Symfony `ServerEvent` objects yielded into the response; `EventStreamResponse` drives iteration and flushes each
  event

## Operational Notes

- Each SSE connection holds a PHP-FPM worker for the stream duration -- fine for back-office, not for public traffic
- Authentication is cookie-based, inherited from Drupal session
- Old DDEV nginx buffering workaround has been removed -- `EventStreamResponse` handles flushing natively

## See Also

- [UI Message Stream Protocol](ui-message-stream.md)
- [Drafting Plugin](drafting-plugin.md)
- [Symfony AI Agent](symfony-ai-agent.md)
