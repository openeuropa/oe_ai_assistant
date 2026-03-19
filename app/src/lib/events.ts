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
};

/** Singleton event bus shared across the entire application. */
export const eventBus = mitt<AppEvents>();
