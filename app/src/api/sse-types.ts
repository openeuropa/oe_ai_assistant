/**
 * SSE event type definitions for plugin-specific streams.
 *
 * Each streaming plugin defines its own event types here. These are
 * used by SSE consumption hooks to parse incoming events with full
 * type safety. The payload shapes follow the Vercel AI SDK UI
 * Message Stream Protocol (Data Stream Protocol).
 */

// -- Common Data Stream Protocol lifecycle events --

/** Emitted at the start of a stream. */
export interface StartEvent {
  type: "start";
  messageId: string;
}

/** Emitted before each LLM turn. */
export interface StartStepEvent {
  type: "start-step";
}

/** Emitted when a text part begins. */
export interface TextStartEvent {
  type: "text-start";
  id: string;
}

/** Emitted for each text content delta. */
export interface TextDeltaEvent {
  type: "text-delta";
  textDelta: string;
}

/** Emitted when a text part ends. */
export interface TextEndEvent {
  type: "text-end";
}

/** Emitted when tool input streaming begins. */
export interface ToolInputStartEvent {
  type: "tool-input-start";
  toolCallId: string;
  toolName: string;
}

/** Emitted when tool input is fully available. */
export interface ToolInputAvailableEvent {
  type: "tool-input-available";
  toolCallId: string;
  toolName: string;
  input: Record<string, unknown>;
}

/** Emitted when tool output is available. */
export interface ToolOutputAvailableEvent {
  type: "tool-output-available";
  toolCallId: string;
  output: Record<string, unknown>;
}

/** Emitted after each LLM turn completes. */
export interface FinishStepEvent {
  type: "finish-step";
}

/** Emitted at the end of a stream. */
export interface FinishEvent {
  type: "finish";
  finishReason: string;
}

/** Emitted when an error occurs during streaming. */
export interface ErrorEvent {
  type: "error";
  errorText: string;
}

/** Union of all Data Stream Protocol lifecycle events. */
export type DataStreamLifecycleEvent =
  | StartEvent
  | StartStepEvent
  | TextStartEvent
  | TextDeltaEvent
  | TextEndEvent
  | ToolInputStartEvent
  | ToolInputAvailableEvent
  | ToolOutputAvailableEvent
  | FinishStepEvent
  | FinishEvent
  | ErrorEvent;

// -- Echo plugin custom data events (dev-only) --

/** Custom data event for the echo stream. */
export interface EchoDataEvent {
  type: "data-echo";
  data: {
    word: string;
    index: number;
    done: boolean;
  };
}

// -- Drafting plugin custom data events --

/** One observability event of the agent run. */
export interface AgentEventData {
  /** The Neuron event name, e.g. "inference-start". */
  event: string;
  /** The id of the agent that fired the event. */
  agent: string;
  /** One short line describing the event. */
  summary: string;
  /** The event data as JSON: prompts, answers, tool results. */
  payload?: unknown;
  /** "error" for a failure the editor should see, "info" otherwise. */
  level?: "info" | "error";
}

/** Transient data event carrying an agent event; never part of a message. */
export interface AgentEventEvent {
  type: "data-agent-event";
  data: AgentEventData;
  transient: true;
}

/** Union of all SSE events emitted by the drafting plugin. */
export type DraftingSSEEvent = DataStreamLifecycleEvent | AgentEventEvent;

/** Union of all SSE events emitted by the echo plugin. */
export type EchoSSEEvent = StartEvent | FinishEvent | EchoDataEvent;
