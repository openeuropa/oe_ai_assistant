import type { Meta, StoryObj } from "@storybook/react-vite";
import { DraftPreview } from "../../../src/plugins/drafting/components/draft-preview";
import { seedDraftingPreviewState } from "./full-drafting-preview";

const meta = {
  title: "Drafting/Draft preview",
  component: DraftPreview,
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta<typeof DraftPreview>;

export default meta;
type Story = StoryObj<typeof meta>;

/**
 * Wraps the pane in a fixed-height container mirroring the artifact
 * pane share of the workspace. The drafting store is seeded with the
 * shared preview fixture so the Data tab has fields to show; the
 * iframe URL template comes from the development config loaded in
 * .storybook/preview.ts.
 */
function renderPane(args: Story["args"]) {
  seedDraftingPreviewState();
  return (
    <div className="flex h-[600px] justify-end border border-gray-200 bg-white">
      <div className="flex w-1/2 min-h-0 flex-col border-l border-gray-200">
        <DraftPreview {...args} />
      </div>
    </div>
  );
}

/** Default view: the live preview iframe with its loading spinner. */
export const LivePreview: Story = {
  args: {
    sessionId: "dev-session",
    versionId: 8,
    onSave: () => {},
  },
  render: renderPane,
};

/** The raw field table, opened through the Data tab. */
export const DataTab: Story = {
  args: {
    sessionId: "dev-session",
    versionId: 8,
    defaultTab: "data",
    onSave: () => {},
  },
  render: renderPane,
};
