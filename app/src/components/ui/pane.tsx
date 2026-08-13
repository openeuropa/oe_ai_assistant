import { Check, Loader2, X } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface PaneProps {
  /** Optional leading icon shown next to the title. */
  icon?: ReactNode;
  /** Pane title. */
  title: string;
  /** Optional supporting text under the title. */
  description?: string;
  /** Pane body. */
  children: ReactNode;
  /** Extra classes for the pane container. */
  className?: string;
  /**
   * Save handler. When provided, the standard Save/Cancel actions render in
   * the top-right; the caller only passes the handlers.
   */
  onSave?: () => void | Promise<void>;
  /** Cancel handler. */
  onCancel?: () => void;
  /** Disables Save beyond the saving state (e.g. no pending change). */
  saveDisabled?: boolean;
  /** Shows a spinner on Save and disables both actions while a save runs. */
  isSaving?: boolean;
  /** Save button label. */
  saveLabel?: string;
  /** Cancel button label. */
  cancelLabel?: string;
}

/**
 * Settings pane surface shown above the chat composer.
 *
 * Provides the shared chrome for panes that sit over the chat: a header with
 * an icon, title, optional description, and the standard Save/Cancel actions,
 * followed by the pane body. Every pane of this kind saves and cancels the
 * same way, so those buttons live here and callers only pass handlers.
 * Placement (e.g. overlaying the chat history) is the caller's concern.
 */
export function Pane({
  icon,
  title,
  description,
  children,
  className,
  onSave,
  onCancel,
  saveDisabled = false,
  isSaving = false,
  saveLabel = "Save",
  cancelLabel = "Cancel",
}: PaneProps) {
  const hasActions = Boolean(onSave || onCancel);

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
        {hasActions && (
          <div className="flex shrink-0 items-center gap-2">
            {onCancel && (
              <button
                type="button"
                className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                onClick={onCancel}
                // Prevent dismissing the pane while a save is in flight.
                disabled={isSaving}
              >
                <X size={15} />
                {cancelLabel}
              </button>
            )}
            {onSave && (
              <button
                type="button"
                className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                onClick={() => {
                  // The caller surfaces its own error state; log the failure
                  // anyway so unexpected handler crashes are never silent.
                  void Promise.resolve(onSave()).catch((error) => {
                    console.error("[pane] onSave failed:", error);
                  });
                }}
                disabled={saveDisabled || isSaving}
              >
                {isSaving ? (
                  <Loader2 size={15} className="animate-spin" />
                ) : (
                  <Check size={15} />
                )}
                {saveLabel}
              </button>
            )}
          </div>
        )}
      </div>
      {children}
    </div>
  );
}
