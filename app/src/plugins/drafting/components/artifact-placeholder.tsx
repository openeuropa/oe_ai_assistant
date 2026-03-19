/**
 * Artifact panel placeholder.
 *
 * Shown on the right panel before the agent produces any drafted
 * fields. Renders a ghost preview of the content table to hint
 * at the UI that will appear once the user prompts the assistant.
 */

import { FileText } from "lucide-react";

/** Fake field rows for the ghost table. */
const GHOST_FIELDS = [
  { label: "Title", width: "w-3/4" },
  { label: "Introduction", width: "w-full" },
  { label: "Body text", width: "w-5/6" },
  { label: "Teaser", width: "w-2/3" },
  { label: "Publication date", width: "w-1/3" },
];

/** Ghost preview of the content table layout. */
export function ArtifactPlaceholder() {
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {/* Ghost header */}
      <div className="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
        <FileText size={14} className="text-gray-300" />
        <span className="text-sm font-semibold text-gray-300">
          Drafted Content
        </span>
      </div>

      {/* Ghost table */}
      <div className="min-h-0 flex-1 overflow-hidden p-4">
        <table className="w-full">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-200">
              <th className="w-8 pb-3" />
              <th className="w-28 pb-3">Field</th>
              <th className="pb-3">Value</th>
            </tr>
          </thead>
          <tbody>
            {GHOST_FIELDS.map((field) => (
              <tr key={field.label} className="border-b border-gray-50">
                {/* Ghost toggle */}
                <td className="py-3 pr-2">
                  <div className="h-5 w-5 rounded-full bg-gray-100" />
                </td>
                {/* Ghost label */}
                <td className="py-3 pr-4">
                  <div className="h-3 w-20 rounded bg-gray-100" />
                </td>
                {/* Ghost value */}
                <td className="py-3">
                  <div className={`h-3 ${field.width} rounded bg-gray-100`} />
                  {field.width === "w-full" && (
                    <div className="mt-2 h-3 w-2/3 rounded bg-gray-50" />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
