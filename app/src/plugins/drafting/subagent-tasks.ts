/**
 * Pure logic for the background subagent tasks the editor's tab follows.
 *
 * The tasks endpoint reports the ledger of the session. These helpers decide
 * how fast to ask again, when the tab starts the follow-up turn itself, and
 * when the thread must be reloaded from the server. The hook that polls
 * holds the timers and the runtime; nothing here touches either.
 */

import type { components } from "@/api/schema";

/** One task of the session ledger, as the tasks endpoint reports it. */
export type SubagentTask = components["schemas"]["DraftingTask"];

/** The tasks endpoint response. */
export type SubagentTasksResponse =
  components["schemas"]["DraftingTasksResponse"];

/** Delay between polls while a task is in flight or results wait. */
export const ACTIVE_POLL_DELAY_MS = 3000;

/** Delay between polls while nothing is in flight. */
export const IDLE_POLL_DELAY_MS = 20000;

/**
 * Tells whether some background task is still on its way to the thread.
 *
 * A task is on its way while it is queued or running, or while its result
 * waits for delivery or is being claimed by a delivery turn.
 */
export function isActive(response: SubagentTasksResponse): boolean {
  return response.tasks.some(
    (task) =>
      (task.mode === "background" &&
        (task.status === "pending" || task.status === "running")) ||
      task.deliveryState === "waiting" ||
      task.deliveryState === "claimed",
  );
}

/** Returns how long to wait before the next poll. */
export function pollDelay(response: SubagentTasksResponse): number {
  return isActive(response) || response.deliverable
    ? ACTIVE_POLL_DELAY_MS
    : IDLE_POLL_DELAY_MS;
}

/** What the tab knows about its own runs when a poll answers. */
export interface DeliveryState {
  /** Whether a turn of this thread is streaming. */
  threadRunning: boolean;
  /** Whether this tab already started a delivery turn that has not ended. */
  delivering: boolean;
}

/** Tells whether this tab starts the delivery turn now. */
export function shouldDeliver(
  response: SubagentTasksResponse,
  state: DeliveryState,
): boolean {
  return (
    response.deliverable &&
    !response.busy &&
    !state.threadRunning &&
    !state.delivering
  );
}

/**
 * Builds the text of the chip spliced in before a live delivery.
 *
 * The endpoint lists the newest task first, so the summary reads the tasks
 * in reverse, which is the order they finished in. A subagent and status
 * pair that repeats is named once.
 */
export function noticeSummary(
  tasks: Pick<SubagentTask, "subagent" | "status">[],
): string {
  const parts: string[] = [];
  for (const task of [...tasks].reverse()) {
    const part = `${task.subagent} ${task.status.replace(/_/g, " ")}`;
    if (!parts.includes(part)) {
      parts.push(part);
    }
  }
  return parts.length === 0
    ? "Background results arrived."
    : `Background results arrived: ${parts.join(", ")}.`;
}

/** What the tab knows about its own runs since the previous poll. */
export interface ReloadState {
  /** Whether a turn of this thread is streaming. */
  threadRunning: boolean;
  /** Whether this tab ran a turn since the previous poll answered. */
  localRunSinceLastPoll: boolean;
}

/** The outcome of comparing the thread revision with the last one seen. */
export interface ReloadDecision {
  /** Whether the thread must be reloaded from the server. */
  reload: boolean;
  /** The revision to remember for the next comparison. */
  revision: string;
}

/**
 * Decides whether the thread changed behind this tab's back.
 *
 * Rows this tab wrote itself are already on screen, and a streaming thread
 * must not be replaced, so both adopt the new revision without a reload. The
 * first poll has no revision to compare and adopts as well.
 */
export function nextReloadDecision(
  prevRevision: string | null,
  response: SubagentTasksResponse,
  state: ReloadState,
): ReloadDecision {
  const changed = prevRevision !== null && prevRevision !== response.revision;
  return {
    reload: changed && !state.threadRunning && !state.localRunSinceLastPoll,
    revision: response.revision,
  };
}
