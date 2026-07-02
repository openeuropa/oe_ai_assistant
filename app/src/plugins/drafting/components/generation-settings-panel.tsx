import { Check, Loader2, UserRound } from "lucide-react";
import type { FormEvent } from "react";
import { useId } from "react";

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
  hasChanges: boolean;
  isSaving: boolean;
  error?: string | null;
}

/** Finds the mini-guidance text for the currently selected tone. */
function guidanceFor(
  options: GenerationSettingsOption[],
  value: string,
): string | null {
  return options.find((option) => option.id === value)?.description ?? null;
}

/**
 * Presentational form for selecting and saving the tone.
 *
 * API calls and persistence stay in useDraftingGenerationSettings; this
 * component only renders the controlled values and reports user actions.
 */
export function GenerationSettingsPanel({
  values,
  toneOptions,
  onChange,
  onSave,
  hasChanges,
  isSaving,
  error,
}: GenerationSettingsPanelProps) {
  const toneId = useId();
  const selectedGuidance = guidanceFor(toneOptions, values.toneId);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // The hook stores the error message; the empty catch avoids an unhandled
    // promise rejection when the save request fails.
    void onSave().catch(() => {});
  }

  return (
    <form
      className="border-t border-gray-200 bg-gray-50 p-4"
      onSubmit={handleSubmit}
    >
      <div className="mb-3 flex items-start gap-2">
        <UserRound size={18} className="mt-0.5 text-blue-600" />
        <div>
          <h2 className="text-sm font-semibold text-gray-900">Tone</h2>
          <p className="text-xs text-gray-600">
            Save the selected tone before drafting to apply it.
          </p>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-start">
        <label
          className="grid self-start gap-1.5 text-xs font-medium text-gray-700"
          htmlFor={toneId}
        >
          <select
            id={toneId}
            value={values.toneId}
            onChange={(event) => onChange({ toneId: event.target.value })}
            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-900 focus:border-blue-500 focus:outline-none"
            disabled={toneOptions.length === 0}
          >
            {toneOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
          {selectedGuidance && (
            <span className="font-normal text-gray-500">
              {selectedGuidance}
            </span>
          )}
        </label>

        <div className="flex items-center gap-3">
          {hasChanges && (
            <span className="text-xs font-medium text-amber-700">
              Unsaved changes
            </span>
          )}
          <button
            type="submit"
            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            // Saving is useful only when there is a selected tone that differs
            // from the already-confirmed value.
            disabled={!values.toneId || !hasChanges || isSaving}
          >
            {isSaving ? (
              <Loader2 size={15} className="animate-spin" />
            ) : (
              <Check size={15} />
            )}
            Save
          </button>
        </div>
      </div>

      {error && (
        <p className="mt-3 text-xs text-red-700" role="alert">
          {error}
        </p>
      )}
    </form>
  );
}
