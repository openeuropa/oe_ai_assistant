import type { Meta, StoryObj } from "@storybook/react-vite";
import {
  FullDraftingPreview,
  seedDraftingPreviewState,
} from "./full-drafting-preview";

const meta = {
  title: "Drafting/Full plugin",
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

/**
 * The complete drafting plugin surface: seeded chat transcript with draft
 * cards and event chips, composer tabs, and the artifact pane showing the
 * latest draft. See full-drafting-preview.tsx for the composition.
 */
export const FullPlugin: Story = {
  decorators: [
    (Story) => {
      seedDraftingPreviewState();
      return <Story />;
    },
  ],
  render: () => (
    <div className="flex h-screen min-h-0 flex-col">
      <FullDraftingPreview />
    </div>
  ),
};
