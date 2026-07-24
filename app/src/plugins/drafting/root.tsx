/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on
 * the right. The right panel shows: a placeholder before
 * prompting, plan steps during orchestration, and the content
 * table once fields arrive.
 */

import { AssistantRuntimeProvider } from "@assistant-ui/react";
import { Megaphone } from "lucide-react";
import { useCallback } from "react";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { ArtifactPlaceholder } from "./components/artifact-placeholder";
import { ContentTable } from "./components/content-table";
import { DraftingThread } from "./components/drafting-thread";
import { GenerationSettingsPanel } from "./components/generation-settings-panel";
import { PlanSteps } from "./components/plan-steps";
import {
  DraftContentToolUI,
  RegenerateFieldsToolUI,
  SaveDraftRevisionToolUI,
  SetFieldContentToolUI,
} from "./components/tool-uis";
import { useDraftingGenerationSettings } from "./hooks/use-drafting-generation-settings";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { useDraftingSlice } from "./store";

export default function DraftingRoot() {
  const { draftedFields, plan } = useDraftingSlice();
  const generationSettings = useDraftingGenerationSettings();
  const runtime = useDraftingRuntime();
  const hasFields = Object.keys(draftedFields).length > 0;

  // Composer tabs. Each opens a pane over the chat. Tone is the first; more
  // (documents, templates) will be added as they land.
  const tabs: PaneTabItem[] = [];
  if (generationSettings.toneOptions.length > 0) {
    tabs.push({
      id: "tone",
      icon: <Megaphone size={16} />,
      title: "Tone",
      // The summary reproposes the current selection: the confirmed tone.
      summary: generationSettings.selectedLabel ?? "Not set",
      render: (close) => (
        <GenerationSettingsPanel
          values={generationSettings.values}
          toneOptions={generationSettings.toneOptions}
          onChange={generationSettings.updateValues}
          onSave={generationSettings.submitValues}
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
