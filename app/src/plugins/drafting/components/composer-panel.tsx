import type { ReactNode } from "react";

export interface ComposerPanelItem {
  id: string;
  /** Accessible label for the collapsed trigger button. */
  ariaLabel: string;
  /** Expanded panel rendered directly above the composer. */
  content: ReactNode;
  /** Optional visual icon shown before the trigger label. */
  icon?: ReactNode;
  /** Compact text shown in the composer area, e.g. "Tone: Formal". */
  triggerLabel?: ReactNode;
  /** Marks the trigger when local panel changes are not saved yet. */
  hasChanges?: boolean;
}

export function ComposerPanelTriggers({
  panels,
  openPanelId,
  onTogglePanel,
}: {
  panels: ComposerPanelItem[];
  openPanelId: string | null;
  onTogglePanel: (panelId: string) => void;
}) {
  if (panels.length === 0) {
    return null;
  }

  return (
    <div className="mb-2 flex flex-wrap gap-2">
      {panels.map((panel) => (
        // Compact triggers keep drafting controls close to the prompt without
        // taking vertical space while the editor is drafting.
        <button
          key={panel.id}
          type="button"
          className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
          aria-label={panel.ariaLabel}
          aria-expanded={openPanelId === panel.id}
          title={panel.ariaLabel}
          onClick={() => onTogglePanel(panel.id)}
        >
          {panel.icon}
          {panel.triggerLabel && <span>{panel.triggerLabel}</span>}
          {panel.hasChanges && (
            <>
              <span className="text-amber-600" aria-hidden="true">
                *
              </span>
              <span className="sr-only">unsaved changes</span>
            </>
          )}
        </button>
      ))}
    </div>
  );
}
