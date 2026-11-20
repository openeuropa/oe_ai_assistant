/**
 * Follows the background subagent tasks of the session.
 *
 * Polls the tasks endpoint while the drafting page is mounted. When a result
 * waits and this tab is idle, the tab starts the follow-up turn itself, which
 * streams into the thread like any other assistant turn. When the thread
 * gained rows this tab did not write (the worker delivered while the tab was
 * busy, or another tab ran a turn), the thread reloads from the server.
 */

import type { AssistantRuntime } from "@assistant-ui/react";
import { useEffect, useRef } from "react";
import { getSubagentTasks } from "../api/drafting-api";
import { loadThreadRepository } from "../hydrate-transcript";
import {
  ACTIVE_POLL_DELAY_MS,
  IDLE_POLL_DELAY_MS,
  nextReloadDecision,
  noticeSummary,
  pollDelay,
  type SubagentTasksResponse,
  shouldDeliver,
} from "../subagent-tasks";
import { appendEventToThread } from "../thread-events";

/** Delay before the poll that follows the end of a delivery turn. */
const AFTER_DELIVERY_POLL_DELAY_MS = 500;

/**
 * What the runtime callbacks and the polling hook tell each other.
 *
 * The runtime is built before the hook that needs it, so both share this
 * object. Its fields are read and written outside React renders.
 */
export interface DeliveryControl {
  /** Whether the run in flight is the delivery turn this tab started. */
  delivering: boolean;
  /** Whether a run of this tab ended since the last poll answered. */
  localRunSinceLastPoll: boolean;
  /** Called by the hook; the runtime reports a delivery turn's end here. */
  onDeliveryEnd: (failed: boolean) => void;
}

/** Creates the object the runtime and the polling hook share. */
export function useDeliveryControl(): DeliveryControl {
  const ref = useRef<DeliveryControl | null>(null);
  if (ref.current === null) {
    ref.current = {
      delivering: false,
      localRunSinceLastPoll: false,
      onDeliveryEnd: () => {},
    };
  }
  return ref.current;
}

/**
 * Reports the end of a run to the polling hook.
 *
 * Called from the runtime's finish, cancel and error callbacks. Every run
 * counts as a run of this tab, so the rows it wrote do not trigger a reload.
 *
 * @param failed Whether the run ended in an error.
 * @returns Whether the run was a delivery turn.
 */
export function reportRunEnd(
  control: DeliveryControl,
  failed: boolean,
): boolean {
  control.localRunSinceLastPoll = true;
  if (!control.delivering) {
    return false;
  }
  control.onDeliveryEnd(failed);
  return true;
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
    let revision: string | null = null;

    const schedule = (delay: number) => {
      if (stopped) return;
      if (timer !== null) clearTimeout(timer);
      timer = setTimeout(poll, delay);
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
      control.localRunSinceLastPoll = true;
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
      let delay = IDLE_POLL_DELAY_MS;
      try {
        const response = await getSubagentTasks();
        if (stopped) return;
        delay = pollDelay(response);

        const { isRunning, isLoading } = runtime.thread.getState();
        // A thread still loading its history has no head to parent under.
        const threadRunning = isRunning || isLoading;
        const decision = nextReloadDecision(revision, response, {
          threadRunning,
          localRunSinceLastPoll: control.localRunSinceLastPoll,
        });
        revision = decision.revision;
        control.localRunSinceLastPoll = false;

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
      schedule(delay);
    }

    control.onDeliveryEnd = (failed) => {
      control.delivering = false;
      if (failed) {
        // The claim was lost or refused: the spliced chip and the empty
        // reply do not exist on the server, so the server's thread replaces
        // them without telling the editor.
        void reload();
      }
      if (!polling) {
        schedule(AFTER_DELIVERY_POLL_DELAY_MS);
      }
    };

    void poll();

    return () => {
      stopped = true;
      control.onDeliveryEnd = () => {};
      if (timer !== null) clearTimeout(timer);
    };
  }, [runtime, control]);
}
