import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { useState } from "react";
import { CardSelectPane } from "../../../src/components/ui/card-select-pane";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import { useDraftingDocuments } from "../../../src/plugins/drafting/hooks/use-drafting-documents";
import { useDraftingTemplate } from "../../../src/plugins/drafting/hooks/use-drafting-template";

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
  {
    value: "engaging",
    label: "Engaging",
    description: "Warmer and more conversational, still accurate.",
  },
];
const defaultToneId = toneOptions[0]?.value ?? "";

const meta = {
  title: "Drafting/Composer",
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

/** Shows the three composer tabs in the real drafting chat layout. */
function DraftingChatPreview() {
  const [toneId, setToneId] = useState(defaultToneId);
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  const runtime = useLocalRuntime({
    run: async () => ({
      content: [
        {
          type: "text",
          text: "The saved tone will be used for the next draft.",
        },
      ],
    }),
  });

  const toneLabel =
    toneOptions.find((option) => option.value === toneId)?.label ?? "Not set";

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <div className="flex h-[700px] max-w-2xl flex-col overflow-hidden border border-gray-200 bg-white">
        <DraftingThread
          defaultActiveTabId="tone"
          tabs={[
            {
              id: "tone",
              icon: <Megaphone size={20} />,
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
              icon: <FileText size={20} />,
              title: "Documents",
              summary: `${documents.count} documents`,
              render: (close) => (
                <DocumentsPanel
                  selected={documents.selected}
                  uploads={documents.uploads}
                  onRemove={documents.removeDocument}
                  onUpload={documents.uploadFiles}
                  onDismissUpload={documents.dismissUpload}
                  onSave={async () => close()}
                  onCancel={close}
                />
              ),
            },
            {
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
    </AssistantRuntimeProvider>
  );
}

export const InDraftingChat: Story = {
  render: () => <DraftingChatPreview />,
};
