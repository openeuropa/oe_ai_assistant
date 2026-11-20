/**
 * Follows the background subagent tasks of the session.
 *
 * Polls the tasks endpoint while the drafting page is mounted. When a result
 * waits and this tab is idle, the tab starts the follow-up turn itself, which
 * streams into the thread like any other assistant turn. When a task shows as
 * delivered that this tab did not deliver (the worker delivered while the tab
 * was idle or busy, or another tab did), the thread reloads from the server.
 */

import type { AssistantRuntime } from "@assistant-ui/react";
import { useEffect, useRef } from "react";
import { getSubagentTasks } from "../api/drafting-api";
import { loadThreadRepository } from "../hydrate-transcript";
import { setDraftingState } from "../store";
import {
  ACTIVE_POLL_DELAY_MS,
  AFTER_RUN_POLL_DELAY_MS,
  IDLE_POLL_DELAY_MS,
  nextReloadDecision,
  noticeSummary,
  pollDelay,
  type SubagentTasksResponse,
  shouldDeliver,
} from "../subagent-tasks";
import { appendEventToThread } from "../thread-events";

/**
 * What the runtime callbacks and the polling hook tell each other.
 *
 * The runtime is built before the hook that needs it, so both share this
 * object. Its fields are read and written outside React renders.
 */
export interface DeliveryControl {
  /** Whether the run in flight is the delivery turn this tab started. */
  delivering: boolean;
  /** Called by the hook; the runtime reports a delivery turn's end here. */
  onDeliveryEnd: (failed: boolean) => void;
  /** Called by the hook; asks for a poll within the given delay. */
  pollWithin: (delay: number) => void;
}

/** Creates the object the runtime and the polling hook share. */
export function useDeliveryControl(): DeliveryControl {
  const ref = useRef<DeliveryControl | null>(null);
  if (ref.current === null) {
    ref.current = {
      delivering: false,
      onDeliveryEnd: () => {},
      pollWithin: () => {},
    };
  }
  return ref.current;
}

/**
 * Reports the end of a run to the polling hook.
 *
 * Called from the runtime's finish, cancel and error callbacks. Whatever the
 * run was, the tab polls again soon: a run ends with new rows, tasks and
 * results, and the next poll would otherwise wait out the idle delay.
 *
 * @param failed Whether the run ended in an error.
 * @returns Whether the run was a delivery turn.
 */
export function reportRunEnd(
  control: DeliveryControl,
  failed: boolean,
): boolean {
  const wasDelivery = control.delivering;
  if (wasDelivery) {
    control.onDeliveryEnd(failed);
  }
  control.pollWithin(AFTER_RUN_POLL_DELAY_MS);
  return wasDelivery;
}

/**
 * Polls the session tasks and delivers the results that wait.
 *
 * Starts on mount and stops on unmount.
 */
export function useSubagentTasks(
  runtime: AssistantRuntime,
  control: DeliveryControl,
): void {
  useEffect(() => {
    let stopped = false;
    let polling = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let dueAt = 0;
    // The soonest delay asked for while a poll was in flight.
    let askedDelay = Number.POSITIVE_INFINITY;
    // The task ids delivered at the previous poll; null before the first.
    let delivered: ReadonlySet<string> | null = null;
    // The tasks this tab delivered itself.
    const ownDeliveries = new Set<string>();

    const schedule = (delay: number) => {
      if (stopped) return;
      if (timer !== null) clearTimeout(timer);
      dueAt = Date.now() + delay;
      timer = setTimeout(poll, delay);
    };

    // Brings the next poll forward, never pushing it back.
    const pollWithin = (delay: number) => {
      if (stopped) return;
      if (polling) {
        askedDelay = Math.min(askedDelay, delay);
      } else if (timer === null || dueAt > Date.now() + delay) {
        schedule(delay);
      }
    };

    // Replaces the thread with the persisted transcript. A thread that
    // started streaming meanwhile keeps its live state.
    const reload = async () => {
      try {
        const repository = await loadThreadRepository();
        if (stopped || runtime.thread.getState().isRunning) return;
        runtime.thread.import(repository);
      } catch {
        // The next change of the revision tries again.
      }
    };

    // Splices the notice chip in and starts the follow-up turn under it.
    const deliver = (response: SubagentTasksResponse) => {
      const waiting = response.tasks.filter(
        (task) => task.deliveryState === "waiting",
      );
      control.delivering = true;
      for (const task of waiting) {
        ownDeliveries.add(task.taskId);
      }
      try {
        appendEventToThread(runtime.thread, {
          eventType: "background_results",
          summary: noticeSummary(waiting),
        });
        runtime.thread.startRun({
          parentId: runtime.thread.export().headId ?? null,
          sourceId: null,
          runConfig: { custom: { deliver: true } },
        });
      } catch {
        control.delivering = false;
        void reload();
      }
    };

    async function poll() {
      timer = null;
      polling = true;
      askedDelay = Number.POSITIVE_INFINITY;
      let delay = IDLE_POLL_DELAY_MS;
      try {
        const response = await getSubagentTasks();
        if (stopped) return;
        delay = pollDelay(response);
        // The tab shows the ledger on its cards.
        setDraftingState({
          tasksById: Object.fromEntries(
            response.tasks.map((task) => [task.taskId, task]),
          ),
        });

        const { isRunning, isLoading } = runtime.thread.getState();
        // A thread still loading its history has no head to parent under.
        const threadRunning = isRunning || isLoading;
        const decision = nextReloadDecision(delivered, response, {
          threadRunning,
          ownDeliveries,
        });
        delivered = decision.delivered;

        if (
          shouldDeliver(response, {
            threadRunning,
            delivering: control.delivering,
          })
        ) {
          deliver(response);
          delay = ACTIVE_POLL_DELAY_MS;
        } else if (decision.reload) {
          await reload();
        }
      } catch {
        // Keep following; the next tick tries again.
      } finally {
        polling = false;
      }
      schedule(Math.min(delay, askedDelay));
    }

    control.onDeliveryEnd = (failed) => {
      control.delivering = false;
      if (failed) {
        // The claim was lost or refused: the spliced chip and the empty
        // reply do not exist on the server, so the server's thread replaces
        // them without telling the editor.
        void reload();
      }
    };
    control.pollWithin = pollWithin;

    void poll();

    return () => {
      stopped = true;
      control.onDeliveryEnd = () => {};
      control.pollWithin = () => {};
      if (timer !== null) clearTimeout(timer);
    };
  }, [runtime, control]);
}
