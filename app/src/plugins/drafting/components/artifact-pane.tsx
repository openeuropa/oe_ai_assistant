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

import type { ReactNode } from "react";
import { useDraftingSlice } from "../store";

export function ArtifactPane({ children }: { children: ReactNode }) {
  const { isArtifactCollapsed } = useDraftingSlice();

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
