import type { Meta, StoryObj } from "@storybook/react-vite";
import { PenLine } from "lucide-react";
import { DraftCard } from "../../../src/plugins/drafting/components/draft-card";
import { ToolCallCard } from "../../../src/plugins/drafting/components/tool-uis";
import type {
  DraftContext,
  DraftGroupProgress,
} from "../../../src/plugins/drafting/draft-result";

/**
 * The drafting tool call as the chat thread shows it: the tool call card, and
 * the draft card the run fills in under it. The pair is what the editor sees
 * live, and what a reload rebuilds.
 */
const meta = {
  title: "Drafting/Drafting tool call",
  component: DraftCard,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DraftCard>;

export default meta;
type Story = StoryObj<typeof meta>;

/** When the sample draft was generated. */
const createdAt = new Date("2026-11-09T08:40:00Z");

/** Tone and template the editor set before asking for the draft. */
const context: DraftContext = {
  tone: { id: "technical", label: "Technical" },
  template: {
    id: "news-with-paragraphs",
    label: "News article with paragraphs",
  },
  documents: [],
};

/** The field groups of the sample content type, with the given statuses. */
function groups(
  main: DraftGroupProgress["status"],
  paragraphs: DraftGroupProgress["status"],
  categorisation: DraftGroupProgress["status"],
  error?: string,
): DraftGroupProgress[] {
  return [
    { id: "main_fields", label: "Main fields", status: main },
    {
      id: "content_paragraphs",
      label: "Content paragraphs",
      status: paragraphs,
      ...(paragraphs === "failed" && error ? { error } : {}),
    },
    { id: "categorisation", label: "Categorisation", status: categorisation },
  ];
}

/** Wraps a story in the chat well, which gives a card its whole width. */
function Thread({ children }: { children: React.ReactNode }) {
  return <div className="w-full bg-gray-50 px-4 py-2">{children}</div>;
}

/** The run is writing the second group. */
export const Running: Story = {
  render: () => (
    <Thread>
      <ToolCallCard
        icon={PenLine}
        label="Drafting content"
        status={{ type: "running" }}
      />
      <DraftCard
        version={2}
        context={context}
        groups={groups("drafted", "drafting", "pending")}
        status="running"
        isSaved={false}
        createdAt={null}
        onOpen={() => {}}
      />
    </Thread>
  ),
};

/** The run is done. The chat message under the pair tells the editor so. */
export const Drafted: Story = {
  render: () => (
    <Thread>
      <ToolCallCard
        icon={PenLine}
        label="Drafting content"
        status={{ type: "complete" }}
      />
      <DraftCard
        version={2}
        context={context}
        groups={groups("drafted", "drafted", "drafted")}
        status="drafted"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
      <p className="text-gray-900">
        The draft "Draft 2" is ready, and you can review it on the right.
      </p>
    </Thread>
  ),
};

/** A group failed, so the tool call card carries the error state as well. */
export const Failed: Story = {
  render: () => (
    <Thread>
      <ToolCallCard
        icon={PenLine}
        label="Drafting content"
        status={{ type: "incomplete" }}
      />
      <DraftCard
        version={2}
        context={context}
        groups={groups(
          "drafted",
          "failed",
          "pending",
          "The drafter answered four times and no answer matched the schema " +
            'of the group. The last answer was missing "field_paragraphs".',
        )}
        status="failed"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
      <p className="text-gray-900">
        I could not draft the content paragraphs. Check the template and ask me
        again.
      </p>
    </Thread>
  ),
};
