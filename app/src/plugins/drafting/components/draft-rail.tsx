/**
 * Vertical draft rail on the right edge of the drafting workspace.
 *
 * Always visible; starts empty and gains a version tab for every draft
 * produced in the session, newest on top. Clicking a version opens
 * that draft in the artifact pane; clicking the active version again
 * (shown as an X) collapses the pane. The active tab is white and sits
 * flush against the white pane for visual continuity, while inactive
 * tabs rest on the grayer strip. Hovering an inactive version shows the
 * draft's chat card in a popover on the left for an at-a-glance
 * preview; the open version's X close control has no popover. The
 * rail scrolls when the session accumulates more drafts than fit.
 * Reopening a session that already has drafts auto-opens the latest
 * one.
 */

import { X } from "lucide-react";
import { HoverCard } from "radix-ui";
import { Fragment, useEffect, useRef } from "react";
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
    // Vertical tab strip: no divider against the pane so the active white
    // tab reads as a continuation of the white draft pane on its left.
    <div className="flex w-12 shrink-0 flex-col items-stretch gap-1 overflow-y-auto bg-gray-100 py-2 pr-1.5">
      {newestFirst.map((draft, index) => {
        const isActive =
          hasFields &&
          !isArtifactCollapsed &&
          activeDraftVersion === draft.version;
        const key = draft.version ?? `legacy-${index}`;

        const tabButton = (
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
            className={`flex h-9 w-full shrink-0 cursor-pointer items-center justify-center rounded-r-md text-xs font-medium transition-colors ${
              isActive
                ? "border-y border-r border-gray-200 bg-white text-gray-900"
                : "text-gray-600 hover:bg-gray-200 hover:text-gray-900"
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

        // The open draft's tab is a close control: its content is
        // already on screen, so hovering the X shows no summary card.
        if (isActive) {
          return <Fragment key={key}>{tabButton}</Fragment>;
        }

        return (
          <HoverCard.Root key={key} openDelay={200} closeDelay={100}>
            <HoverCard.Trigger asChild>{tabButton}</HoverCard.Trigger>

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
