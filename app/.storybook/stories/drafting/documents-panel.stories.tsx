import type { Meta, StoryObj } from "@storybook/react-vite";
import { DocumentsPanel } from "../../../src/plugins/drafting/components/documents-panel";
import {
  type DraftingDocument,
  useDraftingDocuments,
} from "../../../src/plugins/drafting/hooks/use-drafting-documents";

const meta = {
  title: "Drafting/Documents panel",
  component: DocumentsPanel,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof DocumentsPanel>;

export default meta;
type Story = StoryObj<typeof meta>;

/** File extensions the static stories accept for upload. */
const acceptedExtensions = ["pdf", "docx", "txt"];

/** Documents shown by the static upload state stories. */
const attachedDocuments: DraftingDocument[] = [
  {
    id: "attached-brief",
    title: "EU AI Act briefing note.pdf",
    status: "done",
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
        extensions={documents.extensions}
        uploads={documents.uploads}
        onRemove={documents.removeDocument}
        onRetry={documents.retryDocument}
        onUpload={documents.uploadFiles}
        onDismissUpload={documents.dismissUpload}
        onClose={() => {}}
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
        extensions={acceptedExtensions}
        uploads={[]}
        onRemove={() => {}}
        onRetry={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onClose={() => {}}
      />
    </div>
  ),
};

/** Initial document fetch in flight: interaction is blocked. */
export const Loading: Story = {
  render: () => (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <DocumentsPanel
        selected={[]}
        extensions={acceptedExtensions}
        uploads={[]}
        onRemove={() => {}}
        onRetry={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onClose={() => {}}
        isLoading
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
        extensions={acceptedExtensions}
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
        onRetry={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onClose={() => {}}
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
        extensions={acceptedExtensions}
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
        onRetry={() => {}}
        onUpload={() => {}}
        onDismissUpload={() => {}}
        onClose={() => {}}
      />
    </div>
  ),
};
