import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { Pane } from "../../../src/components/ui/pane";
import {
  type PaneTabItem,
  PaneTabs,
} from "../../../src/components/ui/pane-tabs";
import { RadioCardGroup } from "../../../src/components/ui/radio-card-group";

const tabs: PaneTabItem[] = [
  {
    id: "tone",
    icon: <Megaphone size={16} />,
    title: "Tone",
    summary: "Clear and professional",
    render: (close) => (
      <Pane
        icon={<Megaphone size={18} />}
        title="Tone"
        description="Save the selected tone before drafting to apply it."
        onSave={() => {}}
        onCancel={close}
      >
        <RadioCardGroup
          name="story-tone"
          options={[
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
          ]}
          value="clear-professional"
          onChange={() => {}}
        />
      </Pane>
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
        onSave={() => {}}
        onCancel={close}
      >
        <p className="text-sm text-gray-600">Document list goes here.</p>
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
        onSave={() => {}}
        onCancel={close}
      >
        <p className="text-sm text-gray-600">Template picker goes here.</p>
      </Pane>
    ),
  },
];

const meta = {
  title: "UI/Pane tabs",
  component: PaneTabs,
  args: { tabs },
  parameters: {
    layout: "padded",
  },
  // Give the overlay room to float above the bar, like the real composer.
  decorators: [
    (Story) => (
      <div className="flex max-w-2xl flex-col justify-end pt-72">
        <div className="border border-gray-200 bg-white">
          <Story />
          {/* Stand-in for the prompt input under the tabs. */}
          <div className="p-4 pt-3">
            <div className="h-9 rounded-lg border border-gray-300" />
          </div>
        </div>
      </div>
    ),
  ],
} satisfies Meta<typeof PaneTabs>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Closed: Story = {};

export const ToneOpen: Story = {
  args: { defaultActiveId: "tone" },
};

export const DocumentsOpen: Story = {
  args: { defaultActiveId: "documents" },
};
