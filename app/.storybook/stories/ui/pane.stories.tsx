import type { Meta, StoryObj } from "@storybook/react-vite";
import { Megaphone, Sparkles } from "lucide-react";
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
        onSave={() => {}}
        onCancel={() => {}}
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
        onSave={() => {}}
        onCancel={() => {}}
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
