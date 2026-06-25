import { useEffect, useState } from "react";
import { getConfig } from "@/config";
import { saveDraftingSession } from "../api/drafting-api";
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

export function useDraftingGenerationSettings() {
  const { generationSettings } = useDraftingSlice();
  const [values, setValues] = useState<GenerationSettingsDraft>({
    toneId: generationSettings?.toneId ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const context = draftingConfig.context as Record<string, unknown> | undefined;
  const toneOptions = getGenerationSettingsOptions(context?.tone);
  const hasChanges = values.toneId !== (generationSettings?.toneId ?? "");
  const selectedTone = toneOptions.find(
    (option) => option.id === values.toneId,
  );

  useEffect(() => {
    if (generationSettings) {
      setValues({ toneId: generationSettings.toneId });
    }
  }, [generationSettings]);

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
      await saveDraftingSession({ context: nextSettings });
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
