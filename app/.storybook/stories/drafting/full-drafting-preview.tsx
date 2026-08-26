/**
 * Shared full drafting plugin preview for stories.
 *
 * Composes the complete drafting plugin UI: the chat thread seeded with a
 * realistic transcript (event chips, versioned draft cards, a save tool
 * call), the composer tabs, and the artifact pane showing the latest
 * draft. Mirrors the layout of the plugin root component with a local
 * mock runtime instead of the backend stream. Used by the "Full plugin"
 * drafting story and by the "Full app" shell story.
 */

import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import { FileText, LayoutTemplate, Megaphone } from "lucide-react";
import { useState } from "react";
import type { SessionMessage } from "../../../src/api/session-messages";
import { CardSelectPane } from "../../../src/components/ui/card-select-pane";
import { ArtifactPane } from "../../../src/plugins/drafting/components/artifact-pane";
import { ContentTable } from "../../../src/plugins/drafting/components/content-table";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import { DraftRail } from "../../../src/plugins/drafting/components/draft-rail";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import {
  DraftContentToolUI,
  EditorialEventToolUI,
  SaveDraftRevisionToolUI,
} from "../../../src/plugins/drafting/components/tool-uis";
import { useDraftingDocuments } from "../../../src/plugins/drafting/hooks/use-drafting-documents";
import { useDraftingTemplate } from "../../../src/plugins/drafting/hooks/use-drafting-template";
import { useReportPendingWork } from "../../../src/plugins/drafting/hooks/use-report-pending-work";
import { toThreadMessages } from "../../../src/plugins/drafting/hydrate-transcript";
import { useReportParticipants } from "../../../src/plugins/drafting/participants";
import {
  draftingSliceConfig,
  setDraftingState,
  useDraftingSlice,
} from "../../../src/plugins/drafting/store";

/**
 * Builds the Drupal-shaped field values for one draft version. Each
 * revision in the narrative gets clearly different content so switching
 * versions on the rail is visible at a glance.
 */
function draftFields(
  title: string,
  summary: string,
  bodyHtml: string,
): Record<string, unknown> {
  return {
    title: [{ value: title }],
    oe_summary: [{ value: `<p>${summary}</p>`, format: "full_html" }],
    body: [{ value: bodyHtml, format: "full_html" }],
    oe_publication_date: [{ value: "2026-08-19" }],
  };
}

/** Draft 1: Dev Editor's plain first draft. */
const v1Fields = draftFields(
  "New EU rules for artificial intelligence",
  "The EU introduces the first comprehensive rules for artificial intelligence.",
  "<p>The European Union introduces a comprehensive framework regulating " +
    "artificial intelligence across the single market. The rules follow a " +
    "risk-based approach, from minimal-risk systems with no obligations to " +
    "high-risk systems subject to strict requirements.</p>" +
    "<p>National authorities have twelve months to designate the bodies " +
    "overseeing conformity assessments.</p>",
);

/** Draft 2: punchier headline and a standfirst. */
const v2Fields = draftFields(
  "EU AI Act enters into force",
  "From today, the world's first comprehensive AI rulebook applies across " +
    "the Union, phasing in obligations by risk.",
  "<p>The countdown has started. As of today the AI Act is law across the " +
    "Union, and every provider placing an AI system on the European market " +
    "is on the clock to comply.</p>" +
    "<p>The rules follow a risk-based approach: minimal-risk systems face " +
    "no obligations, while high-risk systems must meet strict requirements " +
    "before entering the market.</p>",
);

/** Draft 3: reworked in the formal register. */
const v3Fields = draftFields(
  "EU AI Act enters into force",
  "The Artificial Intelligence Act applies across the Union as of today, " +
    "introducing obligations proportionate to risk.",
  "<p>The Regulation establishes a harmonised legal framework for the " +
    "development, placing on the market and use of artificial intelligence " +
    "systems in the Union. Obligations are proportionate to the risk an AI " +
    "system presents to health, safety and fundamental rights.</p>" +
    "<p>Member States shall designate national competent authorities " +
    "within twelve months of the entry into force.</p>",
);

