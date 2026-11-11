import type { Meta, StoryObj } from "@storybook/react-vite";
import { DraftCard } from "../../../src/plugins/drafting/components/draft-card";
import type {
  DraftContext,
  DraftGroupProgress,
  DraftGroupStatus,
} from "../../../src/plugins/drafting/draft-result";

const meta = {
  title: "Drafting/Draft card",
  component: DraftCard,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DraftCard>;

export default meta;
type Story = StoryObj<typeof meta>;

/** When the sample drafts were generated. */
const createdAt = new Date("2026-11-09T08:40:00Z");

/** The field groups of the sample content type, in the order it defines them. */
const NEWS_GROUPS = [
  { id: "main_fields", label: "Main fields" },
  { id: "content_paragraphs", label: "Content paragraphs" },
  { id: "categorisation", label: "Categorisation" },
];

/**
 * Builds the sample groups, one status per group.
 *
 * The error is attached to the first failed group, which is the only one that
 * can carry a message: a failure stops the run.
 */
function newsGroups(
  statuses: DraftGroupStatus[],
  error?: string,
): DraftGroupProgress[] {
  return NEWS_GROUPS.map((group, index) => ({
    ...group,
    status: statuses[index] ?? "pending",
    ...(statuses[index] === "failed" && error ? { error } : {}),
  }));
}

/** The message a schema miss records, which the popover shows. */
const schemaError =
  "The drafter answered four times and no answer matched the schema of the " +
  'group. The last answer was missing "field_paragraphs" and gave ' +
  '"field_teaser" as an array where the schema asks for a string.';

/** Full editorial context: tone, template, and several documents per category. */
const fullContext: DraftContext = {
  tone: { id: "technical", label: "Technical" },
  template: {
    id: "news-with-paragraphs",
    label: "News article with paragraphs",
  },
  documents: [
    {
      id: "doc-1",
      title: "Circular Economy Action Plan",
      category: "context",
      summary:
        "The 2020 action plan sets out more than thirty measures aimed at making sustainable products the norm in the European Union, covering the entire lifecycle from design and manufacturing to consumption, repair, reuse, and recycling. It targets the sectors that use the most resources and where the potential for circularity is highest: electronics and ICT, batteries and vehicles, packaging, plastics, textiles, construction, and food. The plan also introduces a sustainable product policy framework with ecodesign requirements, empowers consumers through a right to repair, and outlines how circularity feeds into the Union's 2050 climate neutrality objective and the halving of residual municipal waste by 2030.",
      file: {
        url: "https://example.com/files/circular-economy-action-plan.pdf",
        name: "circular-economy-action-plan.pdf",
        mime: "application/pdf",
        size: 2489344,
      },
    },
    {
      id: "doc-2",
      title: "Impact assessment 2025",
      category: "context",
      summary:
        "This assessment quantifies the expected economic and environmental effects of the proposed recycling targets across all member states over the 2025 to 2035 horizon. The central scenario projects a net gain of roughly 700 thousand jobs in collection, sorting, and reprocessing activities, a twelve percent reduction in primary raw material imports, and avoided emissions equivalent to taking eleven million cars off the road annually. The document details the modelling assumptions, the sensitivity analysis around commodity price volatility, and the distributional effects on small and medium enterprises, which face proportionally higher compliance costs during the first three years of the transition.",
      file: {
        url: "https://example.com/files/impact-assessment-2025.pdf",
        name: "impact-assessment-2025.pdf",
        mime: "application/pdf",
        size: 11534336,
      },
    },
    {
      id: "doc-3",
      title: "Stakeholder consultation notes",
      category: "context",
      summary:
        "Consolidated notes from four consultation rounds held between January and April with industry federations, environmental NGOs, municipal waste operators, and consumer organisations. Industry representatives broadly supported harmonised ecodesign rules but asked for longer transition periods and clearer definitions of recycled content; NGOs pressed for binding reuse targets and criticised the exemptions foreseen for exported waste streams. Municipalities highlighted the funding gap for separate collection infrastructure in rural areas, and consumer groups focused on repairability scoring and the affordability of spare parts. Points of convergence and open disagreements are marked per topic to support the drafting team.",
      file: {
        url: "https://example.com/files/stakeholder-consultation-notes.docx",
        name: "stakeholder-consultation-notes.docx",
        mime: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        size: 348160,
      },
    },
    {
      id: "doc-4",
      title: "Commission press release draft",
      category: "publishable",
      summary:
        "An early draft of the official press release prepared by the spokesperson service for editorial review. It leads with the headline recycling targets, quotes the Executive Vice-President on the competitiveness benefits of circular value chains, and closes with the legislative next steps in Parliament and Council. The middle section still contains two alternative formulations of the SME support paragraph, flagged for a decision by the communication unit, and the figures in the fourth paragraph must be reconciled with the final impact assessment before publication.",
      file: {
        url: "https://example.com/files/press-release-draft.docx",
        name: "press-release-draft.docx",
        mime: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        size: 96256,
      },
    },
  ],
};

/** Tone and template only, which is the common setup. */
const plainContext: DraftContext = {
  tone: fullContext.tone,
  template: fullContext.template,
  documents: [],
};

/**
 * Documents whose titles are file names, which carry no space to wrap on.
 *
 * Real uploads arrive named this way, so the card has to cut them rather than
 * let one long word widen the chat.
 */
const fileNameContext: DraftContext = {
  tone: fullContext.tone,
  template: fullContext.template,
  documents: fullContext.documents.map((doc, index) => ({
    ...doc,
    title: [
      "circular_economy_action_plan_2020_consolidated_final_version.pdf",
      "impact_assessment_2025_annex_III_member_state_projections.pdf",
      "stakeholder_consultation_notes_rounds_one_to_four_merged.docx",
      "commission_press_release_draft_for_editorial_review_v7.docx",
    ][index] as string,
  })),
};

/** Wraps a story in the chat well, which gives a card its whole width. */
function Frame({ children }: { children: React.ReactNode }) {
  return <div className="w-full bg-gray-50 px-4">{children}</div>;
}

/** Wraps a story in a chat well narrow enough to cut a long file name. */
function NarrowFrame({ children }: { children: React.ReactNode }) {
  return <div className="max-w-sm bg-gray-50 px-4">{children}</div>;
}

/**
 * The first frame: the run has named its groups and started on none of them.
 *
 * Tone, template and documents are known before the run starts, so they are
 * already filled in. Created waits for the stored draft.
 */
export const JustStarted: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={fullContext}
        groups={newsGroups(["pending", "pending", "pending"])}
        status="running"
        isSaved={false}
        createdAt={null}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** Mid-run: one group written, one being written, one still to come. */
export const Drafting: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={fullContext}
        groups={newsGroups(["drafted", "drafting", "pending"])}
        status="running"
        isSaved={false}
        createdAt={null}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** The last group has landed. This is what a reload rebuilds. */
export const Drafted: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={fullContext}
        groups={newsGroups(["drafted", "drafted", "drafted"])}
        status="drafted"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** The version has been saved as a revision, so the header icon is a tick. */
export const Saved: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={fullContext}
        groups={newsGroups(["drafted", "drafted", "drafted"])}
        status="drafted"
        isSaved={true}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/**
 * A group failed, which stops the run.
 *
 * Click the red cross on the row to read what the run recorded. The groups
 * after it stay pending, because the run never reached them.
 */
export const GroupFailed: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={fullContext}
        groups={newsGroups(["drafted", "failed", "pending"], schemaError)}
        status="failed"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/**
 * The run failed before it reached a group, so no row carries the cross.
 *
 * The header cross opens the message instead. A content type with no field
 * group fails this way.
 */
export const RunFailed: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={2}
        context={plainContext}
        groups={[]}
        status="failed"
        error='The "oe_news" content type has no field group to draft.'
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** A content type of one group, drafted. */
export const SingleGroup: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={1}
        context={plainContext}
        groups={[
          { id: "main_fields", label: "Main fields", status: "drafted" },
        ]}
        status="drafted"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** A content type of many groups, part way through a run. */
export const ManyGroups: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={1}
        context={plainContext}
        groups={[
          { id: "main_fields", label: "Main fields", status: "drafted" },
          { id: "teaser", label: "Teaser", status: "drafted" },
          {
            id: "content_paragraphs",
            label: "Content paragraphs",
            status: "drafted",
          },
          { id: "related", label: "Related content", status: "drafting" },
          { id: "categorisation", label: "Categorisation", status: "pending" },
          { id: "contacts", label: "Contacts", status: "pending" },
          {
            id: "publication",
            label: "Publication details",
            status: "pending",
          },
        ]}
        status="running"
        isSaved={false}
        createdAt={null}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** The editor set no tone and no template, so the card carries neither row. */
export const NoContext: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={1}
        context={{ tone: null, template: null, documents: [] }}
        groups={newsGroups(["drafted", "drafting", "pending"])}
        status="running"
        isSaved={false}
        createdAt={null}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/** A draft stored before groups were recorded, which has no rows to show. */
export const LegacyDraft: Story = {
  render: () => (
    <Frame>
      <DraftCard
        version={null}
        context={plainContext}
        groups={[]}
        status="drafted"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </Frame>
  ),
};

/**
 * Context documents named the way an upload names them.
 *
 * An underscore is no break opportunity, so each link is cut to the width of
 * its column. The whole name is on the link as a tooltip, and the detail
 * dialog carries it in full.
 */
export const FileNameDocuments: Story = {
  render: () => (
    <NarrowFrame>
      <DraftCard
        version={2}
        context={fileNameContext}
        groups={newsGroups(["drafted", "drafted", "drafted"])}
        status="drafted"
        isSaved={false}
        createdAt={createdAt}
        onOpen={() => {}}
      />
    </NarrowFrame>
  ),
};
