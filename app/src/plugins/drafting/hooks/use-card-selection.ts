import { useEffect, useState } from "react";
import type { RadioCardOption } from "@/components/ui/radio-card-group";
import { useAppStore } from "@/store";
import { setDraftingSelection, useDraftingSelection } from "../store";

export interface UseCardSelectionParams {
  /** Panel id; the key under which the confirmed value lives in the store. */
  panelId: string;
  /** Card options to choose from. */
  options: RadioCardOption[];
  /** Persists the selected value; the confirmed store value updates on success. */
  onSave: (value: string) => void | Promise<void>;
}

/**
 * State machine for a single card-selection pane (tone, template, ...).
 *
 * The confirmed value lives in the drafting store (keyed by panel id) so every
 * panel persists the same way and other components can read it. The in-progress
 * draft value is local until Save. Callers only supply the panel id, its
 * options, and how to persist the choice.
 */
export function useCardSelection({
  panelId,
  options,
  onSave,
}: UseCardSelectionParams) {
  const stored = useDraftingSelection(panelId);
  const setPendingWork = useAppStore((s) => s.setPendingWork);
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  // The confirmed value is the stored selection, or the first option until the
  // editor saves a different one.
  const confirmed = stored || options[0]?.value || "";
  const [value, setValue] = useState(confirmed);

  const hasChanges = value !== confirmed;
  const selectedLabel =
    options.find((option) => option.value === confirmed)?.label ?? null;

  useEffect(() => {
    // Keep the draft in sync when the confirmed value changes (e.g. rehydration
    // from the server, or the host options becoming available).
    setValue(confirmed);
  }, [confirmed]);

  function updateValue(next: string) {
    setValue(next);
    setError(null);
  }

  function discardChanges() {
    // Restore the draft to the confirmed value, dropping any pending change.
    setValue(confirmed);
    setError(null);
  }

  async function submitValues() {
    if (!value || !hasChanges) {
      return;
    }

    setError(null);
    setIsSaving(true);
    // Report the in-flight save to the shell exit guard under a
    // panel-scoped source key, so navigating away is blocked until the
    // backend has accepted (or rejected) the selection.
    setPendingWork(`drafting:${panelId}`, true);
    try {
      // Only confirm locally after the backend accepts the selection.
      await onSave(value);
      setDraftingSelection(panelId, value);
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : "Could not save the selection.",
      );
      throw caughtError;
    } finally {
      setIsSaving(false);
      setPendingWork(`drafting:${panelId}`, false);
    }
  }

  return {
    options,
    value,
    updateValue,
    discardChanges,
    submitValues,
    hasChanges,
    isSaving,
    error,
    selectedLabel,
  };
}
