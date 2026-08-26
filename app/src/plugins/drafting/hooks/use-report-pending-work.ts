/**
 * Reports the drafting plugin's pending work to the shell store.
 *
 * Pending means the assistant run is in flight (streaming text, tool
 * calls, or a save triggered through the chat) or the composer holds
 * unsent text. Editorial panel saves report themselves to the same
 * store under their own source keys (see useCardSelection). The shell
 * exit guard blocks navigation while any source reports pending work.
 * Must be called inside the AssistantRuntimeProvider so the
 * assistant-ui state is available.
 */

import { useAuiState } from "@assistant-ui/react";
import { useEffect } from "react";
import { useAppStore } from "@/store";

export function useReportPendingWork(): void {
  const isRunning = useAuiState((s) => s.thread.isRunning);
  const composerText = useAuiState((s) => s.composer.text);
  const setPendingWork = useAppStore((s) => s.setPendingWork);

  // Sync the flag whenever the run state or composer text changes.
  useEffect(() => {
    setPendingWork("drafting", isRunning || composerText.trim().length > 0);
  }, [isRunning, composerText, setPendingWork]);

  // Clear the flag when the plugin unmounts.
  useEffect(() => () => setPendingWork("drafting", false), [setPendingWork]);
}
