import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";
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
];

const meta = {
  title: "Drafting/Generation settings panel",
  component: GenerationSettingsPanel,
  args: {
    values: { toneId: "" },
    toneOptions,
    onChange: () => {},
    onSave: async () => {},
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
  const [values, setValues] = useState<GenerationSettingsDraft>(
    initialValues ?? { toneId: "" },
  );

  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <GenerationSettingsPanel
        values={values}
        toneOptions={toneOptions}
        onChange={setValues}
        onSave={async () => {}}
        hasChanges={values.toneId !== (initialValues?.toneId ?? "")}
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
  const [values, setValues] = useState<GenerationSettingsDraft>({
    toneId: "",
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

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <div className="flex h-[700px] max-w-2xl flex-col overflow-hidden border border-gray-200 bg-white">
        <DraftingThread
          defaultSettingsOpen
          generationSettingsLabel={
            values.toneId
              ? `Tone: ${
                  toneOptions.find((option) => option.id === values.toneId)
                    ?.label ?? values.toneId
                }`
              : "Tone: Not set"
          }
          hasUnsavedGenerationSettings={values.toneId !== ""}
          generationSettings={
            <GenerationSettingsPanel
              values={values}
              toneOptions={toneOptions}
              onChange={setValues}
              onSave={async () => {}}
              hasChanges={values.toneId !== ""}
              isSaving={false}
            />
          }
        />
      </div>
    </AssistantRuntimeProvider>
  );
}

export const InDraftingChat: Story = {
  render: () => <DraftingChatPreview />,
};
