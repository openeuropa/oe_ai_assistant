import type { Meta, StoryObj } from "@storybook/react-vite";
import { expect, userEvent, waitFor, within } from "storybook/test";
import { setConfig } from "../../../src/config";
import { developmentConfig } from "../../../src/development-config";
import { ExitControl } from "../../../src/shell/exit-control";
import { useAppStore } from "../../../src/store";

const meta = {
  title: "Shell/Exit control",
  component: ExitControl,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof ExitControl>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Idle session: exit shows the saved confirmation dialog. */
export const Idle: Story = {
  decorators: [
    (Story) => {
      setConfig({ ...developmentConfig, exitUrl: "/dashboard" });
      useAppStore.setState({ pendingWork: {} });
      return <Story />;
    },
  ],
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await userEvent.click(
      await canvas.findByRole("button", { name: /exit session/i }),
    );

    // The dialog renders in a portal, so query the whole document.
    const body = within(canvasElement.ownerDocument.body);
    await waitFor(async () => {
      await expect(
        body.getByText(/your session has been saved/i),
      ).toBeVisible();
    });
  },
};

/** Pending work reported: exit is blocked with a wait prompt. */
export const BlockedWhilePending: Story = {
  decorators: [
    (Story) => {
      setConfig({ ...developmentConfig, exitUrl: "/dashboard" });
      useAppStore.setState({ pendingWork: { drafting: true } });
      return <Story />;
    },
  ],
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await userEvent.click(
      await canvas.findByRole("button", { name: /exit session/i }),
    );

    // The dialog renders in a portal, so query the whole document.
    const body = within(canvasElement.ownerDocument.body);
    await waitFor(async () => {
      await expect(
        body.getByText(/the assistant is still working/i),
      ).toBeVisible();
    });
  },
};

/** No exit URL from the host: the control does not render. */
export const HiddenWithoutExitUrl: Story = {
  decorators: [
    (Story) => {
      setConfig({ ...developmentConfig, exitUrl: "" });
      useAppStore.setState({ pendingWork: {} });
      return <Story />;
    },
  ],
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await expect(
      canvas.queryByRole("button", { name: /exit session/i }),
    ).not.toBeInTheDocument();
  },
};
