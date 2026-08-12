/**
 * EventChip component.
 *
 * Renders a compact, centered, pill-shaped chip for editorial events in the
 * chat history. Visually distinct from message bubbles and tool-call cards:
 * small, muted, and not interactive.
 */

import { Info, LayoutTemplate, Megaphone, Sparkles } from "lucide-react";
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
 * Sparkles for session start, and Info for everything else.
 */
function iconForEventType(eventType: string): React.ElementType {
  switch (eventType) {
    case "tone":
      return Megaphone;
    case "template":
      return LayoutTemplate;
    case "session_start":
      return Sparkles;
    default:
      return Info;
  }
}

/**
 * Non-clickable event chip shown inline in the chat thread.
 *
 * Left alignment is achieved with a flex wrapper so the pill never
 * stretches to full width. The optional `at` prop adds a browser tooltip.
 */
export function EventChip({ eventType, summary, at }: EventChipProps) {
  const Icon = iconForEventType(eventType);

  return (
    <div className="flex justify-start">
      <span
        className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs text-gray-500"
        title={at}
      >
        <Icon size={12} className="shrink-0" aria-hidden="true" />
        {summary}
      </span>
    </div>
  );
}