/** Draft 4: Commissioner quote and the AI Office added. */
const v4Fields = draftFields(
  "EU AI Act enters into force",
  "The Artificial Intelligence Act applies across the Union as of today, " +
    "introducing obligations proportionate to risk.",
  "<p>The Regulation establishes a harmonised legal framework for the " +
    "development, placing on the market and use of artificial intelligence " +
    "systems in the Union.</p>" +
    '<p>"As of today, providers know exactly what is expected of them," ' +
    'said the Commissioner for Internal Market. "Trustworthy AI is now a ' +
    'legal standard, not a slogan."</p>' +
    "<p>A newly established AI Office will coordinate supervision and " +
    "enforcement across Member States.</p>",
);

/** Draft 5: legally corrected timeline and softened claims. */
const v5Fields = draftFields(
  "EU AI Act enters into force",
  "The Artificial Intelligence Act applies across the Union as of today, " +
    "with obligations phasing in over the coming years.",
  "<p>The Regulation establishes a harmonised legal framework for " +
    "artificial intelligence systems in the Union.</p>" +
    "<p>The prohibitions on unacceptable-risk practices apply six months " +
    "from today; obligations for general-purpose AI models follow at " +
    "twelve months, while most remaining duties phase in over twenty-four " +
    "months. Providers must prepare to comply as each deadline " +
    "approaches.</p>" +
    '<p>"As of today, providers know exactly what is expected of them," ' +
    "said the Commissioner for Internal Market.</p>",
);

/** Draft 6: restructured into three subheaded sections for the site. */
const v6Fields = draftFields(
  "EU AI Act enters into force",
  "The Artificial Intelligence Act applies across the Union as of today, " +
    "with obligations phasing in over the coming years.",
  "<h3>What changes today</h3>" +
    "<p>The AI Act is law across the Union. The entry into force starts " +
    "the compliance countdown for every provider and deployer of AI " +
    "systems on the European market.</p>" +
    "<h3>Who is affected</h3>" +
    '<p>Obligations are proportionate to risk. "As of today, providers ' +
    'know exactly what is expected of them," said the Commissioner for ' +
    "Internal Market.</p>" +
    "<h3>What comes next</h3>" +
    "<p>Prohibitions apply in six months, general-purpose AI obligations " +
    "in twelve, and most remaining duties within twenty-four months.</p>",
);

/** Draft 7: trimmed middle section and a bulleted timeline. */
const v7Fields = draftFields(
  "EU AI Act enters into force",
  "The Artificial Intelligence Act applies across the Union as of today, " +
    "with obligations phasing in over the coming years.",
  "<h3>What changes today</h3>" +
    "<p>The AI Act is law across the Union. The entry into force starts " +
    "the compliance countdown for every provider and deployer of AI " +
    "systems on the European market.</p>" +
    "<h3>Who is affected</h3>" +
    '<p>"As of today, providers know exactly what is expected of them," ' +
    "said the Commissioner for Internal Market.</p>" +
    "<h3>What comes next</h3>" +
    "<ul>" +
    "<li>February 2027: prohibited practices banned</li>" +
    "<li>August 2027: general-purpose AI obligations apply</li>" +
    "<li>August 2028: most remaining obligations apply</li>" +
    "<li>August 2029: rules for high-risk systems in regulated " +
    "products</li>" +
    "</ul>",
);

/** Draft 8: the final, consistency-checked version. */
const v8Fields = draftFields(
  "EU AI Act enters into force",
  "The world's first comprehensive AI rulebook applies across the Union " +
    "as of today, phasing in obligations by risk until August 2029.",
  "<h3>What changes today</h3>" +
    "<p>The AI Act is law across the Union. The entry into force starts " +
    "the compliance countdown for every provider and deployer of AI " +
    "systems on the European market.</p>" +
    "<h3>Who is affected</h3>" +
    '<p>"As of today, providers know exactly what is expected of them," ' +
    "said Commissioner for Internal Market Thierry Breton.</p>" +
    "<h3>What comes next</h3>" +
    "<ul>" +
    "<li>February 2027: prohibited practices banned</li>" +
    "<li>August 2027: general-purpose AI obligations apply</li>" +
    "<li>August 2028: most remaining obligations apply</li>" +
    "<li>August 2029: rules for high-risk systems in regulated " +
    "products</li>" +
    "</ul>",
);

/**
 * Builds the tool calls for one versioned draft revision so the
 * narrative fixture below stays compact.
 */
function draftCall(
  version: number,
  toneLabel: string,
  fields: Record<string, unknown>,
) {
  return [
    {
      function: { name: "draft_content" },
      result: {
        version,
        context: {
          tone: {
            id: toneLabel.toLowerCase().replace(/\s+/g, "-"),
            label: toneLabel,
          },
          template: { id: "news-article", label: "News article" },
          documents: [],
        },
        fields,
      },
    },
  ];
}

