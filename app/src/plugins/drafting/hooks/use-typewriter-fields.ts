/**
 * Typewriter effect for drafted fields.
 *
 * Purely cosmetic: when new field values arrive all at once (as
 * they do from Mistral), this hook progressively reveals each
 * field's text content character by character, giving the
 * appearance of live streaming. All fields animate in parallel.
 *
 * The hook compares incoming fields against its internal revealed
 * state. When a field's value changes (or a new field appears),
 * a reveal animation starts for that field. Fields whose values
 * have not changed pass through untouched.
 *
 * For HTML fields (type "html"), the text is revealed by slicing
 * the raw HTML string. This works well for simple markup but may
 * briefly show unclosed tags during the animation. The final
 * state is always the complete, correct HTML.
 */

import { useEffect, useRef, useState } from "react";
import type { DraftedField } from "../store";

/** Characters revealed per tick. Higher = faster typing. */
const CHARS_PER_TICK = 8;

/** Milliseconds between ticks. 16ms ~ 60fps. */
const TICK_INTERVAL_MS = 16;

/** Internal state for one field's reveal animation. */
interface FieldRevealState {
  /** The full target value to reveal. */
  target: string;
  /** Number of characters currently revealed. */
  revealed: number;
  /** Whether this field's animation is complete. */
  done: boolean;
}

/**
 * Returns a copy of draftedFields where values are progressively
 * revealed with a typewriter effect. All fields animate in
 * parallel.
 *
 * @param draftedFields - The real (complete) drafted fields from
 *   the store.
 * @returns A record of DraftedField objects with partially
 *   revealed values, plus a boolean indicating whether any field
 *   is still animating.
 */
export function useTypewriterFields(
  draftedFields: Record<string, DraftedField>,
): {
  fields: Record<string, DraftedField>;
  isAnimating: boolean;
} {
  // Track per-field reveal state across renders.
  const revealRef = useRef<Record<string, FieldRevealState>>({});
  // Force re-renders during animation via a counter.
  const [, setTick] = useState(0);
  // Timer ref for cleanup.
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Detect new or changed fields and start their animations.
  useEffect(() => {
    const current = revealRef.current;

    for (const [name, field] of Object.entries(draftedFields)) {
      const targetValue = extractText(field);
      const existing = current[name];

      if (!existing || existing.target !== targetValue) {
        // New field or value changed: start reveal from zero.
        current[name] = {
          target: targetValue,
          revealed: 0,
          done: false,
        };
      }
    }

    // Remove fields that are no longer in the input.
    for (const name of Object.keys(current)) {
      if (!(name in draftedFields)) {
        delete current[name];
      }
    }

    // Start the animation timer if there are pending animations
    // and the timer is not already running. Check for any field
    // that is not fully revealed (not just new animations) to
    // handle React StrictMode remounts: the cleanup function
    // clears the timer, but refs persist, so on the second mount
    // we need to restart the timer for fields still in progress.
    const hasPending = Object.values(current).some((s) => !s.done);
    if (hasPending && !timerRef.current) {
      timerRef.current = setInterval(() => {
        let allDone = true;

        for (const state of Object.values(revealRef.current)) {
          if (!state.done) {
            state.revealed = Math.min(
              state.revealed + CHARS_PER_TICK,
              state.target.length,
            );
            if (state.revealed >= state.target.length) {
              state.done = true;
            } else {
              allDone = false;
            }
          }
        }

        // Trigger a re-render to update the displayed values.
        setTick((t) => t + 1);

        // Stop the timer when all fields are fully revealed.
        if (allDone && timerRef.current) {
          clearInterval(timerRef.current);
          timerRef.current = null;
        }
      }, TICK_INTERVAL_MS);
    }
  }, [draftedFields]);

  // Clean up timer on unmount.
  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
    };
  }, []);

  // Build the output fields with partially revealed values.
  const fields: Record<string, DraftedField> = {};
  let isAnimating = false;

  for (const [name, field] of Object.entries(draftedFields)) {
    const state = revealRef.current[name];
    if (!state || state.done) {
      // Fully revealed or unknown: pass through as-is.
      fields[name] = field;
    } else {
      isAnimating = true;
      fields[name] = applyReveal(field, state.revealed);
    }
  }

  return { fields, isAnimating };
}

/**
 * Extracts the text content from a DraftedField for reveal
 * tracking. For HTML fields, uses the raw HTML string. For
 * plain fields, uses the value directly.
 */
function extractText(field: DraftedField): string {
  return field.value ?? "";
}

/**
 * Creates a copy of a DraftedField with only the first N
 * characters of its value revealed.
 */
function applyReveal(field: DraftedField, revealed: number): DraftedField {
  const partial = (field.value ?? "").slice(0, revealed);
  return { ...field, value: partial };
}
