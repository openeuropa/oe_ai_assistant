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

/** Full editorial context: tone, template, and several documents per category. */
const fullContext: DraftContext = {
  tone: { id: "formal", label: "Formal" },
  template: { id: "press-release", label: "Press Release" },
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
    {
      id: "doc-5",
      title: "Hero image: recycling facility",
      category: "publishable",
      summary:
        "Approved photograph for the article header showing the sorting line of a materials recovery facility near Rotterdam, taken during the January press visit. The image is cleared for editorial use across all Union channels, includes the photographer credit in its embedded metadata, and a cropped 16 by 9 variant optimised for social media cards is available from the media library under the same reference number.",
      file: {
        url: "https://example.com/files/hero-recycling-facility.jpg",
        name: "hero-recycling-facility.jpg",
        mime: "image/jpeg",
        size: 4718592,
      },
    },
    {
      id: "doc-6",
      title: "Explainer clip",
      category: "publishable",
      summary:
        "Thirty second explainer video produced for social media that walks through the journey of a plastic bottle from separate collection to food-grade recycled packaging. Subtitled versions exist in all official languages, the master file is delivered in 4K with a separate audio stem for re-voicing, and the clip ends on the campaign hashtag frame required by the visual identity guidelines for this initiative.",
      file: {
        url: "https://example.com/files/explainer-clip.mp4",
        name: "explainer-clip.mp4",
        mime: "video/mp4",
        size: 87031808,
      },
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
