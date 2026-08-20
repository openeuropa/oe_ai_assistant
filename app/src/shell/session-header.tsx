/**
 * Session header.
 *
 * Always-present bar at the top of the workspace, owned by the shell and
 * plugin agnostic. Shows the session title supplied by the host config and
 * hosts session-level controls (e.g. the exit link). Falls back to a
 * neutral title when the host does not supply one.
 */

import { Bot } from "lucide-react";
import { getConfig } from "@/config";
import { ExitControl } from "@/shell/exit-control";

export function SessionHeader() {
  const title = getConfig().sessionTitle.trim() || "Editorial session";

  return (
    <header className="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-2.5">
      <div className="flex min-w-0 items-center gap-2">
        <Bot size={22} className="shrink-0 text-gray-700" aria-hidden="true" />
        <h1 className="truncate text-lg font-semibold text-gray-900">
          {title}
        </h1>
      </div>
      {/* Session-level controls (exit link, future controls). */}
      <div className="flex items-center gap-2">
        <ExitControl />
      </div>
    </header>
  );
}
