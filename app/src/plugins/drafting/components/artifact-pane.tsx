/**
 * Artifact pane chrome for the drafting plugin.
 *
 * Wraps the right-hand artifact area. Expanded, it takes the artifact
 * share of the workspace width and hosts the pane body (content table or
 * plan steps). Collapsed, it animates down to zero width so the chat
 * reclaims the whole workspace; expanding it again is done from the
 * toggle in the drafting toolbar. The collapse flag lives in the
 * drafting store slice and is transient: every session load starts from
 * the expanded default.
 */

import { type ReactNode, useEffect } from "react";
import { setDraftingState, useDraftingSlice } from "../store";

export function ArtifactPane({ children }: { children: ReactNode }) {
  const { isArtifactCollapsed } = useDraftingSlice();

  // Escape collapses the expanded pane. Open modals own the Escape key:
  // Radix closes them on the same press, and at that moment the dialog
  // is still in the DOM, so the guard below skips the collapse.
  useEffect(() => {
    if (isArtifactCollapsed) {
      return;
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Escape" || event.defaultPrevented) {
        return;
      }
      if (document.querySelector('[role="dialog"][data-state="open"]')) {
        return;
      }
      setDraftingState({ isArtifactCollapsed: true });
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [isArtifactCollapsed]);

  return (
    <div
      className={`flex min-h-0 flex-col overflow-hidden transition-[width] duration-300 ease-in-out ${
        isArtifactCollapsed ? "w-0" : "w-1/2 border-l border-gray-200 bg-white"
      }`}
    >
      {children}
    </div>
  );
}
