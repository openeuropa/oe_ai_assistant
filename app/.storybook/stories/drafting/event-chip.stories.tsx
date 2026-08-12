import type { Meta, StoryObj } from "@storybook/react-vite";
import { EventChip } from "../../../src/plugins/drafting/components/event-chip";

const meta = {
  title: "Drafting/Event chip",
  component: EventChip,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof EventChip>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Tone change event with a Megaphone icon. */
export const Tone: Story = {
  args: {
    eventType: "tone",
    summary: "Tone changed to Formal",
    at: "2026-08-12T10:30:00Z",
  },
};

/** Template change event with a LayoutTemplate icon. */
export const Template: Story = {
  args: {
    eventType: "template",
    summary: "Template changed to Press Release",
    at: "2026-08-12T10:31:00Z",
  },
};

/** Session start event with a Sparkles icon. */
export const SessionStart: Story = {
  args: {
    eventType: "session_start",
    summary: "Editorial session started",
    at: "2026-08-12T10:29:00Z",
  },
};

/** Unknown event type falls back to an Info icon. */
export const Unknown: Story = {
  args: {
    eventType: "some_future_event",
    summary: "An unrecognised editorial event occurred",
  },
};
