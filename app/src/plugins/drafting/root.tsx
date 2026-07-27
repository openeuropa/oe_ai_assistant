/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on
 * the right. The right panel shows: a placeholder before
 * prompting, plan steps during orchestration, and the content
 * table once fields arrive.
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
  RegenerateFieldsToolUI,
  SaveDraftRevisionToolUI,
  SetFieldContentToolUI,
} from "./components/tool-uis";
import { useDraftingDocuments } from "./hooks/use-drafting-documents";
import { useDraftingGenerationSettings } from "./hooks/use-drafting-generation-settings";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingTemplate } from "./hooks/use-drafting-template";
import { useDraftingSlice } from "./store";

export default function DraftingRoot() {
  const { draftedFields, plan } = useDraftingSlice();
  const generationSettings = useDraftingGenerationSettings();
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  const runtime = useDraftingRuntime();
  const hasFields = Object.keys(draftedFields).length > 0;

  // Composer tabs. Each opens a pane over the chat, and its summary
  // reproposes the current selection.
  const tabs: PaneTabItem[] = [];
  if (generationSettings.enabled) {
    tabs.push({
      id: "tone",
      icon: <Megaphone size={16} />,
      title: "Tone",
      summary: generationSettings.selectedLabel ?? "Not set",
      render: (close) => (
        <CardSelectPane
          icon={<Megaphone size={18} />}
          title="Tone"
          description="Save the selected tone before drafting to apply it."
          options={generationSettings.toneOptions.map((option) => ({
            value: option.id,
            label: option.label,
            description: option.description,
          }))}
          value={generationSettings.values.toneId}
          onChange={(toneId) => generationSettings.updateValues({ toneId })}
          onSave={async () => {
            // Persist, then close the pane on success.
            await generationSettings.submitValues();
            close();
          }}
          onCancel={() => {
            // Restore the confirmed tone, then close the pane.
            generationSettings.discardChanges();
            close();
          }}
          hasChanges={generationSettings.hasChanges}
          isSaving={generationSettings.isSaving}
          error={generationSettings.error}
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
            // Persist, then close the pane on success.
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

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      {/* Register tool call renderers so they appear inline in chat. */}
      <DraftContentToolUI />
      <SetFieldContentToolUI />
      <RegenerateFieldsToolUI />
      <SaveDraftRevisionToolUI />

      <div className="flex min-h-0 flex-1">
        {/* Left panel: chat (always visible) */}
        <div className="flex w-2/5 min-h-0 flex-col border-r border-gray-200">
          {/* Tabs sit on top of the prompt; each opens a pane over the chat. */}
          <DraftingThread tabs={tabs} />
        </div>

        {/* Right panel: placeholder -> plan steps -> content table */}
        <div className="flex w-3/5 min-h-0 flex-col">{renderArtifact()}</div>
      </div>
    </AssistantRuntimeProvider>
  );
}
