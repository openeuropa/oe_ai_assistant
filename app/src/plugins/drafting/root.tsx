/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on the right.
 * DraftingChat owns the assistant-ui runtime, the tone/template/documents
 * hooks, and the tab construction. After a successful save it splices a
 * local event chip into the thread state via appendEventToThread so the
 * chip appears instantly without any remount or refetch.
 *
 * DraftingRoot is a thin shell that renders DraftingChat.
 */

import { AssistantRuntimeProvider } from "@assistant-ui/react";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { type ReactNode, useCallback } from "react";
import { CardSelectPane } from "@/components/ui/card-select-pane";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { useAppStore } from "@/store";
import { saveDraftRevision } from "./api/drafting-api";
import { ArtifactPane } from "./components/artifact-pane";
import { ContentTable } from "./components/content-table";
import { DocumentsPanel } from "./components/documents-panel";
import { DraftRail } from "./components/draft-rail";
import { DraftingThread } from "./components/drafting-thread";
import { PlanSteps } from "./components/plan-steps";
import {
  DraftContentToolUI,
  EditorialEventToolUI,
} from "./components/tool-uis";
import { useDraftingDocuments } from "./hooks/use-drafting-documents";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingTemplate } from "./hooks/use-drafting-template";
import { useDraftingTone } from "./hooks/use-drafting-tone";
import { useReportPendingWork } from "./hooks/use-report-pending-work";
import { useReportParticipants } from "./participants";
import { useSessionDrafts } from "./session-drafts";
import { getDraftingState, useDraftingSlice } from "./store";
import { appendEventToThread } from "./thread-events";

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
 * Inner component that owns the assistant-ui runtime and all runtime-dependent
 * state, including tone/template/documents hooks and composer tab construction.
 *
 * Save handlers capture labels before calling submitValues() and splice a
 * local event chip (or error chip on failure) directly into the thread via
 * export/import. This avoids any remount or network refetch after a save.
 */
function DraftingChat() {
  const { draftedFields, plan } = useDraftingSlice();
  const setPendingWork = useAppStore((s) => s.setPendingWork);
  const runtime = useDraftingRuntime();
  const tone = useDraftingTone();
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  const hasFields = Object.keys(draftedFields).length > 0;
  // The pane only exists once there is an artifact to show; before that
  // the chat takes the full workspace width.
  const hasArtifact = hasFields || plan.length > 0;

  /**
   * Splices a local event chip (or error chip) into the thread.
   *
   * Memoised on runtime so the identity is stable across re-renders that do
   * not change the runtime reference.
   */
  const appendEvent = useCallback(
    (eventType: string, summary: string) =>
      appendEventToThread(runtime.thread, { eventType, summary }),
    [runtime],
  );

  /**
   * Saves the draft version open in the artifact pane via the save
   * endpoint. The backend resolves the fields for that version from its
   * own draft history, so saving an older version saves exactly what
   * the pane shows. The in-flight request reports pending work so the
   * exit guard blocks navigation, and the outcome lands in the thread
   * as a local event chip (the backend records the matching durable
   * event row).
   */
  const handleSave = useCallback(async () => {
    const version = getDraftingState().activeDraftVersion;
    if (version === null) {
      // Legacy unversioned drafts cannot be addressed by the contract.
      appendEvent("error", "This draft has no version and cannot be saved");
      return;
    }
    setPendingWork("drafting:save", true);
    try {
      await saveDraftRevision({ version });
    } catch {
      appendEvent("error", `Draft ${version} could not be saved`);
      return;
    } finally {
      setPendingWork("drafting:save", false);
    }
    appendEvent("save", `Draft ${version} saved as unpublished revision`);
  }, [appendEvent, setPendingWork]);

  /** Determine what the artifact pane shows. */
  function renderArtifact() {
    if (hasFields) {
      return <ContentTable onSave={handleSave} />;
    }
    return (
      <div className="flex min-h-0 flex-1 flex-col p-4">
        <PlanSteps steps={plan} />
      </div>
    );
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
            // Capture labels before saving so the summary matches the backend's.
            const previous = tone.selectedLabel;
            const next =
              tone.options.find((option) => option.value === tone.value)
                ?.label ?? tone.value;
            try {
              await tone.submitValues();
            } catch (error) {
              // The pane keeps its inline error; the chat records the failure.
              appendEvent("error", "Tone change failed");
              throw error;
            }
            appendEvent(
              "tone",
              previous
                ? `Tone changed from ${previous} to ${next}`
                : `Tone changed to ${next}`,
            );
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
      summary:
        documents.count === 1 ? "1 document" : `${documents.count} documents`,
      render: (close) => (
        <DocumentsPanel
          selected={documents.selected}
          uploads={documents.uploads}
          onRemove={documents.removeDocument}
          onUpload={documents.uploadFiles}
          onDismissUpload={documents.dismissUpload}
          onSave={async () => {
            // Uploads and removals are persisted immediately.
            close();
          }}
          onCancel={close}
          isSaving={documents.isSaving}
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
            // Capture label before saving so the summary matches the backend's.
            const next =
              template.options.find((option) => option.value === template.value)
                ?.label ?? template.value;
            try {
              await template.submitValues();
            } catch (error) {
              // The pane keeps its inline error; the chat records the failure.
              appendEvent("error", "Template change failed");
              throw error;
            }
            appendEvent("template", `Template changed to ${next}`);
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
      <DraftContentToolUI />
      <EditorialEventToolUI />

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

        {/* Middle panel appears once a plan or draft exists: plan steps
            while generating, then the content table. */}
        {hasArtifact && (
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
