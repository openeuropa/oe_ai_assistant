import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";
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
  {
    value: "authoritative",
    label: "Authoritative",
    description: "Confident and expert, backed by evidence.",
  },
];

const meta = {
  title: "UI/Radio card group",
  component: RadioCardGroup,
  args: {
    name: "example",
    options: toneOptions,
    value: toneOptions[0]?.value ?? "",
    onChange: () => {},
  },
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof RadioCardGroup>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Interactive wrapper so the cards respond to selection. */
function InteractiveGroup({ initialValue }: { initialValue?: string }) {
  const [value, setValue] = useState(
    initialValue ?? toneOptions[0]?.value ?? "",
  );
  return (
    <div className="max-w-2xl">
      <RadioCardGroup
        name="example"
        options={toneOptions}
        value={value}
        onChange={setValue}
      />
    </div>
  );
}

export const Default: Story = {
  render: () => <InteractiveGroup />,
};

export const SecondSelected: Story = {
  render: () => <InteractiveGroup initialValue="formal" />,
};

/** A single column via the className override. */
export const SingleColumn: Story = {
  render: () => {
    const [value, setValue] = useState(toneOptions[0]?.value ?? "");
    return (
      <div className="max-w-md">
        <RadioCardGroup
          name="example-single"
          options={toneOptions}
          value={value}
          onChange={setValue}
          className="sm:grid-cols-1"
        />
      </div>
    );
  },
};

export const Disabled: Story = {
  render: () => (
    <div className="max-w-2xl">
      <RadioCardGroup
        name="example-disabled"
        options={toneOptions}
        value="clear-professional"
        onChange={() => {}}
        disabled
      />
    </div>
  ),
};
