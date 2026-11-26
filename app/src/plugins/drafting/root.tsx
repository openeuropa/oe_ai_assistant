/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on the right.
 * DraftingChat owns the assistant-ui runtime, the tone/template/documents
 * hooks, and the tab construction. The pane's Save button sends the chat
 * message "Save draft {n}." and the editor confirms the resulting save call
 * in a dialog.
 *
 * DraftingRoot is a thin shell that renders DraftingChat.
 */

import { AssistantRuntimeProvider, useAuiState } from "@assistant-ui/react";
import { FileText, LayoutTemplate, Loader2, Megaphone } from "lucide-react";
import { type ReactNode, useCallback } from "react";
import { CardSelectPane } from "@/components/ui/card-select-pane";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { getConfig } from "@/config";
import { ApprovalDecisionProvider, type Decision } from "./approval-decision";
import { ArtifactPane } from "./components/artifact-pane";
import { ContentTable } from "./components/content-table";
import { DocumentsPanel } from "./components/documents-panel";
import { DraftPreview } from "./components/draft-preview";
import { DraftRail } from "./components/draft-rail";
import { DraftingThread } from "./components/drafting-thread";
import { PaneBusyOverlay } from "./components/pane-busy-overlay";
import {
  DraftContentToolUI,
  EditorialEventToolUI,
  GetEditorialContextToolUI,
  GetSessionActivityToolUI,
  ReadDocumentToolUI,
  SaveDraftToolUI,
} from "./components/tool-uis";
import { useDraftingDocuments } from "./hooks/use-drafting-documents";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingTemplate } from "./hooks/use-drafting-template";
import { useDraftingTone } from "./hooks/use-drafting-tone";
import { useReportPendingWork } from "./hooks/use-report-pending-work";
import { useReportParticipants } from "./participants";
import { useSavedVersions } from "./saved-versions";
import { useSessionDrafts } from "./session-drafts";
import { useDraftingSlice } from "./store";
import { useWaitingSaves } from "./waiting-saves";

/** Bridges the runtime's pending state into the shell store. */
function PendingWorkReporter() {
  useReportPendingWork();
  return null;
}

/** Publishes the thread's participants to the session header. */
function ParticipantsReporter() {
  useReportParticipants();
  return null;
}

/**
 * Artifact pane wired to the session drafts index: Escape may only
 * collapse the pane once a rail tab exists to restore it. Reads the
 * thread, so it must render inside the AssistantRuntimeProvider.
 */
function SessionArtifactPane({ children }: { children: ReactNode }) {
  const sessionDrafts = useSessionDrafts();
  return (
    <ArtifactPane canCollapse={sessionDrafts.length > 0}>
      {children}
    </ArtifactPane>
  );
}

/**
 * Preview pane for a versioned draft, stamped with its creation time.
 * Save stays disabled while a reply streams or a save waits for the editor.
 * Reads the thread, so it must render inside the AssistantRuntimeProvider.
 */
function VersionedDraftPreview({
  version,
  onSave,
}: {
  version: number;
  onSave: () => void;
}) {
  const sessionDrafts = useSessionDrafts();
  const savedVersions = useSavedVersions();
  const waitingSaves = useWaitingSaves();
  const isRunning = useAuiState((s) => s.thread.isRunning);
  const activeDraft = sessionDrafts.find((draft) => draft.version === version);
  return (
    <DraftPreview
      sessionId={getConfig().sessionId}
      versionId={version}
      createdAt={activeDraft?.createdAt ?? null}
      isSaved={savedVersions.has(version)}
      onSave={onSave}
      isSaveDisabled={isRunning || waitingSaves.length > 0}
    />
  );
}

/**
 * Inner component that owns the assistant-ui runtime and all runtime-dependent
 * state, including tone/template/documents hooks and composer tab construction.
 *
 * The pane's Save button appends "Save draft {n}." to the thread, which makes
 * the agent call the save tool. The call waits for the editor's decision in
 * the approval dialog.
 */
