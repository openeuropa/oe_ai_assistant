/**
 * EventChip component.
 *
 * Renders a compact, centered, pill-shaped chip for editorial events in the
 * chat history. Visually distinct from message bubbles and tool-call cards:
 * small, muted, and not interactive.
 */

import {
  AlertCircle,
  Info,
  LayoutTemplate,
  Megaphone,
  Save,
  Sparkles,
} from "lucide-react";
import type * as React from "react";

/** Props for EventChip. */
export interface EventChipProps {
  /** Machine-readable event type that determines the leading icon. */
  eventType: string;
  /** Human-readable summary text rendered inside the pill. */
  summary: string;
  /**
   * ISO timestamp or label shown as a title attribute (tooltip on hover).
   * Not rendered as visible text.
   */
  at?: string;
}

/**
 * Returns the Lucide icon component that matches the given event type.
 *
 * Megaphone for tone changes, LayoutTemplate for template changes,
 * Sparkles for session start, Save for draft saves, AlertCircle for
 * errors, and Info for everything else.
 */
function iconForEventType(eventType: string): React.ElementType {
  switch (eventType) {
    case "tone":
      return Megaphone;
    case "template":
      return LayoutTemplate;
    case "session_start":
      return Sparkles;
    case "save":
      return Save;
    case "error":
      return AlertCircle;
    default:
      return Info;
  }
}

/**
 * Returns the Tailwind class string for the chip based on event type.
 *
 * Error chips use red styling to signal a failed operation; all other
 * chips use the default muted grey styling.
 */
function classesForEventType(eventType: string): string {
  if (eventType === "error") {
    return "inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs text-red-600";
  }
  return "inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs text-gray-500";
}

/**
 * Non-clickable event chip shown inline in the chat thread.
 *
 * Left alignment is achieved with a flex wrapper so the pill never
 * stretches to full width. The optional `at` prop adds a browser tooltip.
 */
export function EventChip({ eventType, summary, at }: EventChipProps) {
  const Icon = iconForEventType(eventType);
  const classes = classesForEventType(eventType);

  return (
    <div className="flex justify-start">
      <span className={classes} title={at}>
        <Icon size={12} className="shrink-0" aria-hidden="true" />
        {summary}
      </span>
    </div>
  );
}
