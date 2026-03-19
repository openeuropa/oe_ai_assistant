/**
 * Type definitions for the content drafting plugin.
 *
 * Uses the AG-UI protocol event types for streaming agent
 * interactions. The plugin communicates with the backend via
 * our RPC-style endpoint which wraps the AG-UI controller.
 */

/** Request body for the drafting chat endpoint. */
export interface DraftingChatRequest {
  message: string;
  threadId?: string;
}

/** Response body for the drafting reset endpoint. */
export interface DraftingResetResponse {
  threadId: string;
}
