/**
 * Type definitions for the content drafting plugin.
 *
 * Uses the AG-UI protocol event types for streaming agent
 * interactions. The plugin communicates with the backend via
 * our RPC-style endpoint which wraps the AG-UI controller.
 */

/** Request body for the drafting chat endpoint. */
export interface DraftingEditorialContext {
  toneId?: string;
}

export interface DraftingChatRequest {
  message: string;
  threadId?: string;
  context?: DraftingEditorialContext;
}

/** Confirmed editorial guidance saved for the drafting session. */
export interface DraftingGenerationSettings {
  toneId: string;
}

/** Request body for saving the selected tone. */
export interface DraftingSaveToneRequest {
  context: DraftingGenerationSettings;
}

/** Response body for saving the selected tone. */
export interface DraftingSaveToneResponse {
  status: "ok";
}

export interface DraftingContextOption {
  id: string;
  label: string;
  description: string;
}

export interface DraftingPluginConfig {
  entityTypeId?: string;
  bundle?: string;
  context?: {
    tone?: DraftingContextOption[];
  };
}

/** Response body for the drafting reset endpoint. */
export interface DraftingResetResponse {
  threadId: string;
}
