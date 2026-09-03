/**
 * Draft preview pane with Live preview and Data tabs.
 *
 * Alternative pane body to the plain ContentTable: the header shows
 * the draft title, a Live preview / Data tab switcher, and the Save
 * button. The Live preview tab renders the draft as a full page in an
 * iframe whose URL comes from the plugin config template; the Data
 * tab reuses the raw field table. A viewport toolbar lets editors
 * check the draft at mobile, tablet and desktop widths, a reload
 * button returns the frame to the draft page after a stray click on
 * a link inside it, and a fullscreen toggle expands the whole pane
 * over the page.
 */

import {
  Eye,
  Loader2,
  Maximize2,
  Minimize2,
  Monitor,
  RefreshCw,
  Save,
  Smartphone,
  Table,
  Tablet,
} from "lucide-react";
import {
  type CSSProperties,
  type ReactNode,
  useEffect,
  useRef,
  useState,
} from "react";
import { getConfig } from "@/config";
import { buildPreviewUrl } from "../preview-url";
import type { DraftingPluginConfig } from "../types";
import { ContentTableBody, SaveConfirmDialog } from "./content-table";

/** The two views the pane can show. */
type PreviewTab = "live" | "data";

/** The viewport presets offered by the toolbar. */
type ViewportSize = "mobile" | "tablet" | "desktop";

/**
 * Iframe width per viewport preset, in CSS pixels. Desktop has no
 * entry: it fills the available pane width.
 */
const VIEWPORT_WIDTHS: Partial<Record<ViewportSize, number>> = {
  mobile: 375,
  tablet: 768,
};

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

