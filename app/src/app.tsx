/**
 * Application shell.
 *
 * Wraps the entire app with the providers it needs (TanStack Query for
 * server state, HashRouter for CMS-safe routing) and renders the
 * top-level layout: a session header spanning the workspace, a
 * collapsible sidebar for plugin navigation, and the plugin viewport.
 */

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { HashRouter } from "react-router-dom";
import { Nav } from "@/shell/nav";
import { SessionHeader } from "@/shell/session-header";
import { Viewport } from "@/shell/viewport";

/** Shared query client with sensible defaults for an editorial tool. */
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
});

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <HashRouter>
        <div className="flex h-full flex-col overflow-hidden">
          {/* Shell-owned session frame spanning the full workspace width. */}
          <SessionHeader />
          <div className="flex min-h-0 flex-1 overflow-hidden">
            <Nav />
            <main className="flex flex-1 flex-col overflow-hidden">
              <Viewport />
            </main>
          </div>
        </div>
      </HashRouter>
    </QueryClientProvider>
  );
}
