/**
 * Pure logic for how a subagent card reads the state of its task.
 *
 * A card has the result its call settled with, the live run of this tab, the
 * ledger state the transcript joined on reload, and the ledger state the
 * latest poll reported. These helpers pick between them and name what the
 * card says.
 */

import type { SubagentCallResult, SubagentLive } from "./subagent-result";
import type { SubagentTask } from "./subagent-tasks";

/** What the badge in the header of a card stands for. */
export type BadgeKind =
  | "background"
  | "running"
  | "completed"
  | "delivered"
  | "needs_input"
  | "answered"
  | "stopped"
  | "failed";

/**
 * Picks the ledger state a card follows.
 *
 * The task of the latest poll wins, because the tab is open and it is newer
 * than what the transcript joined when it loaded.
 */
export function effectiveLive(
  live: SubagentLive | null,
  task: SubagentTask | undefined,
): SubagentLive | null {
  return task
    ? { status: task.status, deliveryState: task.deliveryState }
    : live;
}

/** Tells whether the editor's answer to a question moved the task on. */
export function isAnswered(
  result: SubagentCallResult | null,
  task: SubagentTask | undefined,
): boolean {
  return (
    result?.status === "needs_input" &&
    task !== undefined &&
    (task.status !== "needs_input" || task.continuations > 0)
  );
}

/** Tells whether the ledger holds the task as lost or ended in failure. */
function isFailed(status: string): boolean {
  return status === "failed";
}

/**
 * Tells whether a task can still be stopped from its card.
 *
 * Only a background task that is queued or running can be: a foreground one
 * runs inside the turn of the editor.
 */
export function isStoppable(
  live: SubagentLive | null,
  task: SubagentTask | undefined,
): boolean {
  return (
    live !== null &&
    (live.status === "pending" || live.status === "running") &&
    task?.mode !== "foreground"
  );
}

/** Maps a ledger state to the badge of a task that ran in the background. */
function badgeOfLive(live: SubagentLive): BadgeKind {
  if (live.status === "pending" || live.status === "running") {
    return "background";
  }
  if (live.status === "stopped") return "stopped";
  if (isFailed(live.status)) return "failed";
  if (live.status === "needs_input") return "needs_input";
  return live.deliveryState === "delivered" || live.deliveryState === "consumed"
    ? "delivered"
    : "completed";
}

/** Maps the status of a foreground result, or of a run, to a badge. */
function badgeOfStatus(status: string): BadgeKind {
  switch (status) {
    case "completed":
      return "completed";
    case "needs_input":
      return "needs_input";
    case "stopped":
      return "stopped";
    case "failed":
      return "failed";
    case "queued":
    case "pending":
      return "background";
    default:
      return "running";
  }
}

/** What a card knows when it picks its badge. */
export interface BadgeInput {
  result: SubagentCallResult | null;
  /** The status the live run of this tab reported, if it has one. */
  runStatus?: string | undefined;
  /** The ledger state the card follows. */
  live: SubagentLive | null;
  /** Whether the editor's answer moved a question of the task on. */
  answered: boolean;
  /** Whether the call ended with nothing to show or with an error. */
  abandoned: boolean;
}

/**
 * Picks the badge of a card.
 *
 * "Background" is only for a task still queued or running. After that the
 * badge says how it ended: Completed, Delivered or Failed.
 */
export function badgeKind(input: BadgeInput): BadgeKind {
  const { result, runStatus, live, answered, abandoned } = input;
  if (answered) return "answered";
  if (abandoned) return "failed";

  if (result === null) {
    return runStatus === undefined ? "running" : badgeOfStatus(runStatus);
  }
  if (result.status === "pending" || result.status === "running") {
    if (live !== null) return badgeOfLive(live);
    return runStatus !== undefined && isFailed(runStatus)
      ? "failed"
      : "background";
  }
  return badgeOfStatus(result.status);
}

/**
 * Says where a background call stands, in the words of its card.
 *
 * @param live The ledger state, or null when none is known.
 * @param working What the task is doing while it runs, such as "Drafting".
 */
export function pendingMessage(
  live: SubagentLive | null,
  working: string,
): string {
  const running = `${working} in the background…`;
  if (live === null) return running;
  if (live.status === "stopped") return "Stopped";
  if (isFailed(live.status)) return "Failed";
  if (live.status === "completed" || live.status === "needs_input") {
    return live.deliveryState === "delivered" ||
      live.deliveryState === "consumed"
      ? "Done, results delivered"
      : "Done, waiting to be delivered";
  }
  return running;
}
