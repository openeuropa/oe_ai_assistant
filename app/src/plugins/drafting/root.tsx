/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on the right.
 * DraftingChat owns the assistant-ui runtime, the tone/template/documents
 * hooks, and the tab construction. Saving goes through the conversation: the
 * Save button asks for it in words, the model calls save_draft, and the editor
 * answers the call before anything is written.
 *
 * DraftingRoot is a thin shell that renders DraftingChat.
 */

import {
  AssistantRuntimeProvider,
  ExportedMessageRepository,
} from "@assistant-ui/react";
import { FileText, LayoutTemplate, Loader2, Megaphone } from "lucide-react";
import { type ReactNode, useCallback, useEffect } from "react";
import { getSessionMessages } from "@/api/session-messages";
import { CardSelectPane } from "@/components/ui/card-select-pane";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { getConfig } from "@/config";
import { eventBus } from "@/lib/events";
import { useAppStore } from "@/store";
import { submitDraftingApproval } from "./api/drafting-api";
import { ArtifactPane } from "./components/artifact-pane";
import { ContentTable } from "./components/content-table";
import { DocumentsPanel } from "./components/documents-panel";
import { DraftPreview } from "./components/draft-preview";
import { DraftRail } from "./components/draft-rail";
import { DraftingThread } from "./components/drafting-thread";
import {
  DraftGroupToolUI,
  GetContentSchemaToolUI,
  GetDraftHistoryToolUI,
  ReviseDraftToolUI,
  SaveDraftToolUI,
} from "./components/tool-uis";
import { useDraftingDocuments } from "./hooks/use-drafting-documents";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingTemplate } from "./hooks/use-drafting-template";
import { useDraftingTone } from "./hooks/use-drafting-tone";
import { useReportPendingWork } from "./hooks/use-report-pending-work";
import { toThreadMessages } from "./hydrate-transcript";
import { useReportParticipants } from "./participants";
import { useSavedVersions } from "./saved-versions";
import { useSessionDrafts } from "./session-drafts";
import { getDraftingState, useDraftingSlice } from "./store";

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
 * Reads the thread for the timestamp, so it must render inside the
 * AssistantRuntimeProvider.
 */
function VersionedDraftPreview({
  version,
  onSave,
}: {
  version: number;
  onSave: (name: string) => void;
}) {
  const sessionDrafts = useSessionDrafts();
  const savedVersions = useSavedVersions();
  const activeDraft = sessionDrafts.find((draft) => draft.version === version);
  return (
    <DraftPreview
      sessionId={getConfig().sessionId}
      versionId={version}
      createdAt={activeDraft?.createdAt ?? null}
      isSaved={savedVersions.has(version)}
      onSave={onSave}
    />
  );
}

/**
 * Inner component that owns the assistant-ui runtime and all runtime-dependent
 * state, including tone/template/documents hooks and composer tab construction.
 *
 * The Save button asks the assistant to save, in the words the editor would
 * have used. The model then calls save_draft, which waits for the editor to
 * answer it, so nothing is written without a confirmation.
 */
function DraftingChat() {
  const { draftedFields, activeDraftVersion } = useDraftingSlice();
  const setPendingWork = useAppStore((s) => s.setPendingWork);
  const runtime = useDraftingRuntime();
  const tone = useDraftingTone();
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  // The pane only exists once there is a draft to show; before that the
  // chat takes the full workspace width.
  const hasFields = Object.keys(draftedFields).length > 0;

  /**
   * Asks the assistant to save the draft the artifact pane has open.
   *
   * The request is a message, because that is what it is: the editor asking
   * for a save. The model answers it with a save_draft call, which waits for
   * the editor to confirm before the node is written.
   */
  const handleSave = useCallback(
    (name: string) => {
      if (getDraftingState().activeDraftVersion === null) {
        return;
      }
      runtime.thread.append(`Save ${name}.`);
    },
    [runtime],
  );

  /**
   * Keeps the thread in step with a tool call that waits for a decision.
   *
   * Neuron writes a gated call to the conversation and then suspends without
   * streaming it, so the question only exists in the store: the thread is read
   * back when a turn ends by asking for one. Answering works the same way,
   * since the reply to a decision is the rest of the turn. Both arrive on the
   * event bus, because neither the runtime hook nor the tool UI that renders
   * the buttons can reach the runtime held here.
   */
  useEffect(() => {
    const reload = async () =>
      runtime.thread.import(
        ExportedMessageRepository.fromArray(
          toThreadMessages(await getSessionMessages("drafting")),
        ),
      );

    const decide = async (decision: {
      callId: string;
      decision: "approve" | "reject";
      reason?: string;
    }) => {
      setPendingWork("drafting:save", true);
      try {
        await submitDraftingApproval(decision);
        await reload();
      } finally {
        setPendingWork("drafting:save", false);
      }
    };

    eventBus.on("approval:decide", decide);
    eventBus.on("approval:requested", reload);
    return () => {
      eventBus.off("approval:decide", decide);
      eventBus.off("approval:requested", reload);
    };
  }, [runtime, setPendingWork]);

  /** Determine what the artifact pane shows. */
  function renderArtifact() {
    // An open draft gets the tabbed live preview pane; otherwise the
    // plain data table stands in.
    if (activeDraftVersion !== null) {
      return (
        <VersionedDraftPreview
          version={activeDraftVersion}
          onSave={handleSave}
        />
      );
    }
    return <ContentTable onSave={handleSave} />;
  }

  // Editorial context panels, shown as pill buttons under the composer.
  // Each opens a centered modal; the save handler appends a local event
  // chip to the thread on success or an error chip on failure.
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
            await template.submitValues();
            close();
          }}
          onCancel={() => {
            // Restore the confirmed template, then close the pane.
            template.discardChanges();
            close();
          }}
          hasChanges={template.hasChanges}
        />
      ),
    });
  }

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      {/* Register tool call renderers so they appear inline in chat. */}
      <DraftGroupToolUI />
      <ReviseDraftToolUI />
      <GetContentSchemaToolUI />
      <GetDraftHistoryToolUI />
      <SaveDraftToolUI />

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

        {/* Middle panel appears once a draft exists. */}
        {hasFields && (
          <SessionArtifactPane>{renderArtifact()}</SessionArtifactPane>
        )}

        {/* Right edge: the always-present draft rail driving the pane. */}
        <DraftRail />
      </div>
    </AssistantRuntimeProvider>
  );
}

/** Drafting plugin root: thin shell that renders DraftingChat. */
export default function DraftingRoot() {
  return <DraftingChat />;
}
