/**
 * Session header.
 *
 * Always-present bar at the top of the workspace, owned by the shell and
 * plugin agnostic. Shows the session title supplied by the host config and
 * hosts session-level controls (e.g. the exit link). Falls back to a
 * neutral title when the host does not supply one.
 */

import { Bot } from "lucide-react";
import {
  AvatarStack,
  type AvatarStackItem,
  avatarColorClass,
} from "@/components/ui/user-avatar";
import { getConfig } from "@/config";
import { ExitControl } from "@/shell/exit-control";
import { type SessionParticipant, useAppStore } from "@/store";

/**
 * Builds the avatar stack for the session header.
 *
 * Contributors are listed in the order of their first message: this
 * order fixes the palette colors, so they are identical for every
 * viewer. Only the DISPLAY order boosts the current user to the front.
 * A current user who has not contributed yet is colored with the next
 * free palette position (the one their first message will take) rather
 * than the neutral gray; other viewers do not see them until they
 * contribute, so the provisional color is never visibly inconsistent.
 */
export function buildAvatarStackItems(
  contributors: SessionParticipant[],
  currentUser: SessionParticipant,
): AvatarStackItem[] {
  const currentName = currentUser.name.trim();
  const colorIndex = (id: string): number => {
    const index = contributors.findIndex((c) => c.id === id);
    return index >= 0 ? index : contributors.length;
  };
  return [
    ...(currentName ? [{ id: currentUser.id, name: currentName }] : []),
    ...contributors.filter((p) => p.id !== currentUser.id),
  ].map((p) => ({ ...p, colorClass: avatarColorClass(colorIndex(p.id)) }));
}

export function SessionHeader() {
  const title = getConfig().sessionTitle.trim() || "Editorial session";
  const contributors = useAppStore((s) => s.sessionParticipants);
  const stackItems = buildAvatarStackItems(contributors, {
    id: getConfig().userId,
    name: getConfig().userName,
  });

  return (
    <header className="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-2.5">
      <div className="flex min-w-0 items-center gap-2">
        <Bot size={22} className="shrink-0 text-gray-700" aria-hidden="true" />
        <h1 className="truncate text-lg font-semibold text-gray-900">
          {title}
        </h1>
      </div>
      {/* Session-level controls (participants, exit link, ...). */}
      <div className="flex items-center gap-4">
        {stackItems.length > 0 && <AvatarStack items={stackItems} />}
        <ExitControl />
      </div>
    </header>
  );
}
