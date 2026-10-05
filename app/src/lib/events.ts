/**
 * Typed event bus for inter-plugin communication.
 *
 * Plugins use this to broadcast fire-and-forget events without importing
 * each other directly. For example, a drafting plugin can emit
 * "notification:show" and the shell will display it.
 *
 * Built on mitt (~200 bytes), which provides a simple typed emitter.
 * Add new event types to AppEvents as the plugin catalog grows.
 */

import mitt from "mitt";

/** Map of event names to their payload types. */
export type AppEvents = {
  "notification:show": {
    type: "info" | "success" | "warning" | "error";
    message: string;
  };
  "notification:clear": undefined;
  /**
   * The editor answered a tool call that was waiting for a decision.
   *
   * A tool UI renders the buttons but cannot reach the thread runtime, which
   * is why the decision travels to the component that owns it.
   */
  /**
   * A turn ended by asking the editor to decide on a tool call.
   *
   * Neuron writes the call to the conversation and then suspends without
   * streaming it, so the thread has to be read back for the question to
   * appear. The component holding the runtime does that.
   */
  "approval:requested": undefined;
  "approval:decide": {
    callId: string;
    decision: "approve" | "reject";
    reason?: string;
  };
};

/** Singleton event bus shared across the entire application. */
export const eventBus = mitt<AppEvents>();
