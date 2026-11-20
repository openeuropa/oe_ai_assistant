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

/**
 * Delay between polls while nothing is in flight.
 *
 * It is tied to SubagentPresence::TTL on the server (45 s): the presence mark
 * of an idle tab must outlast two idle polls, so keep the TTL at least twice
 * this delay.
 */
export const IDLE_POLL_DELAY_MS = 20000;

/** Delay before the poll that follows the end of a run of this tab. */
export const AFTER_RUN_POLL_DELAY_MS = 1000;

/** Wait before the next delivery after the first failed one. */
export const DELIVERY_BACKOFF_BASE_MS = 5000;

/** Longest wait between two delivery attempts. */
export const DELIVERY_BACKOFF_MAX_MS = 60000;

/**
 * Returns how long to wait before the next delivery attempt.
 *
 * @param failures The number of deliveries that failed in a row.
 */
export function deliveryBackoff(failures: number): number {
  if (failures <= 0) {
    return 0;
  }
  return Math.min(
    DELIVERY_BACKOFF_BASE_MS * 2 ** (failures - 1),
    DELIVERY_BACKOFF_MAX_MS,
  );
}

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

/** What the tab knows about its own deliveries when a poll answers. */
export interface ReloadState {
  /** Whether a turn of this thread is streaming. */
  threadRunning: boolean;
  /** The ids of the tasks this tab delivered itself. */
  ownDeliveries: ReadonlySet<string>;
}

/** The outcome of comparing the delivered tasks with the ones seen before. */
export interface ReloadDecision {
  /** Whether the thread must be reloaded from the server. */
  reload: boolean;
  /** The ids of the delivered tasks to remember for the next comparison. */
  delivered: ReadonlySet<string>;
}

/** Returns the ids of the tasks whose result reached the conversation. */
export function deliveredIds(response: SubagentTasksResponse): Set<string> {
  return new Set(
    response.tasks
      .filter((task) => task.deliveryState === "delivered")
      .map((task) => task.taskId),
  );
}

/**
 * Decides whether someone else delivered into the thread behind this tab.
 *
 * A task that newly shows as delivered and is not one this tab delivered
 * itself reached the thread through the worker or another tab, so the tab
 * does not have its rows. The first poll has nothing to compare and only
 * sets the baseline. A streaming thread must not be replaced, so while it
 * runs the baseline stays where it was and the next poll decides again.
 */
export function nextReloadDecision(
  previous: ReadonlySet<string> | null,
  response: SubagentTasksResponse,
  state: ReloadState,
): ReloadDecision {
  const delivered = deliveredIds(response);
  if (previous === null) {
    return { reload: false, delivered };
  }
  if (state.threadRunning) {
    return { reload: false, delivered: previous };
  }
  const foreign = [...delivered].some(
    (id) => !previous.has(id) && !state.ownDeliveries.has(id),
  );
  return { reload: foreign, delivered };
}
