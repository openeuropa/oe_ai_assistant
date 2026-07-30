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
  /** The editorial session that hosts the conversation. */
  sessionId: string;
}

/** Confirmed editorial guidance saved for the drafting session. */
export interface DraftingGenerationSettings {
  toneId: string;
}

/** Request body for setting the selected tone. */
export interface DraftingSetToneRequest {
  toneId: string;
}

/** Response body for setting the selected tone. */
export interface DraftingSetToneResponse {
  status: "ok";
}

/** A selectable option (tone, template, ...) provided by the host config. */
export interface DraftingSelectOption {
  id: string;
  label: string;
  description: string;
}

/** A composer panel gated by the host, with its selectable options. */
export interface DraftingSelectPanelConfig {
  /** Whether the panel's tab is shown. */
  enabled?: boolean;
  options?: DraftingSelectOption[];
  /** The option id currently saved on the server (for rehydration). */
  selected?: string;
}

/** A reference document that can ground the next draft. */
export interface DraftingDocument {
  id: string;
  title: string;
  /** Short descriptor, e.g. "PDF - 240 KB". */
  meta: string;
}

export interface DraftingPluginConfig {
  entityTypeId?: string;
  bundle?: string;
  /** Tone panel: gate + available tones. */
  tone?: DraftingSelectPanelConfig;
  /** Template panel: gate + available templates. */
  templates?: DraftingSelectPanelConfig;
  /** Documents panel: gate + documents attached from the server. */
  documents?: { enabled?: boolean; options?: DraftingDocument[] };
}

/** Response body for the drafting reset endpoint. */
export interface DraftingResetResponse {
  status: string;
}