/**
 * Persisted-transcript fixture simulating a full editorial session.
 * Several editors take turns one after another (sessions have no real
 * concurrency: one active user at any moment), handing the work over as
 * they come and go. Covers user turns from five authors, event chips,
 * eight draft versions, and save tool calls, all mapped through the
 * real hydrate path so the story exercises the same rendering pipeline
 * as a reloaded session.
 */
const transcript: SessionMessage[] = [
  {
    role: "event",
    type: "session_start",
    summary: "Session started",
    at: "2026-08-19T09:00:00Z",
  },

  // Dev Editor opens the session and produces the first two drafts.
  {
    role: "user",
    userName: "Dev Editor",
    userId: "dev-editor",
    content:
      "Draft a news article about the EU AI Act entering into force. " +
      "Keep it around 400 words, we need it for the morning briefing.",
  },
  {
    role: "assistant",
    content:
      "Here is a first draft based on the news article structure. I went " +
      "through the briefing points you provided and organised the piece " +
      "around the entry into force date, the risk-based approach, and the " +
      "obligations that apply to providers of high-risk systems.\n\n" +
      "A few editorial notes on the choices I made:\n\n" +
      "- The headline leads with the date because that is the news hook; " +
      "the regulation itself has been covered extensively since the " +
      "political agreement, so the novelty is the legal effect starting " +
      "today.\n" +
      "- The summary paragraph deliberately avoids the phrase 'world " +
      "first' since the claim is contested for narrow-scope laws in other " +
      "jurisdictions; 'first comprehensive rules' is the safer wording " +
      "used by the institutions.\n" +
      "- The body keeps the timeline concrete: twelve months for " +
      "national authorities, twenty-four months for most obligations, " +
      "thirty-six for high-risk systems embedded in regulated products.\n\n" +
      "Review the draft on the right and tell me what to adjust.",
    toolCalls: draftCall(1, "Clear and professional", v1Fields),
  },
  {
    role: "user",
    userName: "Dev Editor",
    userId: "dev-editor",
    content:
      "Good start. The headline is flat though: make it punchier and lead " +
      "with what changes today. Also add a one-line standfirst under the " +
      "title.",
  },
  {
    role: "assistant",
    content:
      "Done. The headline now leads with the legal effect starting today " +
      "and a standfirst sums up the risk-based approach in one line. " +
      "Draft 2 is on the right.",
    toolCalls: draftCall(2, "Clear and professional", v2Fields),
  },
  {
    role: "user",
    userName: "Dev Editor",
    userId: "dev-editor",
    content:
      "Thanks, that reads much better. I have to jump into the editorial " +
      "meeting now, Maria will take this over from here.",
  },
  {
    role: "assistant",
    content:
      "Understood. The session stays open and both drafts are in the " +
      "version rail, so Maria can pick up right from Draft 2.",
  },

  // Maria Rossi takes over: formal tone and institutional additions.
  {
    role: "event",
    type: "tone",
    summary: "Tone changed from Clear and professional to Formal",
    at: "2026-08-19T09:40:00Z",
  },
  {
    role: "user",
    userName: "Maria Rossi",
    userId: "maria-rossi",
    content:
      "Picking this up after the briefing. I switched the tone to formal " +
      "since the piece will also go out through the institutional " +
      "newsletter. Can you rework the body accordingly?",
  },
  {
    role: "assistant",
    content:
      "Reworked in the formal register: contractions removed, the " +
      "colloquial framing in the opening replaced with the institutional " +
      "wording, and the compliance timeline is now stated in full dates " +
      "rather than relative terms. The structure is unchanged.",
    toolCalls: draftCall(3, "Formal", v3Fields),
  },
  {
    role: "user",
    userName: "Maria Rossi",
    userId: "maria-rossi",
    content:
      "Better. Now add a short quote from the Commissioner for Internal " +
      "Market on what changes for companies from today, and mention the " +
      "role of the new AI Office.",
  },
  {
    role: "assistant",
    content:
      "Added a two-sentence quote in the middle section on the immediate " +
      "obligations for providers, and a closing paragraph introducing the " +
      "AI Office as the supervision and enforcement hub. Draft 4 is " +
      "ready for review.",
    toolCalls: draftCall(4, "Formal", v4Fields),
  },
  {
    role: "user",
    userName: "Maria Rossi",
    userId: "maria-rossi",
    content: "That works for me. Leaving it here for the legal pass.",
  },

  // Jan Kowalski does the legal review and saves a revision.
  {
    role: "user",
    userName: "Jan Kowalski",
    userId: "jan-kowalski",
    content:
      "Legal pass. Two corrections: the prohibitions apply in six months, " +
      "not immediately, and the general-purpose AI obligations in twelve. " +
      "Please fix the timeline and soften the compliance claims " +
      "accordingly.",
  },
  {
    role: "assistant",
    content:
      "Corrected. The timeline paragraph now distinguishes the six-month " +
      "mark for prohibited practices from the twelve-month mark for " +
      "general-purpose AI obligations, and the compliance wording says " +
      "'must prepare to comply' where the duties are not yet applicable.",
    toolCalls: draftCall(5, "Formal", v5Fields),
  },
  {
    role: "user",
    userName: "Jan Kowalski",
    userId: "jan-kowalski",
    content: "Correct now. Save the draft as a new unpublished revision.",
  },
  {
    role: "assistant",
    content:
      "The draft has been saved as an unpublished revision. Nothing has " +
      "been published: the revision sits in the moderation queue until an " +
      "editor with publishing rights approves it, and every save creates " +
      "a separate revision so earlier versions remain recoverable.",
    toolCalls: [{ function: { name: "save_draft_revision" }, result: {} }],
  },
  {
    role: "event",
    type: "saved",
    summary: "Draft saved as unpublished revision",
    at: "2026-08-19T10:25:00Z",
  },

  // Ana Silva restructures the piece for the website layout.
  {
    role: "event",
    type: "template",
    summary: "Template changed to News article with paragraphs",
    at: "2026-08-19T11:30:00Z",
  },
  {
    role: "user",
    userName: "Ana Silva",
    userId: "ana-silva",
    content:
      "Web team here. I switched the template so the article renders with " +
      "the paragraph layout on the site. Restructure the body into three " +
      "short sections with subheadings.",
  },
  {
    role: "assistant",
    content:
      "Restructured into three sections: 'What changes today', 'Who is " +
      "affected', and 'What comes next', each with a subheading and two " +
      "short paragraphs. The quote stays in the second section.",
    toolCalls: draftCall(6, "Formal", v6Fields),
  },
  {
    role: "user",
    userName: "Ana Silva",
    userId: "ana-silva",
    content:
      "Trim the middle section, it repeats the standfirst. And end with " +
      "the timeline as a bullet list, it scans better on the site.",
  },
  {
    role: "assistant",
    content:
      "Trimmed and reformatted:\n\n" +
      "- The middle section lost the sentence duplicating the standfirst " +
      "and now goes straight into the obligations.\n" +
      "- The closing timeline is a four-item bullet list, one per " +
      "milestone, each starting with the date.\n\n" +
      "Draft 7 is on the right.",
    toolCalls: draftCall(7, "Formal", v7Fields),
  },

  // Peter Novak does the final consistency pass and saves again.
  {
    role: "user",
    userName: "Peter Novak",
    userId: "peter-novak",
    content:
      "Final check before scheduling. Read the whole piece once more and " +
      "fix anything inconsistent after all the edits, especially numbers " +
      "and dates.",
  },
  {
    role: "assistant",
    content:
      "Full pass done. Three fixes: the standfirst still said 'twenty " +
      "months' where the body was corrected to twenty-four, one date was " +
      "written in US format, and the AI Office was introduced twice after " +
      "the restructuring. All aligned now, and the quote attribution " +
      "matches the official title. This is Draft 8, ready for scheduling.",
    toolCalls: draftCall(8, "Formal", v8Fields),
  },
  {
    role: "user",
    userName: "Peter Novak",
    userId: "peter-novak",
    content:
      "Save it as a new revision, this is the version going to " +
      "publication review.",
  },
  {
    role: "assistant",
    content:
      "Saved as a new unpublished revision and ready for publication " +
      "review.",
    toolCalls: [{ function: { name: "save_draft_revision" }, result: {} }],
  },
  {
    role: "event",
    type: "saved",
    summary: "Draft saved as unpublished revision",
    at: "2026-08-19T12:40:00Z",
  },
];

