/**
 * Drafting plugin root component.
 *
 * Split-panel layout: chat on the left, content artifact on
 * the right. The right panel always shows: a ghost placeholder
 * before prompting, a loading spinner while generating, and
 * the content table once fields arrive.
 */

import { AssistantRuntimeProvider } from "@assistant-ui/react";
import { Loader2 } from "lucide-react";
import { useCallback } from "react";
import { ArtifactPlaceholder } from "./components/artifact-placeholder";
import { ContentTable } from "./components/content-table";
import { DraftingThread } from "./components/drafting-thread";
import {
  DraftContentToolUI,
  RegenerateFieldsToolUI,
  SaveDraftRevisionToolUI,
  SetFieldContentToolUI,
} from "./components/tool-uis";
import { useDraftingRuntime } from "./hooks/use-drafting-runtime";
import { setDraftingState, useDraftingSlice } from "./store";

/** Loading spinner shown while the agent is generating content. */
function ArtifactLoading() {
  return (
    <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 text-gray-400">
      <Loader2 size={24} className="animate-spin" />
      <p className="text-sm">Generating draft content...</p>
    </div>
  );
}

export default function DraftingRoot() {
  const runtime = useDraftingRuntime();
  const { draftedFields, isDrafting } = useDraftingSlice();
  const hasFields = Object.keys(draftedFields).length > 0;

  /** Send rejected fields back to the chat for regeneration. */
  const handleRegenerate = useCallback(
    (rejectedFieldNames: string[]) => {
      const labels = rejectedFieldNames
        .map((name) => draftedFields[name]?.label ?? name)
        .join(", ");
      const message =
        `Regenerate these fields: ${labels}` +
        ` [fields:${rejectedFieldNames.join(",")}]`;

      runtime.thread.append({
        role: "user",
        content: [{ type: "text", text: message }],
      });

      setDraftingState({ rejectedFields: new Set() });
    },
    [draftedFields, runtime],
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
      return (
        <ContentTable onRegenerate={handleRegenerate} onSave={handleSave} />
      );
    }
    if (isDrafting) {
      return <ArtifactLoading />;
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
          <DraftingThread />
        </div>

        {/* Right panel: placeholder -> loading -> content table */}
        <div className="flex w-3/5 min-h-0 flex-col">{renderArtifact()}</div>
      </div>
    </AssistantRuntimeProvider>
  );
}
