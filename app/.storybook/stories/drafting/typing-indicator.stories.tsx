import type { Meta, StoryObj } from "@storybook/react-vite";
import { TypingIndicator } from "../../../src/plugins/drafting/components/drafting-thread";

const meta = {
  title: "Drafting/Typing indicator",
  component: TypingIndicator,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof TypingIndicator>;

export default meta;
type Story = StoryObj<typeof meta>;

/** The three bouncing dots shown while the assistant is processing. */
export const Bouncing: Story = {
  render: () => (
    <div className="bg-gray-50 p-6">
      <TypingIndicator />
    </div>
  ),
};
