import { Check, LayoutTemplate, X } from "lucide-react";
import type { FormEvent } from "react";
import { useId } from "react";
import { Pane } from "@/components/ui/pane";
import {
  RadioCardGroup,
  type RadioCardOption,
} from "@/components/ui/radio-card-group";

export interface TemplatePanelProps {
  options: RadioCardOption[];
  value: string;
  onChange: (templateId: string) => void;
  onSave: () => Promise<void>;
  /** Restores the confirmed selection and closes the panel. */
  onCancel: () => void;
  hasChanges: boolean;
  isSaving?: boolean;
  error?: string | null;
}

/**
 * Template selection pane.
 *
 * Composes the shared Pane chrome with a card selection for the draft
 * template, reusing the same selection UX as the tone pane. Presentational
 * only; selection state and persistence live in useDraftingTemplate.
 */
export function TemplatePanel({
  options,
  value,
  onChange,
  onSave,
  onCancel,
  hasChanges,
  isSaving = false,
  error,
}: TemplatePanelProps) {
  // A unique name keeps the radio group isolated when several panes render.
  const groupName = useId();

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // The empty catch avoids an unhandled promise rejection on failure.
    void onSave().catch(() => {});
  }

  return (
    <form onSubmit={handleSubmit}>
      <Pane
        icon={<LayoutTemplate size={18} />}
        title="Template"
        description="Select the structure the generated draft should follow."
        actions={
          <>
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-100"
              onClick={onCancel}
            >
              <X size={15} />
              Cancel
            </button>
            <button
              type="submit"
              className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              disabled={!value || !hasChanges || isSaving}
            >
              <Check size={15} />
              Save
            </button>
          </>
        }
      >
        <RadioCardGroup
          name={groupName}
          options={options}
          value={value}
          onChange={onChange}
          disabled={options.length === 0}
        />

        {error && (
          <p className="mt-3 text-xs text-red-700" role="alert">
            {error}
          </p>
        )}
      </Pane>
    </form>
  );
}
