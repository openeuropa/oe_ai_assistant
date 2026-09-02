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

/** Documents shown by the static upload state stories. */
const attachedDocuments = [
  {
    id: "attached-brief",
    title: "EU AI Act briefing note.pdf",
    meta: { type: "pdf", size: 245760 },
  },
];

/** Interactive wrapper backed by the mock documents hook. */
function InteractiveDocuments() {
  const documents = useDraftingDocuments();
  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={documents.selected}
        uploads={documents.uploads}
        onRemove={documents.removeDocument}
        onUpload={documents.uploadFiles}
        onDismissUpload={documents.dismissUpload}
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
        uploads={[]}
        onRemove={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onSave={async () => {}}
        onCancel={() => {}}
      />
    </div>
  ),
};

/**
 * Concurrent uploads in flight: each file holds a slot with an
 * indeterminate progress bar and no remove cross.
 */
export const Uploading: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={attachedDocuments}
        uploads={[
          {
            id: "upload-1",
            title: "Stakeholder comments.docx",
            size: 98304,
            status: "uploading",
          },
          {
            id: "upload-2",
            title: "Meeting minutes.txt",
            size: 20480,
            status: "uploading",
          },
        ]}
        onRemove={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onSave={async () => {}}
        onCancel={() => {}}
      />
    </div>
  ),
};

/**
 * One upload failed while another still runs: the failed slot shows the
 * endpoint error and a dismiss cross.
 */
export const UploadFailed: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={attachedDocuments}
        uploads={[
          {
            id: "upload-1",
            title: "Stakeholder comments.docx",
            size: 98304,
            status: "error",
            error: "Drafting add-document error: 500",
          },
          {
            id: "upload-2",
            title: "Meeting minutes.txt",
            size: 20480,
            status: "uploading",
          },
        ]}
        onRemove={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onSave={async () => {}}
        onCancel={() => {}}
      />
    </div>
  ),
};
