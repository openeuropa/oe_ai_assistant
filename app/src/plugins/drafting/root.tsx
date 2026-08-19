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
import { useCallback } from "react";
import { CardSelectPane } from "@/components/ui/card-select-pane";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { ArtifactPlaceholder } from "./components/artifact-placeholder";
import { ContentTable } from "./components/content-table";
import { DocumentsPanel } from "./components/documents-panel";
import { DraftingThread } from "./components/drafting-thread";
import { PlanSteps } from "./components/plan-steps";
import {
  DraftContentToolUI,
  EditorialEventToolUI,
  SaveDraftRevisionToolUI,
} from "./components/tool-uis";
import { useDraftingDocuments } from "./hooks/use-drafting-documents";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingTemplate } from "./hooks/use-drafting-template";
import { useDraftingTone } from "./hooks/use-drafting-tone";
import { useReportPendingWork } from "./hooks/use-report-pending-work";
import { useDraftingSlice } from "./store";
import { appendEventToThread } from "./thread-events";

/** Bridges the runtime's pending state into the shell store. */
function PendingWorkReporter() {
  useReportPendingWork();
  return null;
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
  const runtime = useDraftingRuntime();
  const tone = useDraftingTone();
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  const hasFields = Object.keys(draftedFields).length > 0;

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

  /** Trigger save via the chat so the agent runs the save tool. */
  const handleSave = useCallback(() => {
    runtime.thread.append({
      role: "user",
      content: [
        {
          type: "text",
          text: "Save the draft as a new unpublished revision.",
        },
      ],
    });
  }, [runtime]);

  /** Determine what the right panel shows. */
  function renderArtifact() {
    if (hasFields) {
      return <ContentTable onSave={handleSave} />;
    }
    if (plan.length > 0) {
      return (
        <div className="flex min-h-0 flex-1 flex-col p-4">
          <PlanSteps steps={plan} />
        </div>
      );
    }
    return <ArtifactPlaceholder />;
  }

  // Composer tabs. Each opens a pane over the chat; the save handler appends
  // a local event chip to the thread on success or an error chip on failure.
  const tabs: PaneTabItem[] = [];

  if (tone.enabled) {
    tabs.push({
      id: "tone",
      icon: <Megaphone size={16} />,
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
      icon: <FileText size={16} />,
      title: "Documents",
      summary: `${documents.count} documents`,
      render: (close) => (
        <DocumentsPanel
          selected={documents.selected}
          onRemove={documents.removeDocument}
          onUpload={documents.uploadFiles}
          onSave={async () => {
            // No backend yet; just close the pane.
            close();
          }}
          onCancel={close}
        />
      ),
    });
  }

  if (template.enabled) {
    tabs.push({
      id: "templates",
      icon: <LayoutTemplate size={16} />,
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
      <SaveDraftRevisionToolUI />

      {/* Feed the shell exit guard with this plugin's pending state. */}
      <PendingWorkReporter />

      <div className="flex min-h-0 flex-1">
        {/* Left panel: chat (always visible) */}
        <div className="flex w-2/5 min-h-0 flex-col border-r border-gray-200">
          {/* Plugin heading confined to the chat column so the artifact
              pane keeps the full workspace height. Fixed height matching
              the artifact pane header so the two align side by side. */}
          <header className="flex h-12 shrink-0 items-center border-b border-gray-200 px-4">
            <h1 className="text-base font-semibold text-gray-900">Drafting</h1>
          </header>
          {/* Tabs sit on top of the prompt; each opens a pane over the chat. */}
          <DraftingThread tabs={tabs} />
        </div>

        {/* Right panel: placeholder -> plan steps -> content table */}
        <div className="flex w-3/5 min-h-0 flex-col">{renderArtifact()}</div>
      </div>
    </AssistantRuntimeProvider>
  );
}

/** Drafting plugin root: thin shell that renders DraftingChat. */
export default function DraftingRoot() {
  return <DraftingChat />;
}
