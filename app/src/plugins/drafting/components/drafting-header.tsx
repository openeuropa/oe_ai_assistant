/**
 * Drafting workspace toolbar.
 *
 * Spans the full width of the plugin area. Hosts the editorial context
 * controls (tone, documents, templates) as a row of bordered tabs on
 * the left, each showing the current selection under its label, and the
 * artifact pane toggle on the right. Pressing a tab opens the
 * corresponding panel in a modal centered on the screen; the panel
 * brings its own title, description, and save/cancel actions, so the
 * modal renders without a header of its own.
 */

import { PanelRightClose, PanelRightOpen } from "lucide-react";
import { useState } from "react";
import { Dialog } from "@/components/ui/dialog";
import { PaneTab } from "@/components/ui/pane-tab";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { setDraftingState, useDraftingSlice } from "../store";

interface DraftingHeaderProps {
  /** Context panels shown as tabs; each opens a centered modal. */
  tabs: PaneTabItem[];
  /** Opens a panel modal by id on mount (Storybook/previews). */
  defaultActiveTabId?: string;
  /** Shows the artifact pane toggle (there is an artifact to show). */
  showPaneToggle?: boolean;
}

export function DraftingHeader({
  tabs,
  defaultActiveTabId,
  showPaneToggle = false,
}: DraftingHeaderProps) {
  const { isArtifactCollapsed } = useDraftingSlice();
  const [activeTabId, setActiveTabId] = useState<string | null>(
    defaultActiveTabId ?? null,
  );
  const activeTab = tabs.find((tab) => tab.id === activeTabId) ?? null;
  const closePanel = () => setActiveTabId(null);

  return (
    <header className="flex h-12 shrink-0 items-stretch border-b border-gray-200">
      {tabs.map((tab) => (
        <PaneTab
          key={tab.id}
          icon={tab.icon}
          title={tab.title}
          summary={tab.summary}
          active={tab.id === activeTabId}
          onClick={() => setActiveTabId(tab.id)}
        />
      ))}

      {/* Right side: artifact pane toggle and future workspace controls. */}
      {showPaneToggle && (
        <div className="ml-auto flex items-center pr-2">
          <button
            type="button"
            onClick={() =>
              setDraftingState({ isArtifactCollapsed: !isArtifactCollapsed })
            }
            className="cursor-pointer rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900"
            aria-label={
              isArtifactCollapsed ? "Expand draft pane" : "Collapse draft pane"
            }
          >
            {isArtifactCollapsed ? (
              <PanelRightOpen size={16} />
            ) : (
              <PanelRightClose size={16} />
            )}
          </button>
        </div>
      )}

      {/* Panel modal, centered on the screen; the panel owns its chrome. */}
      {activeTab && (
        <Dialog open onClose={closePanel} title={activeTab.title} hideHeader>
          {activeTab.render(closePanel)}
        </Dialog>
      )}
    </header>
  );
}
