/**
 * Vertical draft rail on the right edge of the drafting workspace.
 *
 * Always visible; starts empty and gains a version button for every
 * draft produced in the session, newest on top. Clicking a version
 * opens that draft in the artifact pane; clicking the active version
 * again (shown as an X) collapses the pane. Hovering a version shows
 * the draft's chat card in a popover on the left for an at-a-glance
 * preview. The rail scrolls when the session accumulates more drafts
 * than fit. Reopening a session that already has drafts auto-opens the
 * latest one.
 */

import { X } from "lucide-react";
import { HoverCard } from "radix-ui";
import { useEffect, useRef } from "react";
import { openSessionDraft, useSessionDrafts } from "../session-drafts";
import { setDraftingState, useDraftingSlice } from "../store";
import { DraftCard } from "./draft-card";

export function DraftRail() {
  const drafts = useSessionDrafts();
  const { draftedFields, activeDraftVersion, isArtifactCollapsed } =
    useDraftingSlice();
  const hasFields = Object.keys(draftedFields).length > 0;
  const latest = drafts[drafts.length - 1];

  // Tracks the newest version already handled so switching between old
  // drafts never re-triggers the activation below.
  const lastSeenVersion = useRef<number | null>(null);

  // React to a new latest draft: on session load with an empty pane,
  // open it; when it was just generated live (the streamed fields are
  // already showing), mark its rail button active and keep the pane
  // expanded so the button reads as the closing X.
  useEffect(() => {
    if (!latest || latest.version === lastSeenVersion.current) {
      return;
    }
    lastSeenVersion.current = latest.version;
    if (!hasFields) {
      openSessionDraft(latest);
      return;
    }
    setDraftingState({
      activeDraftVersion: latest.version,
      isArtifactCollapsed: false,
    });
  }, [latest, hasFields]);

  // Newest on top: the index is version-ascending, so display reversed.
  const newestFirst = [...drafts].reverse();

  return (
    <div className="flex w-12 shrink-0 flex-col items-center gap-1.5 overflow-y-auto border-l border-gray-200 bg-white py-2">
      {newestFirst.map((draft, index) => {
        const isActive =
          hasFields &&
          !isArtifactCollapsed &&
          activeDraftVersion === draft.version;

        return (
          <HoverCard.Root
            key={draft.version ?? `legacy-${index}`}
            openDelay={200}
            closeDelay={100}
          >
            <HoverCard.Trigger asChild>
              <button
                type="button"
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
                    : "border-gray-300 bg-gray-100 text-gray-600 hover:bg-gray-200"
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
            </HoverCard.Trigger>

            {/* At-a-glance preview: the draft's chat card, floated to the
                left of the rail with an arrow pointing at the button. The
                data-ai-app scope lives on an inner wrapper, NOT on the
                Radix content: the scoped reset reverts inline styles on
                descendants and would break the arrow positioning. The
                wrapper overrides the 100vh height and white background of
                the container baseline and strips the card's chat margins
                so only the card box shows. */}
            <HoverCard.Portal>
              <HoverCard.Content
                side="left"
                align="start"
                sideOffset={6}
                className="z-50 drop-shadow-lg"
              >
                <div
                  data-ai-app=""
                  className="h-auto w-96 bg-transparent [&>button]:my-0 [&>button]:border-0"
                >
                  <DraftCard
                    version={draft.version}
                    context={draft.context}
                    fields={draft.fields}
                    onOpen={() => openSessionDraft(draft)}
                  />
                </div>
                {/* Arrow on the right edge, pointing at the rail button. */}
                <HoverCard.Arrow width={12} height={6} className="fill-white" />
              </HoverCard.Content>
            </HoverCard.Portal>
          </HoverCard.Root>
        );
      })}
    </div>
  );
}
