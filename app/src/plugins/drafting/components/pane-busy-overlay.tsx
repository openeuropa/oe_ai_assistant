/**
 * Busy surface for the artifact pane.
 *
 * An open pane keeps showing the draft it holds while the run writes the next
 * one. The editor then reads an old draft as the current one. This covers the
 * pane instead, so the preview comes back only when the new draft is there.
 */

import { Loader2 } from "lucide-react";

/**
 * Covers the pane body with a centred spinner.
 *
 * Renders into the nearest positioned ancestor, so the pane body it covers
 * must be relative.
 */
export function PaneBusyOverlay() {
  return (
    <output
      className="absolute inset-0 z-10 flex items-center justify-center bg-white"
      aria-label="Drafting"
    >
      <Loader2 size={28} className="animate-spin text-gray-300" />
    </output>
  );
}
