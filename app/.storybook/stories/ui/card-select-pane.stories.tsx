import type { Meta, StoryObj } from "@storybook/react-vite";
import { Megaphone } from "lucide-react";
import { useState } from "react";
import { CardSelectPane } from "../../../src/components/ui/card-select-pane";

const options = [
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
  title: "UI/Card select pane",
  component: CardSelectPane,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof CardSelectPane>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Interactive selection; Save enables once the value changes. */
function Interactive() {
  const saved = options[0]?.value ?? "";
  const [value, setValue] = useState(saved);
  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <CardSelectPane
        icon={<Megaphone size={18} />}
        title="Tone"
        description="Save the selected tone before drafting to apply it."
        options={options}
        value={value}
        onChange={setValue}
        onSave={async () => {}}
        onCancel={() => setValue(saved)}
        hasChanges={value !== saved}
      />
    </div>
  );
}

export const Default: Story = {
  render: () => <Interactive />,
};

/** Save in progress: spinner shown, both actions disabled. */
export const Saving: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <CardSelectPane
        icon={<Megaphone size={18} />}
        title="Tone"
        description="Save the selected tone before drafting to apply it."
        options={options}
        value="formal"
        onChange={() => {}}
        onSave={async () => {}}
        onCancel={() => {}}
        hasChanges
        isSaving
      />
    </div>
  ),
};
