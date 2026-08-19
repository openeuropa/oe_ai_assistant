import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface PaneTabProps {
  /** Icon of the pane this tab opens (reused from that pane). */
  icon: ReactNode;
  /** Tab title, e.g. "Tone" or "Documents". */
  title: string;
  /** Summary of the current selection, e.g. the tone label or "3 documents". */
  summary?: ReactNode;
  /** Whether this tab's pane is currently open. */
  active: boolean;
  /** Toggles this tab's pane. */
  onClick: () => void;
}

/**
 * A single tab sitting on top of the chat composer.
 *
 * Shows the pane's icon, its title, and a one-line summary of the current
 * selection underneath. When active, a top accent connects it to the pane
 * that opens above. Reused for every composer tab (tone, documents, ...).
 */
export function PaneTab({
  icon,
  title,
  summary,
  active,
  onClick,
}: PaneTabProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-expanded={active}
      className={cn(
        // A single right border per tab avoids doubling between tabs; the
        // last tab keeps it too, closing the grid on the right. Tabs size
        // to their content above a shared floor so they stay grouped on
        // the left with consistent widths.
        "flex min-w-[250px] cursor-pointer items-start gap-2 border-r border-gray-200 border-b-2 px-3 py-2 text-left transition-colors",
        // A bottom accent underlines the active tab; its pane opens above.
        // Hover mirrors the open (active) styling.
        active
          ? "border-b-blue-600 bg-gray-50 text-blue-700"
          : "border-b-transparent bg-white text-gray-700 hover:border-b-blue-600 hover:bg-gray-50 hover:text-blue-700",
      )}
    >
      {/* Icon aligned with the title line at the top of the tab. */}
      <span className="mt-px shrink-0">{icon}</span>
      <span className="flex min-w-0 flex-col leading-tight">
        <span className="truncate text-xs font-medium">{title}</span>
        {summary != null && summary !== "" && (
          <span className="truncate text-xs text-gray-500">{summary}</span>
        )}
      </span>
    </button>
  );
}
