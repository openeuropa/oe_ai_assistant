/**
 * Vertical draft rail on the right edge of the drafting workspace.
 *
 * Always visible; starts empty and gains a version button for every
 * draft produced in the session, newest on top. Clicking a version
 * opens that draft in the artifact pane; clicking the active version
 * again (shown as an X) collapses the pane. The rail scrolls when the
 * session accumulates more drafts than fit. Reopening a session that
 * already has drafts auto-opens the latest one.
 */

import { X } from "lucide-react";
import { useEffect } from "react";
import { openSessionDraft, useSessionDrafts } from "../session-drafts";
import { setDraftingState, useDraftingSlice } from "../store";

export function DraftRail() {
  const drafts = useSessionDrafts();
  const { draftedFields, activeDraftVersion, isArtifactCollapsed } =
    useDraftingSlice();
  const hasFields = Object.keys(draftedFields).length > 0;
  const latest = drafts[drafts.length - 1];

  // Auto-open the latest draft when a session loads with drafts but the
  // pane has nothing to show yet (e.g. after a reload).
  useEffect(() => {
    if (!hasFields && latest) {
      openSessionDraft(latest);
    }
  }, [hasFields, latest]);

  // Newest on top: the index is version-ascending, so display reversed.
  const newestFirst = [...drafts].reverse();

  return (
    <div className="flex w-12 shrink-0 flex-col items-center gap-1.5 overflow-y-auto border-l border-gray-200 bg-gray-50 py-2">
      {newestFirst.map((draft, index) => {
        const isActive =
          hasFields &&
          !isArtifactCollapsed &&
          activeDraftVersion === draft.version;

        return (
          <button
            key={draft.version ?? `legacy-${index}`}
            type="button"
            title={draft.label}
            aria-label={
              isActive ? `Close ${draft.label}` : `Open ${draft.label}`
            }
            onClick={() =>
              isActive
                ? setDraftingState({ isArtifactCollapsed: true })
                : openSessionDraft(draft)
            }
            className={`flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full border text-xs font-medium transition-colors ${
              isActive
                ? "border-blue-600 bg-blue-600 text-white hover:bg-blue-700"
                : "border-gray-300 bg-white text-gray-600 hover:border-gray-400 hover:bg-gray-100"
            }`}
          >
            {isActive ? (
              <X size={14} />
            ) : draft.version !== null ? (
              `v${draft.version}`
            ) : (
              "v?"
            )}
          </button>
        );
      })}
    </div>
  );
}