function DraftingChat() {
  const { draftedFields, runs, activeCallIds, activeDraftVersion } =
    useDraftingSlice();
  const runtime = useDraftingRuntime();
  const tone = useDraftingTone();
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  // The pane only exists once there is a draft to show; before that the chat
  // takes the full workspace width.
  const hasFields = Object.keys(draftedFields).length > 0;
  const isDrafting = activeCallIds.some(
    (callId) => runs[callId]?.status === "running",
  );

  /**
   * Answers one waiting save as a turn of its own.
   *
   * Recording the call id in decidedSaves is what retires the card that
   * asked, while the answer is still on its way. See waiting-saves.ts.
   */
  const decide = useCallback(
    (callId: string, decision: Decision) => {
      const { messages } = runtime.thread.getState();
      // The runtime's onError already reports a failed run; the rejection
      // carries nothing more.
      Promise.resolve(
        runtime.thread.startRun({
          parentId: messages[messages.length - 1]?.id ?? null,
          runConfig: { custom: { approval: { callId, decision } } },
        }),
      ).catch(() => {});
    },
    [runtime],
  );

  /** Determine what the artifact pane shows. */
  function renderArtifact() {
    // Versioned drafts get the tabbed live preview pane; legacy
    // unversioned drafts cannot be addressed by the preview
    // endpoint and keep the plain data table.
    if (activeDraftVersion !== null) {
      return (
        <VersionedDraftPreview
          version={activeDraftVersion}
          onSave={() =>
            runtime.thread.append(`Save draft ${activeDraftVersion}.`)
          }
        />
      );
    }
    return <ContentTable />;
  }

  // Editorial context panels, shown as pill buttons under the composer.
  // Each opens a centered modal. A panel that saves keeps its own pane
  // open on a failure, and reports the failure in it.
  const tabs: PaneTabItem[] = [];

  if (tone.enabled) {
    tabs.push({
      id: "tone",
      icon: <Megaphone size={20} />,
      title: "Tone",
      summary: tone.selectedLabel ?? "Not set",
      render: (close) => (
        <CardSelectPane
          icon={<Megaphone size={18} />}
          title="Tone"
          description="Save the selected tone before drafting to apply it."
          options={tone.options}
          value={tone.value}
          onChange={tone.updateValue}
          onSave={async () => {
            // submitValues() throws when the save fails. Nothing catches it,
            // so close() never runs and the pane stays open.
            await tone.submitValues();
            close();
          }}
          onCancel={() => {
            // Restore the confirmed tone, then close the pane.
            tone.discardChanges();
            close();
          }}
          hasChanges={tone.hasChanges}
          isSaving={tone.isSaving}
          error={tone.error}
        />
      ),
    });
  }

  if (documents.enabled) {
    tabs.push({
      id: "documents",
      icon: <FileText size={20} />,
      title: "Context documents",
      summary: documents.isLoading ? (
        "Loading"
      ) : documents.processingCount > 0 ? (
        // The pipeline still owns some documents: say so even while the
        // pane is closed.
        <span className="inline-flex items-center gap-1">
          <Loader2 size={12} className="animate-spin" />
          Processing {documents.processingCount} of {documents.count}
        </span>
      ) : documents.count === 1 ? (
        "1 document"
      ) : (
        `${documents.count} documents`
      ),
      render: (close) => (
        <DocumentsPanel
          selected={documents.selected}
          extensions={documents.extensions}
          uploads={documents.uploads}
          onRemove={documents.removeDocument}
          onRetry={documents.retryDocument}
          onUpload={documents.uploadFiles}
          onDismissUpload={documents.dismissUpload}
          onClose={close}
          isSaving={documents.isSaving}
          isLoading={documents.isLoading}
          loadError={documents.loadError}
          selectionError={documents.selectionError}
        />
      ),
    });
  }

  if (template.enabled) {
    tabs.push({
      id: "templates",
      icon: <LayoutTemplate size={20} />,
      title: "Templates",
      summary: template.selectedLabel ?? "Not set",
      render: (close) => (
        <CardSelectPane
          icon={<LayoutTemplate size={18} />}
          title="Template"
          description="Select the structure the generated draft should follow."
          options={template.options}
          value={template.value}
          onChange={template.updateValue}
          onSave={async () => {
            // submitValues() throws when the save fails. Nothing catches it,
            // so close() never runs and the pane stays open.
            await template.submitValues();
            close();
          }}
          onCancel={() => {
            // Restore the confirmed template, then close the pane.
            template.discardChanges();
            close();
          }}
          hasChanges={template.hasChanges}
          isSaving={template.isSaving}
          error={template.error}
        />
      ),
    });
  }

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <ApprovalDecisionProvider value={decide}>
        {/* Register tool call renderers so they appear inline in chat. */}
        <DraftContentToolUI />
        <EditorialEventToolUI />
        <SaveDraftToolUI />
        <GetEditorialContextToolUI />
        <ReadDocumentToolUI />
        <GetSessionActivityToolUI />

        {/* Feed the shell exit guard with this plugin's pending state.
          Panel saves report themselves via useCardSelection. */}
        <PendingWorkReporter />
        {/* Feed the session header with the chat participants. */}
        <ParticipantsReporter />

        <div className="flex min-h-0 flex-1">
          {/* Left panel: chat, always flexing into the width the pane
            leaves free; the thread centers its own content. The faint
            gray well makes the white composer and cards stand out. */}
          <div className="flex min-h-0 flex-1 flex-col bg-gray-50">
            <DraftingThread tabs={tabs} />
          </div>

          {/* Middle panel appears once the session holds a draft. While the
            run writes the next one the pane still holds the previous draft,
            which the editor must not read as the current one, so the busy
            surface covers it until the new draft lands. */}
          {hasFields && (
            <SessionArtifactPane>
              <div className="relative flex min-h-0 flex-1 flex-col">
                {renderArtifact()}
                {isDrafting && <PaneBusyOverlay />}
              </div>
            </SessionArtifactPane>
          )}

          {/* Right edge: the always-present draft rail driving the pane. */}
          <DraftRail />
        </div>
      </ApprovalDecisionProvider>
    </AssistantRuntimeProvider>
  );
}

/** Drafting plugin root: thin shell that renders DraftingChat. */
export default function DraftingRoot() {
  return <DraftingChat />;
}
