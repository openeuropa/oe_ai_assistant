/**
 * Application shell.
 *
 * Wraps the entire app with the providers it needs (TanStack Query for
 * server state, HashRouter for CMS-safe routing) and renders the
 * top-level layout: a collapsible sidebar for plugin navigation and
 * a main content area with a header and plugin viewport.
 */

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { HashRouter } from "react-router-dom";
import { Header } from "@/shell/header";
import { Nav } from "@/shell/nav";
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
        <div className="flex h-full overflow-hidden">
          <Nav />
          <div className="flex flex-1 flex-col overflow-hidden">
            <Header />
            <main className="flex flex-1 flex-col overflow-hidden">
              <Viewport />
            </main>
          </div>
        </div>
      </HashRouter>
    </QueryClientProvider>
  );
}
