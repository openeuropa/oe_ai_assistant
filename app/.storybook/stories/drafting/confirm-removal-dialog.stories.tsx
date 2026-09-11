import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";
import { expect, userEvent, waitFor, within } from "storybook/test";
import { Button } from "../../../src/components/ui/button";
import { ConfirmRemovalDialog } from "../../../src/plugins/drafting/components/confirm-removal-dialog";

const meta = {
  title: "Drafting/Confirm removal dialog",
  component: ConfirmRemovalDialog,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof ConfirmRemovalDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Text props shared by every story, as the documents panel passes them. */
const contextDocumentTexts = {
  title: "Remove context document",
  message:
    '"EU AI Act briefing note.pdf" will be deleted and would need to be uploaded again to feed the drafting context.',
  confirmLabel: "Delete document",
};

/** Idle confirmation: message plus Cancel and destructive confirm. */
export const Idle: Story = {
  args: {
    ...contextDocumentTexts,
    open: true,
    onConfirm: async () => {},
    onCancel: () => {},
  },
};

/** In-flight removal: spinner on the confirm button, controls locked. */
export const Removing: Story = {
  args: {
    ...contextDocumentTexts,
    open: true,
    // Never resolves, freezing the dialog in its busy state.
    onConfirm: () => new Promise<void>(() => {}),
    onCancel: () => {},
  },
  play: async ({ canvasElement }) => {
    // The dialog renders in a portal, so query the whole document.
    const body = within(canvasElement.ownerDocument.body);
    await userEvent.click(
      await body.findByRole("button", { name: /delete document/i }),
    );
    await waitFor(async () => {
      await expect(
        body.getByRole("button", { name: /delete document/i }),
      ).toBeDisabled();
    });
  },
};

/** Failed removal: the dialog stays open and shows the error. */
export const Failed: Story = {
  args: {
    ...contextDocumentTexts,
    open: true,
    onConfirm: async () => {
      throw new Error("Drafting remove-document error: 500");
    },
    onCancel: () => {},
  },
  play: async ({ canvasElement }) => {
    // The dialog renders in a portal, so query the whole document.
    const body = within(canvasElement.ownerDocument.body);
    await userEvent.click(
      await body.findByRole("button", { name: /delete document/i }),
    );
    await waitFor(async () => {
      await expect(body.getByText(/remove-document error: 500/i)).toBeVisible();
    });
  },
};

/**
 * Full transition playground: open the dialog, watch the spinner for a
 * second, then see it close on success. Reopen to replay.
 */
function InteractiveRemoval() {
  const [open, setOpen] = useState(false);
  const [removed, setRemoved] = useState(false);

  return (
    <div className="space-y-3">
      <Button
        variant="outline"
        className="cursor-pointer"
        onClick={() => setOpen(true)}
      >
        Remove document
      </Button>
      {removed && (
        <p className="text-xs text-gray-600">
          Document removed. Click again to replay the flow.
        </p>
      )}
      <ConfirmRemovalDialog
        {...contextDocumentTexts}
        open={open}
        onConfirm={async () => {
          // Simulated backend latency before the success path closes.
          await new Promise((resolve) => setTimeout(resolve, 1200));
          setRemoved(true);
          setOpen(false);
        }}
        onCancel={() => setOpen(false)}
      />
    </div>
  );
}

export const SuccessFlow: Story = {
  args: {
    ...contextDocumentTexts,
    open: false,
    onConfirm: async () => {},
    onCancel: () => {},
  },
  render: () => <InteractiveRemoval />,
};