/** Tone options mirroring the standalone development config. */
const toneOptions = [
  {
    value: "clear-professional",
    label: "Clear and professional",
    description: "Be direct, neutral, and easy to scan.",
  },
  {
    value: "formal",
    label: "Formal",
    description: "Use an institutional, measured voice.",
  },
];
const defaultToneId = toneOptions[1]?.value ?? "";

/**
 * Seeds the drafting store slice for the preview. Stories skip
 * initializePluginSlices, so the full initial slice is spread in before
 * the latest draft's field values.
 */
export function seedDraftingPreviewState(): void {
  setDraftingState({
    ...draftingSliceConfig.initialState,
    draftedFields: v8Fields,
    // The seeded fields belong to the latest draft in the transcript.
    activeDraftVersion: 8,
  });
}

/** Bridges the mock runtime's pending state into the shell store. */
function PendingWorkReporter() {
  useReportPendingWork();
  return null;
}

/** Publishes the seeded thread's participants to the session header. */
function ParticipantsReporter() {
  useReportParticipants();
  return null;
}

/** Full drafting plugin UI preview; fills its parent flex container. */
export function FullDraftingPreview() {
  const [toneId, setToneId] = useState(defaultToneId);
  const documents = useDraftingDocuments();
  const template = useDraftingTemplate();
  const { draftedFields } = useDraftingSlice();
  // Mirror the root component: the pane only exists with an artifact.
  const hasArtifact = Object.keys(draftedFields).length > 0;

  const runtime = useLocalRuntime(
    {
      run: async () => ({
        content: [
          {
            type: "text",
            text: "This is a mocked response; the story has no backend.",
          },
        ],
      }),
    },
    { initialMessages: toThreadMessages(transcript) },
  );

  const toneLabel =
    toneOptions.find((option) => option.value === toneId)?.label ?? "Not set";

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      {/* Feed the shell exit guard with the mock runtime's pending state. */}
      <PendingWorkReporter />
      {/* Feed the session header with the seeded participants. */}
      <ParticipantsReporter />
      {/* Register tool call renderers so they appear inline in chat. */}
      <DraftContentToolUI />
      <EditorialEventToolUI />
      <SaveDraftRevisionToolUI />

      <div className="flex min-h-0 flex-1 bg-white">
        {/* Left panel: chat in the faint gray well, as in the root. */}
        <div className="flex min-h-0 flex-1 flex-col bg-gray-50">
          <DraftingThread
            tabs={[
              {
                id: "tone",
                icon: <Megaphone size={20} />,
                title: "Tone",
                summary: toneLabel,
                render: (close) => (
                  <CardSelectPane
                    icon={<Megaphone size={18} />}
                    title="Tone"
                    description="Save the selected tone before drafting to apply it."
                    options={toneOptions}
                    value={toneId}
                    onChange={setToneId}
                    onSave={async () => close()}
                    onCancel={close}
                    hasChanges={toneId !== defaultToneId}
                  />
                ),
              },
              {
                id: "documents",
                icon: <FileText size={20} />,
                title: "Documents",
                summary: `${documents.count} documents`,
                render: (close) => (
                  <DocumentsPanel
                    selected={documents.selected}
                    onRemove={documents.removeDocument}
                    onUpload={documents.uploadFiles}
                    onSave={async () => close()}
                    onCancel={close}
                  />
                ),
              },
              {
                id: "templates",
                icon: <LayoutTemplate size={20} />,
                title: "Templates",
                summary: template.selectedLabel ?? "Not set",
                render: (close) => (
                  <CardSelectPane
                    icon={<LayoutTemplate size={18} />}
                    title="Template"
                    description="Select the structure the generated draft should follow."
                    options={template.options}
                    value={template.value}
                    onChange={template.updateValue}
                    onSave={async () => close()}
                    onCancel={() => {
                      template.discardChanges();
                      close();
                    }}
                    hasChanges={template.hasChanges}
                  />
                ),
              },
            ]}
          />
        </div>

        {/* Middle panel: artifact pane with the open draft. The seeded
            transcript always carries drafts, so the rail can restore it. */}
        {hasArtifact && (
          <ArtifactPane canCollapse>
            <ContentTable onSave={() => {}} />
          </ArtifactPane>
        )}

        {/* Right edge: the always-present draft rail. */}
        <DraftRail />
      </div>
    </AssistantRuntimeProvider>
  );
}
