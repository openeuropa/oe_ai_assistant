import { Check, Loader2, Megaphone, X } from "lucide-react";
import type { FormEvent } from "react";
import { useId } from "react";
import { Pane } from "@/components/ui/pane";
import { RadioCardGroup } from "@/components/ui/radio-card-group";

export interface GenerationSettingsOption {
  id: string;
  label: string;
  description: string;
}

export interface GenerationSettingsDraft {
  toneId: string;
}

export interface GenerationSettingsPanelProps {
  values: GenerationSettingsDraft;
  toneOptions: GenerationSettingsOption[];
  onChange: (values: GenerationSettingsDraft) => void;
  onSave: () => Promise<void>;
  /** Restores the confirmed selection and closes the panel. */
  onCancel: () => void;
  hasChanges: boolean;
  isSaving: boolean;
  error?: string | null;
}

/**
 * Tone settings pane.
 *
 * Composes the shared Pane chrome with a card selection for the tone. API
 * calls and persistence stay in useDraftingGenerationSettings; this
 * component only renders the controlled values and reports user actions.
 */
export function GenerationSettingsPanel({
  values,
  toneOptions,
  onChange,
  onSave,
  onCancel,
  hasChanges,
  isSaving,
  error,
}: GenerationSettingsPanelProps) {
  // A unique name keeps the radio group isolated when several panes render.
  const toneGroupName = useId();

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // The hook stores the error message; the empty catch avoids an unhandled
    // promise rejection when the save request fails.
    void onSave().catch(() => {});
  }

  return (
    <form onSubmit={handleSubmit}>
      <Pane
        icon={<Megaphone size={18} />}
        title="Tone"
        description="Save the selected tone before drafting to apply it."
        actions={
          <>
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
              onClick={onCancel}
              // Prevent dismissing the panel while a save is in flight.
              disabled={isSaving}
            >
              <X size={15} />
              Cancel
            </button>
            <button
              type="submit"
              className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              // Saving is useful only when there is a selected tone that
              // differs from the already-confirmed value.
              disabled={!values.toneId || !hasChanges || isSaving}
            >
              {isSaving ? (
                <Loader2 size={15} className="animate-spin" />
              ) : (
                <Check size={15} />
              )}
              Save
            </button>
          </>
        }
      >
        <RadioCardGroup
          name={toneGroupName}
          options={toneOptions.map((option) => ({
            value: option.id,
            label: option.label,
            description: option.description,
          }))}
          value={values.toneId}
          onChange={(toneId) => onChange({ toneId })}
          disabled={toneOptions.length === 0}
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
