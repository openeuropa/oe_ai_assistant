/**
 * Shared full drafting plugin preview for stories.
 *
 * Composes the complete drafting plugin UI: the chat thread seeded with a
 * realistic transcript (event chips, versioned draft cards, a save tool
 * call), the composer tabs, and the artifact pane showing the latest
 * draft. Mirrors the layout of the plugin root component with a local
 * mock runtime instead of the backend stream. Used by the "Full plugin"
 * drafting story and by the "Full app" shell story.
 */

import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { useState } from "react";
import type { SessionMessage } from "../../../src/api/session-messages";
import { CardSelectPane } from "../../../src/components/ui/card-select-pane";
import { ContentTable } from "../../../src/plugins/drafting/components/content-table";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import {
  DraftContentToolUI,
  EditorialEventToolUI,
  SaveDraftRevisionToolUI,
} from "../../../src/plugins/drafting/components/tool-uis";
import { useDraftingDocuments } from "../../../src/plugins/drafting/hooks/use-drafting-documents";
import { useDraftingTemplate } from "../../../src/plugins/drafting/hooks/use-drafting-template";
import { useReportPendingWork } from "../../../src/plugins/drafting/hooks/use-report-pending-work";
import { toThreadMessages } from "../../../src/plugins/drafting/hydrate-transcript";
import {
  draftingSliceConfig,
  setDraftingState,
} from "../../../src/plugins/drafting/store";

/** Drafted field values shown in the artifact pane (latest draft). */
const draftFields: Record<string, unknown> = {
  title: [{ value: "EU AI Act enters into force" }],
  oe_summary: [
    {
      value:
        "<p>The EU Artificial Intelligence Act enters into force today, " +
        "introducing the world's first comprehensive rules for AI.</p>",
      format: "full_html",
    },
  ],
  body: [
    {
      value:
        "<p>The regulation follows a risk-based approach: minimal-risk " +
        "systems face no obligations, while high-risk systems must meet " +
        "strict requirements before entering the market.</p>" +
        "<p>National authorities have twelve months to designate the " +
        "bodies overseeing conformity assessments.</p>",
      format: "full_html",
    },
  ],
  oe_publication_date: [{ value: "2026-08-19" }],
};

/** Fields captured on the first draft, before the editor asked for changes. */
const firstDraftFields: Record<string, unknown> = {
  title: [{ value: "New EU rules for artificial intelligence" }],
  body: [
    {
      value:
        "<p>The EU introduces a comprehensive framework regulating " +
        "artificial intelligence across the single market.</p>",
      format: "full_html",
    },
  ],
};

/**
 * Persisted-transcript fixture covering the full conversation surface:
 * user turns, event chips, versioned draft cards, and a save tool call.
 * It is mapped through the real hydrate path so the story exercises the
 * same rendering pipeline as a reloaded session.
 */
const transcript: SessionMessage[] = [
  {
    role: "event",
    type: "session_start",
    summary: "Session started",
    at: "2026-08-19T09:00:00Z",
  },
  {
    role: "user",
    content: "Draft a news article about the EU AI Act entering into force.",
  },
  {
    role: "assistant",
    content: "Here is a first draft based on the news article structure.",
    toolCalls: [
      {
        function: { name: "draft_content" },
        result: {
          version: 1,
          context: {
            tone: { id: "clear-professional", label: "Clear and professional" },
            template: { id: "news-article", label: "News article" },
            documents: [],
          },
          fields: firstDraftFields,
        },
      },
    ],
  },
  {
    role: "event",
    type: "tone",
    summary: "Tone changed from Clear and professional to Formal",
    at: "2026-08-19T09:05:00Z",
  },
  {
    role: "user",
    content: "Add a summary and a publication date, and use the formal tone.",
  },
  {
    role: "assistant",
    content: "I updated the draft with a summary and the publication date.",
    toolCalls: [
      {
        function: { name: "draft_content" },
        result: {
          version: 2,
          context: {
            tone: { id: "formal", label: "Formal" },
            template: { id: "news-article", label: "News article" },
            documents: [],
          },
          fields: draftFields,
        },
      },
    ],
  },
  {
    role: "user",
    content: "Save the draft as a new unpublished revision.",
  },
  {
    role: "assistant",
    content: "The draft has been saved as an unpublished revision.",
    toolCalls: [{ function: { name: "save_draft_revision" }, result: {} }],
  },
  {
    role: "event",
    type: "saved",
    summary: "Draft saved as unpublished revision",
    at: "2026-08-19T09:12:00Z",
  },
];

