import { useEffect, useState } from "react";
import { getConfig } from "@/config";
import { setDraftingTone } from "../api/drafting-api";
import type {
  GenerationSettingsDraft,
  GenerationSettingsOption,
} from "../components/generation-settings-panel";
import { setDraftingState, useDraftingSlice } from "../store";
import type { DraftingGenerationSettings } from "../types";

function getGenerationSettingsOptions(
  value: unknown,
): GenerationSettingsOption[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter(
    (option): option is GenerationSettingsOption =>
      Boolean(option) &&
      typeof option === "object" &&
      typeof (option as Record<string, unknown>).id === "string" &&
      typeof (option as Record<string, unknown>).label === "string" &&
      typeof (option as Record<string, unknown>).description === "string",
  );
}

/**
 * Owns the tone selector state.
 *
 * The panel edits local draft values first. The selected tone only becomes
 * confirmed after the set-tone request succeeds, so the UI can clearly show
 * whether the next generation will use a saved tone or a pending local change.
 */
export function useDraftingGenerationSettings() {
  const { generationSettings } = useDraftingSlice();
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const context = draftingConfig.context as Record<string, unknown> | undefined;
  const toneOptions = getGenerationSettingsOptions(context?.tone);

  // With no placeholder option in the select, the first backend-provided tone
  // is the visible default until the editor saves a different tone.
  const defaultToneId = generationSettings?.toneId ?? toneOptions[0]?.id ?? "";
  const [values, setValues] = useState<GenerationSettingsDraft>({
    toneId: defaultToneId,
  });
  const hasChanges = values.toneId !== (generationSettings?.toneId ?? "");
  const selectedTone = toneOptions.find(
    (option) => option.id === values.toneId,
  );

  useEffect(() => {
    // Keep the controlled select in sync when saved state is restored from the
    // scoped store or when the host-provided tone config becomes available.
    setValues({ toneId: defaultToneId });
  }, [defaultToneId]);

  function updateValues(nextValues: GenerationSettingsDraft) {
    setValues(nextValues);
    setError(null);
  }

  async function submitValues() {
    if (!values.toneId || !hasChanges) {
      return;
    }

    const nextSettings: DraftingGenerationSettings = {
      toneId: values.toneId,
    };

    setError(null);
    setIsSaving(true);
    try {
      // Only persist locally after the backend accepts the selected tone.
      // Until then, hasChanges remains true and the collapsed trigger is marked.
      await setDraftingTone({ context: nextSettings });
      setDraftingState({ generationSettings: nextSettings });
    } catch (caughtError) {
      const message =
        caughtError instanceof Error
          ? caughtError.message
          : "Could not save the tone setting.";
      setError(message);
      throw caughtError;
    } finally {
      setIsSaving(false);
    }
  }

  return {
    error,
    hasChanges,
    isSaving,
    selectedLabel: selectedTone?.label ?? null,
    submitValues,
    toneOptions,
    updateValues,
    values,
  };
}
