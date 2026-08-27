/**
 * Editorial context buttons shown inside the chat composer.
 *
 * Renders the context panels (tone, documents, templates) as a row of
 * pill buttons, each showing its icon, label, and the current selection
 * on a second line. Pressing a button opens the corresponding panel in
 * a modal centered on the screen; the panel brings its own title,
 * description, and save/cancel actions, so the modal renders without a
 * header of its own. Layout is left to the parent (the composer's
 * bottom row).
 */

import { useState } from "react";
import { Dialog } from "@/components/ui/dialog";
import type { PaneTabItem } from "@/components/ui/pane-tabs";

interface ContextButtonsProps {
  /** Context panels shown as pill buttons; each opens a centered modal. */
  tabs: PaneTabItem[];
  /** Opens a panel modal by id on mount (Storybook/previews). */
  defaultActiveTabId?: string;
}

export function ContextButtons({
  tabs,
  defaultActiveTabId,
}: ContextButtonsProps) {
  const [activeTabId, setActiveTabId] = useState<string | null>(
    defaultActiveTabId ?? null,
  );
  const activeTab = tabs.find((tab) => tab.id === activeTabId) ?? null;
  const closePanel = () => setActiveTabId(null);

  if (tabs.length === 0) {
    return null;
  }

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-2">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          onClick={() => setActiveTabId(tab.id)}
          className="flex cursor-pointer items-center gap-2 rounded-xl px-3 py-1.5 text-left transition-colors hover:bg-gray-100"
        >
          <span className="shrink-0 text-gray-500">{tab.icon}</span>
          <span className="flex min-w-0 flex-col leading-tight">
            <span className="truncate text-xs font-medium text-gray-700">
              {tab.title}
            </span>
            {tab.summary != null && tab.summary !== "" && (
              <span className="max-w-40 truncate text-xs text-gray-500">
                {tab.summary}
              </span>
            )}
          </span>
        </button>
      ))}

      {/* Panel modal, centered on the screen; the panel owns its chrome.
          Wider than the dialog default; the height follows the content. */}
      {activeTab && (
        <Dialog
          open
          onClose={closePanel}
          title={activeTab.title}
          hideHeader
          className="max-w-3xl"
        >
          {activeTab.render(closePanel)}
        </Dialog>
      )}
    </div>
  );
}
