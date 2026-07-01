import { AssistantRuntimeProvider, useLocalRuntime } from "@assistant-ui/react";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { FileText, LayoutTemplate, UserRound } from "lucide-react";
import { DraftingThread } from "../../../src/plugins/drafting/components/drafting-thread";
import {
  DocumentAttachmentPanel,
  TemplateSelectionPanel,
} from "./composer-panel-examples";

const meta = {
  title: "Drafting/Composer panel pattern",
  component: DraftingThread,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DraftingThread>;

export default meta;
type Story = StoryObj<typeof meta>;

function ComposerPanelPatternPreview() {
  const runtime = useLocalRuntime({
    run: async () => ({
      content: [
        {
          type: "text",
          text: "Composer panels keep drafting controls close to the prompt.",
        },
      ],
    }),
  });

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <div className="flex h-[700px] max-w-2xl flex-col overflow-hidden border border-gray-200 bg-white">
        <DraftingThread
          defaultOpenPanelId="tone"
          composerPanels={[
            {
              id: "tone",
              ariaLabel: "Tone settings",
              icon: <UserRound size={14} />,
              triggerLabel: "Tone: Formal",
              content: (
                <div className="border-t border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                  Full tone selector content goes here.
                </div>
              ),
            },
            {
              id: "documents",
              ariaLabel: "Briefing documents",
              icon: <FileText size={14} />,
              triggerLabel: "5 documents attached",
              hasChanges: true,
              content: <DocumentAttachmentPanel />,
            },
            {
              id: "template",
              ariaLabel: "Template selection",
              icon: <LayoutTemplate size={14} />,
              triggerLabel: "Template: News article",
              content: <TemplateSelectionPanel />,
            },
          ]}
        />
      </div>
    </AssistantRuntimeProvider>
  );
}

export const MultipleComposerPanels: Story = {
  render: () => <ComposerPanelPatternPreview />,
};

function DocumentAttachmentPanelPreview() {
  return (
    <div className="max-w-3xl overflow-hidden border border-gray-200 bg-white">
      <DocumentAttachmentPanel />
    </div>
  );
}

export const DocumentAttachmentPanelOnly: Story = {
  render: () => <DocumentAttachmentPanelPreview />,
};

function TemplateSelectionPanelPreview() {
  return (
    <div className="max-w-3xl overflow-hidden border border-gray-200 bg-white">
      <TemplateSelectionPanel />
    </div>
  );
}

export const TemplateSelectionPanelOnly: Story = {
  render: () => <TemplateSelectionPanelPreview />,
};
