import type { ReactNode } from "react";
import { useId } from "react";
import { Pane } from "@/components/ui/pane";
import {
  RadioCardGroup,
  type RadioCardOption,
} from "@/components/ui/radio-card-group";

export interface CardSelectPaneProps {
  /** Optional leading icon shown next to the title. */
  icon?: ReactNode;
  /** Pane title. */
  title: string;
  /** Optional supporting text under the title. */
  description?: string;
  /** Selectable options rendered as cards. */
  options: RadioCardOption[];
  /** The currently selected value. */
  value: string;
  /** Reports the newly selected value. */
  onChange: (value: string) => void;
  /** Persists the selection. */
  onSave: () => void | Promise<void>;
  /** Restores the confirmed selection and closes the pane. */
  onCancel: () => void;
  /** Whether the selection differs from the confirmed value. */
  hasChanges: boolean;
  /** Shows a spinner on Save while a save runs. */
  isSaving?: boolean;
  /** Error message shown under the selection. */
  error?: string | null;
}

/**
 * A settings pane whose body is a single card selection.
 *
 * Every "pick one option and save" pane (tone, template, ...) looks the same:
 * the Pane chrome with a RadioCardGroup body and Save enabled only when a
 * value is selected and changed. This component captures that shape so each
 * use only passes its icon, title, options, and handlers. Presentational only;
 * selection state and persistence live in the caller's hook.
 */
export function CardSelectPane({
  icon,
  title,
  description,
  options,
  value,
  onChange,
  onSave,
  onCancel,
  hasChanges,
  isSaving = false,
  error,
}: CardSelectPaneProps) {
  // A unique name keeps the radio group isolated when several panes render.
  const groupName = useId();

  return (
    <Pane
      icon={icon}
      title={title}
      description={description}
      onSave={onSave}
      onCancel={onCancel}
      isSaving={isSaving}
      // Saving is useful only when there is a selection that differs from the
      // already-confirmed value.
      saveDisabled={!value || !hasChanges}
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
  );
}
