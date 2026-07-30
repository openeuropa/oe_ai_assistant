import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, Megaphone } from "lucide-react";
import { PaneTab } from "../../../src/components/ui/pane-tab";

const meta = {
  title: "UI/Pane tab",
  component: PaneTab,
  args: {
    icon: <Megaphone size={16} />,
    title: "Tone",
    summary: "Clear and professional",
    active: false,
    onClick: () => {},
  },
  parameters: {
    layout: "padded",
  },
  // The tab stretches to fill its row, so constrain it for the stories.
  decorators: [
    (Story) => (
      <div className="flex w-64 border border-gray-200">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof PaneTab>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Inactive: Story = {};

export const Active: Story = {
  args: { active: true },
};

/** Documents tab where the summary is a count. */
export const DocumentsCount: Story = {
  args: {
    icon: <FileText size={16} />,
    title: "Documents",
    summary: "3 documents",
  },
};

/** A tab with no current selection yet. */
export const NoSummary: Story = {
  args: {
    title: "Templates",
    summary: undefined,
  },
};
