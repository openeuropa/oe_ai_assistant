import type { Meta, StoryObj } from "@storybook/react-vite";
import { ArtifactPane } from "../../../src/plugins/drafting/components/artifact-pane";
import {
  draftingSliceConfig,
  setDraftingState,
} from "../../../src/plugins/drafting/store";

const meta = {
  title: "Drafting/Artifact pane",
  component: ArtifactPane,
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta<typeof ArtifactPane>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Placeholder pane body so the stories focus on the pane chrome. */
function PaneBody() {
  return (
    <div className="flex min-h-0 flex-1 items-center justify-center p-8 text-sm text-gray-400">
      Pane content
    </div>
  );
}

/** Seeds the drafting slice with the given collapse state. */
function seedCollapseState(isArtifactCollapsed: boolean): void {
  setDraftingState({
    ...draftingSliceConfig.initialState,
    isArtifactCollapsed,
  });
}

/** Expanded pane taking the artifact share of the workspace width. */
export const Expanded: Story = {
  decorators: [
    (Story) => {
      seedCollapseState(false);
      return <Story />;
    },
  ],
  render: () => (
    <div className="flex h-96 justify-end border border-gray-200 bg-white">
      <ArtifactPane canCollapse>
        <PaneBody />
      </ArtifactPane>
    </div>
  ),
};

/** Collapsed pane: a slim rail with only the expand control. */
export const Collapsed: Story = {
  decorators: [
    (Story) => {
      seedCollapseState(true);
      return <Story />;
    },
  ],
  render: () => (
    <div className="flex h-96 justify-end border border-gray-200 bg-white">
      <ArtifactPane canCollapse>
        <PaneBody />
      </ArtifactPane>
    </div>
  ),
};
