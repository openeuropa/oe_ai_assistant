import type { Meta, StoryObj } from "@storybook/react-vite";
import { PaneBusyOverlay } from "../../../src/plugins/drafting/components/pane-busy-overlay";

/**
 * The surface that covers an open artifact pane while the run writes the next
 * draft, so the editor never reads the draft underneath as the current one.
 */
const meta = {
  title: "Drafting/Pane busy overlay",
  component: PaneBusyOverlay,
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta<typeof PaneBusyOverlay>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Stands in for the pane body the overlay covers. */
function PaneBody() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold text-gray-900">
        Innovations in Quantum Computing Enhance Cryptographic Security
      </h1>
      <p className="mt-4 text-gray-700">
        Recent advancements in quantum computing bring significant improvements
        to cryptographic systems, paving the way for more robust security
        protocols.
      </p>
    </div>
  );
}

/** The overlay over the pane body. */
export const Busy: Story = {
  render: () => (
    <div className="relative h-96 w-full border-l border-gray-200 bg-white">
      <PaneBody />
      <PaneBusyOverlay />
    </div>
  ),
};

/** The same pane without the overlay, for comparison. */
export const Idle: Story = {
  render: () => (
    <div className="relative h-96 w-full border-l border-gray-200 bg-white">
      <PaneBody />
    </div>
  ),
};
