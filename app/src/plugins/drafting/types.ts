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
export type DraftingDocumentStatus =
  components["schemas"]["DraftingDocumentStatus"];

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

/**
 * Request body for answering a waiting tool call.
 *
 * The sessionId is added by the helper.
 */
export interface DraftingSubmitApprovalRequest {
  /** The tool call being answered. */
  callId: string;
  /** The editor's decision. */
  decision: "approve" | "reject";
  /** Why the call was rejected, which reaches the model. */
  reason?: string;
}

/** What the save_draft tool answers with, as the thread carries it. */
export interface SaveDraftResult {
  /** The draft version that was written. */
  version?: number;
  /** The name of that draft, such as "Draft 2.0". */
  name?: string;
  /** The node the save wrote. */
  nodeId?: string;
  /** Where to preview that node. */
  previewUrl?: string;
  /** Why the save did not happen. */
  error?: string;
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
  /** Documents panel gate; the list itself is fetched after boot. */
  documents?: { enabled?: boolean };
  /**
   * Live preview pane. The url is a template with {sessionId} and
   * {versionId} placeholders, resolved before loading the iframe.
   */
  preview?: { url?: string };
}

/** Response body for the drafting reset endpoint. */
export interface DraftingResetResponse {
  status: string;
}
