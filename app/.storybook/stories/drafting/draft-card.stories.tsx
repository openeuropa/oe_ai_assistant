import type { Meta, StoryObj } from "@storybook/react-vite";
import { DraftCard } from "../../../src/plugins/drafting/components/draft-card";
import type { DraftContext } from "../../../src/plugins/drafting/draft-result";

const meta = {
  title: "Drafting/Draft card",
  component: DraftCard,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DraftCard>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Sample fields shared across stories. */
const sampleFields = {
  title: "New approach to circular economy in the EU",
  body: "The European Commission has proposed new measures...",
  summary: "A concise overview of the circular economy package.",
};

/** Full editorial context: tone, template, and two documents of both categories. */
const fullContext: DraftContext = {
  tone: { id: "formal", label: "Formal" },
  template: { id: "press-release", label: "Press Release" },
  documents: [
    {
      id: "doc-1",
      title: "Circular Economy Action Plan",
      category: "context",
      summary:
        "The 2020 action plan sets out measures for a more sustainable product lifecycle.",
      meta: { pages: "24", language: "EN" },
    },
    {
      id: "doc-2",
      title: "Commission press release draft",
      category: "publishable",
      summary:
        "An early draft of the official press release for editorial review.",
      meta: "Internal working document",
    },
  ],
};

/** Draft card with full editorial context: tone, template, and two documents. */
export const FullContext: Story = {
  render: () => (
    <div className="max-w-md">
      <DraftCard
        version={3}
        context={fullContext}
        fields={sampleFields}
        onOpen={() => {}}
      />
    </div>
  ),
};

/** Draft card with a tone but no template and no documents. */
export const ToneOnly: Story = {
  render: () => (
    <div className="max-w-md">
      <DraftCard
        version={1}
        context={{
          tone: { id: "conversational", label: "Conversational" },
          template: null,
          documents: [],
        }}
        fields={sampleFields}
        onOpen={() => {}}
      />
    </div>
  ),
};

/** Legacy draft with no version and no context (pre-provenance tracking). */
export const Legacy: Story = {
  render: () => (
    <div className="max-w-md">
      <DraftCard
        version={null}
        context={null}
        fields={sampleFields}
        onOpen={() => {}}
      />
    </div>
  ),
};
