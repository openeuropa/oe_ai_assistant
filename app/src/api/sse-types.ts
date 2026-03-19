/**
 * SSE event type definitions for plugin-specific streams.
 *
 * Each streaming plugin defines its own event types here. These are
 * used by SSE consumption hooks to parse incoming events with full
 * type safety. The payload shapes match the schemas in the OpenAPI spec.
 */

import type { components } from "./schema";

// -- Echo plugin SSE events (dev-only) --

/** SSE event payload for the echo stream. */
export type EchoSSEEvent = components["schemas"]["EchoStreamEvent"];