/** Tone options mirroring the standalone development config. */
const toneOptions = [
  {
    value: "clear-professional",
    label: "Clear and professional",
    description: "Be direct, neutral, and easy to scan.",
  },
  {
    value: "formal",
    label: "Formal",
    description: "Use an institutional, measured voice.",
  },
];
const defaultToneId = toneOptions[1]?.value ?? "";

/**
 * Seeds the drafting store slice for the preview. Stories skip
 * initializePluginSlices, so the full initial slice is spread in before
 * the latest draft's field values.
 */
export function seedDraftingPreviewState(): void {
  setDraftingState({
    ...draftingSliceConfig.initialState,
    draftedFields: draftFields,
  });
}

/** Bridges the mock runtime's pending state into the shell store. */
function PendingWorkReporter() {
  useReportPendingWork();
  return null;
}

/** Full drafting plugin UI preview; fills its parent flex container. */
export function FullDraftingPreview() {
  const [toneId, setToneId] = useState(defaultToneId);
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();

  const runtime = useLocalRuntime(
    {
      run: async () => ({
        content: [
          {
            type: "text",
            text: "This is a mocked response; the story has no backend.",
          },
        ],
      }),
    },
    { initialMessages: toThreadMessages(transcript) },
  );

  const toneLabel =
    toneOptions.find((option) => option.value === toneId)?.label ?? "Not set";

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      {/* Feed the shell exit guard with the mock runtime's pending state. */}
      <PendingWorkReporter />
      {/* Register tool call renderers so they appear inline in chat. */}
      <DraftContentToolUI />
      <EditorialEventToolUI />
      <SaveDraftRevisionToolUI />

      <div className="flex min-h-0 flex-1 bg-white">
        {/* Left panel: chat with composer tabs. */}
        <div className="flex w-2/5 min-h-0 flex-col border-r border-gray-200">
          {/* Plugin heading confined to the chat column, as in the root. */}
          <header className="flex h-12 shrink-0 items-center border-b border-gray-200 px-4">
            <h1 className="text-base font-semibold text-gray-900">Drafting</h1>
          </header>
          <DraftingThread
            tabs={[
              {
                id: "tone",
                icon: <Megaphone size={16} />,
                title: "Tone",
                summary: toneLabel,
                render: (close) => (
                  <CardSelectPane
                    icon={<Megaphone size={18} />}
                    title="Tone"
                    description="Save the selected tone before drafting to apply it."
                    options={toneOptions}
                    value={toneId}
                    onChange={setToneId}
                    onSave={async () => close()}
                    onCancel={close}
                    hasChanges={toneId !== defaultToneId}
                  />
                ),
              },
              {
                id: "documents",
                icon: <FileText size={16} />,
                title: "Documents",
                summary: `${documents.count} documents`,
                render: (close) => (
                  <DocumentsPanel
                    selected={documents.selected}
                    onRemove={documents.removeDocument}
                    onUpload={documents.uploadFiles}
                    onSave={async () => close()}
                    onCancel={close}
                  />
                ),
              },
              {
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
                    onSave={async () => close()}
                    onCancel={() => {
                      template.discardChanges();
                      close();
                    }}
                    hasChanges={template.hasChanges}
                  />
                ),
              },
            ]}
          />
        </div>

        {/* Right panel: artifact pane with the latest draft. */}
        <div className="flex w-3/5 min-h-0 flex-col">
          <ContentTable onSave={() => {}} />
        </div>
      </div>
    </AssistantRuntimeProvider>
  );
}
