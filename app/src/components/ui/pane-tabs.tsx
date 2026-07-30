import { type ReactNode, useState } from "react";
import { PaneTab } from "./pane-tab";

/** One tab and the pane it opens. */
export interface PaneTabItem {
  /** Unique id for the tab. */
  id: string;
  /** Icon of the pane, shown on the tab. */
  icon: ReactNode;
  /** Tab title. */
  title: string;
  /** Summary of the current selection (tone label, "3 documents", ...). */
  summary?: ReactNode;
  /** Renders the pane body; receives a `close` callback to dismiss itself. */
  render: (close: () => void) => ReactNode;
}

export interface PaneTabsProps {
  /** Tabs to show; each opens its pane above the bar. */
  tabs: PaneTabItem[];
  /** Opens a tab by id on mount (Storybook/previews). */
  defaultActiveId?: string;
}

/**
 * A row of tabs sitting on top of the chat composer.
 *
 * Each tab opens its pane above the bar, floating over the chat history
 * without resizing it. Only one pane is open at a time; clicking the active
 * tab closes it. The bar is reusable for any set of composer panes.
 */
export function PaneTabs({ tabs, defaultActiveId }: PaneTabsProps) {
  const [activeId, setActiveId] = useState<string | null>(
    defaultActiveId ?? null,
  );

  if (tabs.length === 0) {
    return null;
  }

  const activeTab = tabs.find((tab) => tab.id === activeId) ?? null;
  const close = () => setActiveId(null);

  return (
    // The bar anchors the pane, which floats above it.
    <div className="relative">
      {/* The pane overlays the chat history instead of pushing it, so the
      message list keeps its size when a pane opens. */}
      {activeTab && (
        <div className="absolute inset-x-0 bottom-full z-10 shadow-[0_-4px_12px_-8px_rgba(0,0,0,0.15)]">
          {activeTab.render(close)}
        </div>
      )}
      <div className="flex border-t border-gray-200">
        {tabs.map((tab) => (
          <PaneTab
            key={tab.id}
            icon={tab.icon}
            title={tab.title}
            summary={tab.summary}
            active={tab.id === activeId}
            onClick={() =>
              setActiveId((current) => (current === tab.id ? null : tab.id))
            }
          />
        ))}
      </div>
    </div>
  );
}
