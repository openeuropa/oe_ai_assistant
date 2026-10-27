import { EditorContent, useEditor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import DOMPurify from "dompurify";
import { Bold, Italic, List, ListOrdered, Redo2, Undo2 } from "lucide-react";
import { useEffect } from "react";

/** A small content editor; Drupal still applies the field's text format. */
export function RichTextInput({
  value,
  label,
  onChange,
  disabled,
}: {
  value: string;
  label: string;
  onChange: (html: string) => void;
  disabled: boolean;
}) {
  const editor = useEditor({
    extensions: [StarterKit.configure({ link: { openOnClick: false } })],
    content: DOMPurify.sanitize(value),
    editable: !disabled,
    editorProps: {
      attributes: {
        role: "textbox",
        "aria-label": label,
        "aria-multiline": "true",
        class:
          "min-h-32 p-3 text-sm outline-none [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5",
      },
    },
    onUpdate: ({ editor: current }) =>
      onChange(current.isEmpty ? "" : current.getHTML()),
  });
  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);
  const controls = [
    {
      label: "Bold",
      icon: Bold,
      run: () => editor?.chain().focus().toggleBold().run(),
    },
    {
      label: "Italic",
      icon: Italic,
      run: () => editor?.chain().focus().toggleItalic().run(),
    },
    {
      label: "Bullet list",
      icon: List,
      run: () => editor?.chain().focus().toggleBulletList().run(),
    },
    {
      label: "Numbered list",
      icon: ListOrdered,
      run: () => editor?.chain().focus().toggleOrderedList().run(),
    },
    {
      label: "Undo",
      icon: Undo2,
      run: () => editor?.chain().focus().undo().run(),
    },
    {
      label: "Redo",
      icon: Redo2,
      run: () => editor?.chain().focus().redo().run(),
    },
  ];
  return (
    <div className="overflow-hidden rounded-md border border-gray-300 bg-white">
      <div
        role="toolbar"
        aria-label="Text formatting"
        className="flex gap-1 border-b border-gray-200 bg-gray-50 p-1"
      >
        {controls.map(({ label: title, icon: Icon, run }) => (
          <button
            key={title}
            type="button"
            aria-label={title}
            title={title}
            disabled={disabled}
            onClick={run}
            className="rounded p-1.5 hover:bg-gray-200 disabled:opacity-50"
          >
            <Icon size={14} />
          </button>
        ))}
      </div>
      <EditorContent editor={editor} />
    </div>
  );
}
