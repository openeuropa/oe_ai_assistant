import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/** A single selectable option rendered as a card. */
export interface RadioCardOption {
  /** The value reported when this option is selected. */
  value: string;
  /** The card title. */
  label: string;
  /** Optional supporting text shown under the title. */
  description?: string;
}

export interface RadioCardGroupProps {
  /** Radio group name; must be unique per rendered instance. */
  name: string;
  /** Available options to choose from. */
  options: RadioCardOption[];
  /** The currently selected value. */
  value: string;
  /** Reports the newly selected value. */
  onChange: (value: string) => void;
  /** Disables selection (e.g. when no options are available). */
  disabled?: boolean;
  /** Extra classes for the grid container, e.g. to change column count. */
  className?: string;
}

/**
 * Card based single-select control.
 *
 * Renders each option as a selectable card that shows its title and
 * optional description together, so choices can be compared without
 * opening a dropdown. Native radio inputs back the cards to keep keyboard
 * and screen reader support; the card styling is driven by the checked
 * state. Reusable across plugins for any card-style single selection.
 */
export function RadioCardGroup({
  name,
  options,
  value,
  onChange,
  disabled,
  className,
}: RadioCardGroupProps) {
  return (
    // Native radio inputs already expose radiogroup semantics; the label
    // cards are the visible control. Two columns keep the list compact.
    <div className={cn("grid gap-2 sm:grid-cols-2", className)}>
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <label
            key={option.value}
            className={cn(
              "flex cursor-pointer items-center gap-3 rounded-lg border bg-white p-3 transition-colors",
              selected
                ? "border-green-500 ring-1 ring-green-500"
                : "border-gray-300 hover:border-gray-400",
              disabled && "cursor-not-allowed opacity-60 hover:border-gray-300",
            )}
          >
            {/* The radio is visually hidden but drives selection and a11y. */}
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={selected}
              onChange={() => onChange(option.value)}
              disabled={disabled}
              className="sr-only"
            />
            {/* Vertically centered selection indicator on the left. */}
            <span
              className={cn(
                "flex h-5 w-5 shrink-0 items-center justify-center rounded-full",
                selected
                  ? "bg-green-500 text-white"
                  : "border border-gray-300 bg-white",
              )}
            >
              {selected && <Check size={12} strokeWidth={3} />}
            </span>
            <span className="flex flex-col gap-0.5">
              <span className="text-sm font-medium text-gray-900">
                {option.label}
              </span>
              {option.description && (
                <span className="text-xs text-gray-500">
                  {option.description}
                </span>
              )}
            </span>
          </label>
        );
      })}
    </div>
  );
}
