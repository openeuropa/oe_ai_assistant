/**
 * Session header.
 *
 * Always-present bar at the top of the workspace, owned by the shell and
 * plugin agnostic. Shows the session title supplied by the host config and
 * hosts session-level controls (e.g. the exit link). Falls back to a
 * neutral title when the host does not supply one.
 */

import { Bot } from "lucide-react";
import { AvatarStack, avatarColorClass } from "@/components/ui/user-avatar";
import { getConfig } from "@/config";
import { ExitControl } from "@/shell/exit-control";
import { useAppStore } from "@/store";

export function SessionHeader() {
  const title = getConfig().sessionTitle.trim() || "Editorial session";
  // Contributors in the order of their first message: this order fixes
  // the palette colors, so they are identical for every viewer. Only
  // the DISPLAY order boosts the current user to the front.
  const contributors = useAppStore((s) => s.sessionParticipants);
  const currentName = getConfig().userName.trim();
  const stackItems = [
    ...(currentName ? [currentName] : []),
    ...contributors.filter((name) => name !== currentName),
  ].map((name) => ({
    name,
    colorClass: avatarColorClass(contributors.indexOf(name)),
  }));

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
