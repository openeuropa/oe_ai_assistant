/**
 * Type definitions for the content drafting plugin.
 *
 * Uses the AG-UI protocol event types for streaming agent
 * interactions. The plugin communicates with the backend via
 * our RPC-style endpoint which wraps the AG-UI controller.
 */

import type { components } from "@/api/schema";

export type DraftingDocumentCategory =
  components["schemas"]["DraftingDocumentCategory"];
export type DraftingDocument = components["schemas"]["DraftingDocument"];

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

/** Request body for saving a draft version (sessionId added by the helper). */
export interface DraftingSaveRequest {
  /** The draft version to save, as shown in the version rail. */
  version: number;
}

/** Response body for the drafting save endpoint. */
export interface DraftingSaveResponse {
  nodeId: string;
  previewUrl: string;
}

/** Request body for setting the selected template. */
export interface DraftingSetTemplateRequest {
  template: string;
}

/** Response body for setting the selected template. */
export interface DraftingSetTemplateResponse {
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
