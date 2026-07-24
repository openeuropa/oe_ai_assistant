import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, LayoutTemplate, Megaphone, X } from "lucide-react";
import { useState } from "react";
import { Pane } from "../../../src/components/ui/pane";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import {
  type GenerationSettingsDraft,
  GenerationSettingsPanel,
} from "../../../src/plugins/drafting/components/generation-settings-panel";

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

/** Cancel-style action for the placeholder panes in the preview. */
function CloseAction({ onClose }: { onClose: () => void }) {
  return (
    <button
      type="button"
      onClick={onClose}
      className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-100"
    >
      <X size={15} />
      Cancel
    </button>
  );
}

/** Shows the settings panel in the real drafting chat layout. */
function DraftingChatPreview() {
  const savedValues: GenerationSettingsDraft = { toneId: defaultToneId };
  const [values, setValues] = useState<GenerationSettingsDraft>({
    toneId: savedValues.toneId,
  });
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
                  onSave={async () => {}}
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
              summary: "3 documents",
              render: (close) => (
                <Pane
                  icon={<FileText size={18} />}
                  title="Documents"
                  description="Reference documents used to ground the draft."
                  actions={<CloseAction onClose={close} />}
                >
                  <p className="text-sm text-gray-600">
                    Document list goes here.
                  </p>
                </Pane>
              ),
            },
            {
              id: "templates",
              icon: <LayoutTemplate size={16} />,
              title: "Templates",
              summary: "Not set",
              render: (close) => (
                <Pane
                  icon={<LayoutTemplate size={18} />}
                  title="Templates"
                  description="Pick a starting template for the draft."
                  actions={<CloseAction onClose={close} />}
                >
                  <p className="text-sm text-gray-600">
                    Template picker goes here.
                  </p>
                </Pane>
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
