import type { Meta, StoryObj } from "@storybook/react-vite";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import { useDraftingDocuments } from "../../../src/plugins/drafting/hooks/use-drafting-documents";

const meta = {
  title: "Drafting/Documents panel",
  component: DocumentsPanel,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DocumentsPanel>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Interactive wrapper backed by the mock documents hook. */
function InteractiveDocuments() {
  const documents = useDraftingDocuments();
  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={documents.selected}
        onRemove={documents.removeDocument}
        onUpload={documents.uploadFiles}
        onSave={async () => {}}
        onCancel={() => {}}
      />
    </div>
  );
}

export const Default: Story = {
  render: () => <InteractiveDocuments />,
};

/** Empty state with nothing attached yet. */
export const Empty: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={[]}
        onRemove={() => {}}
        onUpload={() => {}}
        onSave={async () => {}}
        onCancel={() => {}}
      />
    </div>
  ),
};
