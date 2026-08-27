/**
 * Draft preview pane with Live preview and Data tabs.
 *
 * Alternative pane body to the plain ContentTable: the header shows
 * the draft title, a Live preview / Data tab switcher, and the Save
 * button. The Live preview tab renders the draft as a full page in an
 * iframe whose URL comes from the plugin config template; the Data
 * tab reuses the raw field table.
 */

import { Eye, Loader2, Save, Table } from "lucide-react";
import { type ReactNode, useState } from "react";
import { getConfig } from "@/config";
import { buildPreviewUrl } from "../preview-url";
import type { DraftingPluginConfig } from "../types";
import { ContentTableBody, SaveConfirmDialog } from "./content-table";

/** The two views the pane can show. */
type PreviewTab = "live" | "data";

/** Props for the draft preview pane. */
interface DraftPreviewProps {
  /** Editorial session the previewed draft belongs to. */
  sessionId: string;
  /** Draft version to preview, as shown in the version rail. */
  versionId: number;
  /** Tab shown on mount. Defaults to the live preview. */
  defaultTab?: PreviewTab;
  /** Invoked after the user confirms the save dialog. */
  onSave: () => void;
}

/** A single tab button in the header switcher. */
function TabButton({
  icon,
  label,
  active,
  onClick,
}: {
  icon: ReactNode;
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex cursor-pointer items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors ${
        active
          ? "bg-white text-gray-900 shadow-sm"
          : "text-gray-500 hover:text-gray-700"
      }`}
    >
      {icon}
      {label}
    </button>
  );
}

/**
 * The live preview iframe with its loading spinner.
 *
 * Owns the per-document loading state, so callers remount it (via a
 * key on the URL) whenever the previewed version changes and the
 * spinner covers each new load. The hidden prop keeps the iframe
 * mounted while the Data tab is open, so switching back does not
 * reload the page.
 */
function LivePreviewFrame({ url, hidden }: { url: string; hidden: boolean }) {
  const [isLoading, setIsLoading] = useState(true);

  return (
    <div className={hidden ? "hidden" : "relative min-h-0 flex-1"}>
      {isLoading && (
        <div className="absolute inset-0 flex items-center justify-center bg-white">
          <Loader2 size={24} className="animate-spin text-gray-400" />
        </div>
      )}
      {/* The document is AI-authored content rendered by the backend:
          keep the session cookie for the authenticated request but do
          not let the document run scripts. */}
      <iframe
        src={url}
        title="Live preview"
        sandbox="allow-same-origin"
        className="h-full w-full border-0"
        onLoad={() => setIsLoading(false)}
      />
    </div>
  );
}

/** Draft pane combining the live preview iframe and the data table. */
export function DraftPreview({
  sessionId,
  versionId,
  defaultTab = "live",
  onSave,
}: DraftPreviewProps) {
  // The URL template comes from the host-provided plugin config.
  const draftingConfig = (getConfig().pluginConfig.drafting ??
    {}) as DraftingPluginConfig;
  const urlTemplate = draftingConfig.preview?.url ?? "";

  // Without a configured template there is nothing to embed: fall
  // back to a data-only pane and hide the tab switcher.
  const hasLivePreview = urlTemplate !== "";
  const previewUrl = hasLivePreview
    ? buildPreviewUrl(urlTemplate, sessionId, versionId)
    : "";

  const [activeTab, setActiveTab] = useState<PreviewTab>(
    hasLivePreview ? defaultTab : "data",
  );
  const [showConfirm, setShowConfirm] = useState(false);

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {/* Header: draft title, tab switcher, save action. */}
      <div className="flex h-12 shrink-0 items-center justify-between border-b border-gray-200 px-4">
        <div className="flex items-center gap-3">
          <h2 className="text-base font-semibold text-gray-900">
            Draft {versionId}
          </h2>
          {hasLivePreview && (
            <div className="flex items-center gap-1 rounded-md bg-gray-100 p-0.5">
              <TabButton
                icon={<Eye size={12} />}
                label="Live preview"
                active={activeTab === "live"}
                onClick={() => setActiveTab("live")}
              />
              <TabButton
                icon={<Table size={12} />}
                label="Data"
                active={activeTab === "data"}
                onClick={() => setActiveTab("data")}
              />
            </div>
          )}
        </div>
        <button
          type="button"
          onClick={() => setShowConfirm(true)}
          className="flex cursor-pointer items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
        >
          <Save size={12} />
          Save draft
        </button>
      </div>

      {/* Keyed on the URL so switching draft versions remounts the
          frame and shows the spinner for the new document. */}
      {hasLivePreview && (
        <LivePreviewFrame
          key={previewUrl}
          url={previewUrl}
          hidden={activeTab !== "live"}
        />
      )}

      {activeTab === "data" && <ContentTableBody />}

      {showConfirm && (
        <SaveConfirmDialog
          onConfirm={() => {
            setShowConfirm(false);
            onSave();
          }}
          onCancel={() => setShowConfirm(false)}
        />
      )}
    </div>
  );
}
