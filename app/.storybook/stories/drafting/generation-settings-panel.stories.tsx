import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { useState } from "react";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import {
  type GenerationSettingsDraft,
  GenerationSettingsPanel,
} from "../../../src/plugins/drafting/components/generation-settings-panel";
import { TemplatePanel } from "../../../src/plugins/drafting/components/template-panel";
import { useDraftingDocuments } from "../../../src/plugins/drafting/hooks/use-drafting-documents";
import { useDraftingTemplate } from "../../../src/plugins/drafting/hooks/use-drafting-template";

const toneOptions = [
  {
    id: "clear-professional",
    label: "Clear and professional",
    description: "Be direct, neutral, and easy to scan.",
  },
  {
    id: "formal",
    label: "Formal",
    description: "Use an institutional, measured voice.",
  },
  {
    id: "engaging",
    label: "Engaging",
    description: "Warmer and more conversational, still accurate.",
  },
  {
    id: "concise",
    label: "Concise",
    description: "Trim every sentence to its essentials.",
  },
  {
    id: "authoritative",
    label: "Authoritative",
    description: "Confident and expert, backed by evidence.",
  },
];
const defaultToneId = toneOptions[0]?.id ?? "";

const meta = {
  title: "Drafting/Generation settings panel",
  component: GenerationSettingsPanel,
  args: {
    values: { toneId: defaultToneId },
    toneOptions,
    onChange: () => {},
    onSave: async () => {},
    onCancel: () => {},
    hasChanges: false,
    isSaving: false,
  },
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof GenerationSettingsPanel>;

export default meta;
type Story = StoryObj<typeof meta>;

function InteractivePanel({
  initialValues,
}: {
  initialValues?: GenerationSettingsDraft;
}) {
  const savedValues = initialValues ?? { toneId: defaultToneId };
  const [values, setValues] = useState<GenerationSettingsDraft>(savedValues);

  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <GenerationSettingsPanel
        values={values}
        toneOptions={toneOptions}
        onChange={setValues}
        onSave={async () => {}}
        onCancel={() => setValues(savedValues)}
        hasChanges={values.toneId !== savedValues.toneId}
        isSaving={false}
      />
    </div>
  );
}

export const SelectForNextPrompt: Story = {
  render: () => <InteractivePanel />,
};

export const SelectedValues: Story = {
  render: () => (
    <InteractivePanel
      initialValues={{
        toneId: "clear-professional",
      }}
    />
  ),
};

/** Shows the settings panel in the real drafting chat layout. */
function DraftingChatPreview() {
  const savedValues: GenerationSettingsDraft = { toneId: defaultToneId };
  const [values, setValues] = useState<GenerationSettingsDraft>({
    toneId: savedValues.toneId,
  });
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

  const selectedLabel =
    toneOptions.find((option) => option.id === values.toneId)?.label ??
    "Not set";

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <div className="flex h-[700px] max-w-2xl flex-col overflow-hidden border border-gray-200 bg-white">
        <DraftingThread
          defaultActiveTabId="tone"
          tabs={[
            {
              id: "tone",
              icon: <Megaphone size={16} />,
              title: "Tone",
              summary: selectedLabel,
              render: (close) => (
                <GenerationSettingsPanel
                  values={values}
                  toneOptions={toneOptions}
                  onChange={setValues}
                  onSave={async () => close()}
                  onCancel={() => {
                    setValues(savedValues);
                    close();
                  }}
                  hasChanges={values.toneId !== savedValues.toneId}
                  isSaving={false}
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
                <TemplatePanel
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