/** An icon-only toolbar button (viewport preset, reload, fullscreen). */
function ToolbarButton({
  icon,
  label,
  active = false,
  onClick,
}: {
  icon: ReactNode;
  label: string;
  active?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={`group relative cursor-pointer rounded p-1.5 transition-colors ${
        active
          ? "bg-white text-gray-900 shadow-sm"
          : "text-gray-500 hover:text-gray-700"
      }`}
    >
      {icon}
      {/* Hover tooltip naming the preset and its size. */}
      <span className="pointer-events-none absolute top-full left-1/2 z-20 mt-1.5 -translate-x-1/2 rounded bg-gray-900 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 transition-opacity group-hover:opacity-100">
        {label}
      </span>
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
 *
 * Device presets render the page at the true device width so its
 * media queries fire, then scale it down to fit the pane when the
 * pane is narrower. Without the scaling, any preset wider than the
 * pane would clamp to the pane width and look identical to desktop.
 */
function LivePreviewFrame({
  url,
  hidden,
  width,
}: {
  url: string;
  hidden: boolean;
  /** Device width in pixels; undefined fills the pane (desktop). */
  width?: number;
}) {
  const [isLoading, setIsLoading] = useState(true);
  // The pane body size, tracked so device presets can scale to fit.
  const bodyRef = useRef<HTMLDivElement>(null);
  const [bodySize, setBodySize] = useState<{ w: number; h: number } | null>(
    null,
  );

  useEffect(() => {
    const el = bodyRef.current;
    if (!el) return;
    const update = () => setBodySize({ w: el.clientWidth, h: el.clientHeight });
    update();
    const observer = new ResizeObserver(update);
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  // Shrink the device frame to fit the pane; never enlarge it.
  const scale = width && bodySize ? Math.min(1, bodySize.w / width) : 1;
  const isDevice = width !== undefined && bodySize !== null;

  return (
    <div
      ref={bodyRef}
      className={
        hidden
          ? "hidden"
          : "relative min-h-0 flex-1 overflow-hidden bg-gray-100"
      }
    >
      {isLoading && (
        <div className="absolute inset-0 z-10 flex items-center justify-center bg-white">
          <Loader2 size={24} className="animate-spin text-gray-400" />
        </div>
      )}
      {/* Device presets center the framed page like a device screen;
          desktop stretches it edge to edge. The wrapper takes the
          scaled (visual) size while the iframe keeps the real device
          width, since transforms do not affect layout. The dynamic
          numbers travel as CSS custom properties consumed by arbitrary
          value utilities: the app's scoped reset reverts inline styles
          on descendants with !important, so plain style attributes
          would be discarded inside the CMS, while custom properties
          are exempt from all:revert and utilities out-cascade it. */}
      <div
        className={
          isDevice
            ? "mx-auto h-full w-[var(--frame-w)] overflow-hidden border-x border-gray-300"
            : "h-full"
        }
        style={
          isDevice
            ? ({ "--frame-w": `${width * scale}px` } as CSSProperties)
            : undefined
        }
      >
        {/* The document is AI-authored content rendered by the backend:
            keep the session cookie for the authenticated request but do
            not let the document run scripts. */}
        <iframe
          src={url}
          title="Live preview"
          sandbox="allow-same-origin"
          className={
            isDevice
              ? "h-[var(--device-h)] w-[var(--device-w)] origin-top-left scale-[var(--device-scale)] border-0 bg-white"
              : "h-full w-full border-0 bg-white"
          }
          style={
            isDevice
              ? ({
                  "--device-w": `${width}px`,
                  "--device-h": `${bodySize.h / scale}px`,
                  "--device-scale": String(scale),
                } as CSSProperties)
              : undefined
          }
          onLoad={() => setIsLoading(false)}
        />
      </div>
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
  const [viewport, setViewport] = useState<ViewportSize>("desktop");
  // Fullscreen turns the whole pane (header included) into a page
  // overlay, so the tabs, toolbar and save action stay available.
  const [isFullscreen, setIsFullscreen] = useState(false);
  // Bumped by the reload button to remount the frame at the original
  // preview URL, undoing any navigation from links clicked inside it.
  const [reloadCount, setReloadCount] = useState(0);
  const [showConfirm, setShowConfirm] = useState(false);

  return (
    <div
      className={
        isFullscreen
          ? // Above the CMS chrome: the Drupal admin toolbar sits at
            // z-index 1250, and full screen should cover the whole page.
            "fixed inset-0 z-[1300] flex flex-col bg-white"
          : "flex min-h-0 flex-1 flex-col"
      }
    >
      {/* Header: draft title, tab switcher, viewport toolbar, save. */}
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
        <div className="flex items-center gap-3">
          {/* Viewport toolbar: only meaningful for the live page. */}
          {hasLivePreview && activeTab === "live" && (
            <div className="flex items-center gap-1 rounded-md bg-gray-100 p-0.5">
              <ToolbarButton
                icon={<Smartphone size={14} />}
                label="Mobile (375px)"
                active={viewport === "mobile"}
                onClick={() => setViewport("mobile")}
              />
              <ToolbarButton
                icon={<Tablet size={14} />}
                label="Tablet (768px)"
                active={viewport === "tablet"}
                onClick={() => setViewport("tablet")}
              />
              <ToolbarButton
                icon={<Monitor size={14} />}
                label="Desktop (full width)"
                active={viewport === "desktop"}
                onClick={() => setViewport("desktop")}
              />
              <ToolbarButton
                icon={<RefreshCw size={14} />}
                label="Reload preview"
                onClick={() => setReloadCount((count) => count + 1)}
              />
              <ToolbarButton
                icon={
                  isFullscreen ? (
                    <Minimize2 size={14} />
                  ) : (
                    <Maximize2 size={14} />
                  )
                }
                label={isFullscreen ? "Exit full screen" : "Full screen"}
                onClick={() => setIsFullscreen(!isFullscreen)}
              />
            </div>
          )}
          <button
            type="button"
            onClick={() => setShowConfirm(true)}
            className="flex cursor-pointer items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
          >
            <Save size={12} />
            Save draft
          </button>
        </div>
      </div>

      {/* Keyed on the URL and the reload count so switching draft
          versions or pressing reload remounts the frame and shows the
          spinner for the new document. */}
      {hasLivePreview && (
        <LivePreviewFrame
          key={`${reloadCount}:${previewUrl}`}
          url={previewUrl}
          hidden={activeTab !== "live"}
          width={VIEWPORT_WIDTHS[viewport]}
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
