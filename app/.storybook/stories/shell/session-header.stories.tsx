import type { Meta, StoryObj } from "@storybook/react-vite";
import { setConfig } from "../../../src/config";
import { developmentConfig } from "../../../src/development-config";
import { SessionHeader } from "../../../src/shell/session-header";

const meta = {
  title: "Shell/Session header",
  component: SessionHeader,
  parameters: {
    layout: "fullscreen",
  },
} satisfies Meta<typeof SessionHeader>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Header with a session title supplied by the host config. */
export const WithTitle: Story = {
  decorators: [
    (Story) => {
      setConfig({ ...developmentConfig, sessionTitle: "March newsletter" });
      return <Story />;
    },
  ],
};

/** Header falling back to the neutral title when no title is supplied. */
export const FallbackTitle: Story = {
  decorators: [
    (Story) => {
      setConfig({ ...developmentConfig, sessionTitle: "" });
      return <Story />;
    },
  ],
};
