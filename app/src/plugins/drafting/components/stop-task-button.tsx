/**
 * StopTaskButton component.
 *
 * Asks the backend to stop a background task, then refreshes the ledger the
 * cards follow, so the card shows the outcome without waiting for the poll.
 */

import { useState } from "react";
import { getSubagentTasks, stopSubagentTask } from "../api/drafting-api";
import { setDraftingState } from "../store";

/** Props for StopTaskButton. */
export interface StopTaskButtonProps {
  /** The task to stop. */
  taskId: string;
}

/** A small button that stops a pending or running background task. */
export function StopTaskButton({ taskId }: StopTaskButtonProps) {
  const [stopping, setStopping] = useState(false);

  const stop = async () => {
    setStopping(true);
    try {
      await stopSubagentTask(taskId);
      const response = await getSubagentTasks();
      setDraftingState({
        tasksById: Object.fromEntries(
          response.tasks.map((task) => [task.taskId, task]),
        ),
      });
    } catch {
      // The regular poll shows where the task stands.
    } finally {
      setStopping(false);
    }
  };

  return (
    <button
      type="button"
      className="shrink-0 rounded border border-gray-200 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50"
      data-testid="stop-task"
      disabled={stopping}
      onClick={() => void stop()}
    >
      Stop
    </button>
  );
}
