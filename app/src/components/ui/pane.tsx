import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface PaneProps {
  /** Optional leading icon shown next to the title. */
  icon?: ReactNode;
  /** Pane title. */
  title: string;
  /** Optional supporting text under the title. */
  description?: string;
  /** Action controls pinned to the top-right corner (e.g. Save/Cancel). */
  actions?: ReactNode;
  /** Pane body. */
  children: ReactNode;
  /** Extra classes for the pane container. */
  className?: string;
}

/**
 * Settings pane surface shown above the chat composer.
 *
 * Provides the shared chrome for panes that sit over the chat: a header
 * with an icon, title, optional description, and a top-right actions slot,
 * followed by the pane body. Placement (e.g. overlaying the chat history)
 * is the caller's concern; this component only renders the pane surface so
 * it can be reused for different settings panes.
 */
export function Pane({
  icon,
  title,
  description,
  actions,
  children,
  className,
}: PaneProps) {
  return (
    <div className={cn("border-t border-gray-200 bg-gray-50 p-4", className)}>
      {/* Header row: title on the left, actions pinned top-right so the body
      below gets the full width. */}
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="flex items-start gap-2">
          {icon && (
            <span className="mt-0.5 shrink-0 text-blue-600">{icon}</span>
          )}
          <div>
            <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
            {description && (
              <p className="text-xs text-gray-600">{description}</p>
            )}
          </div>
        </div>
        {actions && (
          <div className="flex shrink-0 items-center gap-2">{actions}</div>
        )}
      </div>
      {children}
    </div>
  );
}
