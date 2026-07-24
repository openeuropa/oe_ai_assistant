import type { Meta, StoryObj } from "@storybook/react-vite";
import { Megaphone, Sparkles, X } from "lucide-react";
import { Pane } from "../../../src/components/ui/pane";
import { RadioCardGroup } from "../../../src/components/ui/radio-card-group";

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
  {
    value: "concise",
    label: "Concise",
    description: "Trim every sentence to its essentials.",
  },
];

/** Placeholder buttons so the actions slot is visible in the stories. */
function ExampleActions() {
  return (
    <>
      <button
        type="button"
        className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-100"
      >
        <X size={15} />
        Cancel
      </button>
      <button
        type="button"
        className="inline-flex h-9 cursor-pointer items-center rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700"
      >
        Save
      </button>
    </>
  );
}

const meta = {
  title: "UI/Pane",
  component: Pane,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof Pane>;

export default meta;
type Story = StoryObj<typeof meta>;

/** The pane wrapping a card selection, as used by the tone settings. */
export const WithCardSelection: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <Pane
        icon={<Megaphone size={18} />}
        title="Tone"
        description="Save the selected tone before drafting to apply it."
        actions={<ExampleActions />}
      >
        <RadioCardGroup
          name="pane-tone"
          options={toneOptions}
          value="clear-professional"
          onChange={() => {}}
        />
      </Pane>
    </div>
  ),
};

/** Minimal pane with just a title and body, no icon or actions. */
export const TitleOnly: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <Pane title="Settings">
        <p className="text-sm text-gray-600">Any pane content goes here.</p>
      </Pane>
    </div>
  ),
};

/** A second pane variant to show the chrome is reusable. */
export const DifferentPane: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <Pane
        icon={<Sparkles size={18} />}
        title="Audience"
        description="Choose who the draft is written for."
        actions={<ExampleActions />}
      >
        <RadioCardGroup
          name="pane-audience"
          options={[
            {
              value: "general",
              label: "General public",
              description: "Plain language for a broad readership.",
            },
            {
              value: "expert",
              label: "Experts",
              description: "Assumes domain knowledge and precise terms.",
            },
          ]}
          value="general"
          onChange={() => {}}
        />
      </Pane>
    </div>
  ),
};
