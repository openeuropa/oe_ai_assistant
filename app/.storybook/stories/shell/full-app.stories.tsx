import type { Meta, StoryObj } from "@storybook/react-vite";
import { MemoryRouter } from "react-router-dom";
import { setConfig } from "../../../src/config";
import { developmentConfig } from "../../../src/development-config";
import { Nav } from "../../../src/shell/nav";
import { SessionHeader } from "../../../src/shell/session-header";
import {
  FullDraftingPreview,
  seedDraftingPreviewState,
} from "../drafting/full-drafting-preview";

const meta = {
  title: "Shell/Full app",
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta;

export default meta;
type Story = StoryObj<typeof meta>;

/**
 * Replicates the App shell layout (session header, plugin sidebar,
 * viewport) around the full drafting plugin preview. A memory router on
 * the drafting route stands in for the app's hash router so the sidebar
 * resolves the active plugin.
 */
function FullAppPreview() {
  return (
    <MemoryRouter initialEntries={["/drafting"]}>
      <div className="flex h-screen flex-col overflow-hidden bg-white">
        {/* Shell-owned session frame spanning the full workspace width. */}
        <SessionHeader />
        <div className="flex min-h-0 flex-1 overflow-hidden">
          <Nav />
          <main className="flex flex-1 flex-col overflow-hidden">
            <FullDraftingPreview />
          </main>
        </div>
      </div>
    </MemoryRouter>
  );
}

/** The whole workspace as mounted by the host page. */
export const FullApp: Story = {
  decorators: [
    (Story) => {
      // Give the session a realistic title and exit target, then seed the
      // drafting slice so the artifact pane has content.
      setConfig({
        ...developmentConfig,
        sessionTitle: "Content creation: EU AI Act news article",
        exitUrl: "/",
      });
      seedDraftingPreviewState();
      return <Story />;
    },
  ],
  render: () => <FullAppPreview />,
};
