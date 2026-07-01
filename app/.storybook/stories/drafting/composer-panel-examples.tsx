import { Check, ChevronRight, Plus, Upload, X } from "lucide-react";
import { useRef, useState } from "react";

function formatFileSize(size: number) {
  if (size < 1024) {
    return `${size} B`;
  }
  if (size < 1024 * 1024) {
    return `${Math.round(size / 1024)} KB`;
  }
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export function DocumentAttachmentPanel() {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploadedFiles, setUploadedFiles] = useState<File[]>([]);
  const selectedDocuments = [
    {
      id: "eu-ai-act-brief",
      title: "EU AI Act briefing note.pdf",
      meta: "PDF · 240 KB",
    },
    {
      id: "stakeholder-comments",
      title: "Stakeholder comments.docx",
      meta: "DOCX · 96 KB",
    },
  ];
  const documentsForDraft = [
    ...selectedDocuments,
    ...uploadedFiles.map((file) => ({
      id: `${file.name}-${file.lastModified}`,
      title: file.name,
      meta: `${file.type || "File"} · ${formatFileSize(file.size)}`,
    })),
  ];
  const availableDocuments = [
    {
      id: "impact-assessment",
      title: "Impact assessment summary.pdf",
      meta: "PDF · 180 KB",
    },
    {
      id: "press-lines",
      title: "Approved press lines.txt",
      meta: "TXT · 12 KB",
    },
  ];

  function addUploadedFiles(fileList: FileList | null) {
    if (!fileList) {
      return;
    }

    setUploadedFiles((currentFiles) => [
      ...currentFiles,
      ...Array.from(fileList),
    ]);
  }

  return (
    <div className="space-y-4 border-t border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
      <div>
        <h2 className="text-sm font-semibold text-gray-900">
          Briefing documents
        </h2>
        <p className="text-xs text-gray-600">
          Attach or select documents that should guide the next draft.
        </p>
      </div>

      <button
        type="button"
        className="block w-full cursor-pointer rounded-lg border border-dashed border-gray-300 bg-white p-4 text-center hover:border-blue-300 hover:bg-blue-50"
        onClick={() => fileInputRef.current?.click()}
      >
        <Upload size={18} className="mx-auto mb-2 text-gray-400" />
        <p className="text-xs font-medium text-gray-700">
          Drop files here or browse your computer
        </p>
        <p className="mt-1 text-xs text-gray-500">
          PDF, DOCX, TXT, or Markdown files
        </p>
        <input
          ref={fileInputRef}
          type="file"
          multiple
          className="sr-only"
          accept=".pdf,.doc,.docx,.txt,.md,text/plain,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
          onChange={(event) => {
            addUploadedFiles(event.target.files);
            event.target.value = "";
          }}
        />
      </button>

      <div className="grid gap-3 md:grid-cols-2">
        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            Selected for this draft
          </h3>
          <div className="space-y-2">
            {documentsForDraft.map((document) => (
              <div
                key={document.id}
                className="flex items-center justify-between gap-3 rounded-md border border-blue-100 bg-blue-50 px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="truncate text-xs font-medium text-gray-900">
                    {document.title}
                  </p>
                  <p className="text-xs text-gray-500">{document.meta}</p>
                </div>
                <button
                  type="button"
                  className="cursor-pointer rounded-md p-1 text-gray-400 hover:bg-white hover:text-gray-600"
                  aria-label={`Remove ${document.title}`}
                >
                  <X size={14} />
                </button>
              </div>
            ))}
          </div>
        </section>

        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            Available documents
          </h3>
          <div className="space-y-2">
            {availableDocuments.map((document) => (
              <div
                key={document.id}
                className="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="truncate text-xs font-medium text-gray-900">
                    {document.title}
                  </p>
                  <p className="text-xs text-gray-500">{document.meta}</p>
                </div>
                <button
                  type="button"
                  className="inline-flex cursor-pointer items-center gap-1 rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                >
                  <Plus size={13} />
                  Add
                </button>
              </div>
            ))}
          </div>
        </section>
      </div>

      <div className="flex justify-end">
        <button
          type="button"
          className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700"
        >
          <Check size={15} />
          Save documents
        </button>
      </div>
    </div>
  );
}

export function TemplateSelectionPanel() {
  const templates = [
    {
      id: "news-article",
      title: "News article",
      description:
        "Structured article with headline, summary, body, and related links.",
      meta: "Recommended for oe_news",
      selected: true,
    },
    {
      id: "press-release",
      title: "Press release",
      description:
        "Announcement-focused structure with key messages and media angle.",
      meta: "Best for public announcements",
      selected: false,
    },
    {
      id: "policy-brief",
      title: "Policy brief",
      description:
        "Short explanatory format focused on context, impact, and next steps.",
      meta: "Best for expert readers",
      selected: false,
    },
  ];

  return (
    <div className="space-y-4 border-t border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
      <div>
        <h2 className="text-sm font-semibold text-gray-900">Template</h2>
        <p className="text-xs text-gray-600">
          Select the structure the generated draft should follow.
        </p>
      </div>

      <div className="grid gap-3">
        {templates.map((template) => (
          <button
            key={template.id}
            type="button"
            className={`flex cursor-pointer items-start justify-between gap-4 rounded-lg border bg-white p-3 text-left hover:border-blue-300 hover:bg-blue-50 ${
              template.selected
                ? "border-blue-300 ring-1 ring-blue-200"
                : "border-gray-200"
            }`}
          >
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <p className="text-sm font-semibold text-gray-900">
                  {template.title}
                </p>
                {template.selected && (
                  <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                    Selected
                  </span>
                )}
              </div>
              <p className="mt-1 text-xs text-gray-600">
                {template.description}
              </p>
              <p className="mt-2 text-xs font-medium text-gray-500">
                {template.meta}
              </p>
            </div>
            <ChevronRight size={16} className="mt-1 shrink-0 text-gray-400" />
          </button>
        ))}
      </div>

      <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        Template selection changes the generated structure but should stay
        compatible with the current content type fields.
      </div>

      <div className="flex justify-end">
        <button
          type="button"
          className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700"
        >
          <Check size={15} />
          Save template
        </button>
      </div>
    </div>
  );
}
