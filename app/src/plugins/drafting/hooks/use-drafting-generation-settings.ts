import { useEffect, useState } from "react";
import { getConfig } from "@/config";
import { fetchDraftingTone, setDraftingTone } from "../api/drafting-api";
import { setDraftingState, useDraftingSlice } from "../store";
import type { DraftingGenerationSettings } from "../types";

/** A host-provided tone option. */
export interface GenerationSettingsOption {
  id: string;
  label: string;
  description: string;
}

/** The controlled tone draft edited by the panel. */
export interface GenerationSettingsDraft {
  toneId: string;
}

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
  const toneConfig = draftingConfig.tone as
    | { enabled?: boolean; options?: unknown }
    | undefined;
  const enabled = toneConfig?.enabled ?? false;
  const toneOptions = getGenerationSettingsOptions(toneConfig?.options);

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
    // Rehydrate the confirmed tone from the backend on mount so the selector
    // reflects the tone currently saved for the session. Nothing is persisted
    // client side, so the server is the single source of truth.
    let active = true;
    fetchDraftingTone().then((settings) => {
      if (active) {
        setDraftingState({ generationSettings: settings });
      }
    });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    // Keep the controlled select in sync when saved state is restored from the
    // scoped store or when the host-provided tone config becomes available.
    setValues({ toneId: defaultToneId });
  }, [defaultToneId]);

  function updateValues(nextValues: GenerationSettingsDraft) {
    setValues(nextValues);
    setError(null);
  }

  function discardChanges() {
    // Restore the controlled selection to the confirmed tone, dropping any
    // pending local change. Used when the editor cancels the panel.
    setValues({ toneId: defaultToneId });
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
      await setDraftingTone(nextSettings);
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
    enabled,
    discardChanges,
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
