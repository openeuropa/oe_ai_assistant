/**
 * Development API server.
 *
 * Express server with real Mistral LLM integration for the
 * drafting plugin and mock endpoints for echo and notes.
 * Runs alongside the Vite dev server and receives proxied
 * requests from /api/*.
 *
 * Uses dynamic imports so dotenv loads before any module that
 * reads process.env (ES module imports are hoisted, so static
 * imports would evaluate before dotenv.config() runs).
 */

import { config } from "dotenv";
import { join } from "node:path";

// Load .env before any other modules read env vars.
// The server runs as a standalone Node process via tsx,
// not through Vite, so Vite's .env loading does not apply.
config({ path: join(import.meta.dirname, "..", ".env") });

const PORT = 5150;

async function start(): Promise<void> {
  // Dynamic imports so modules that read process.env at the
  // top level (config.ts) see the dotenv-injected values.
  const { default: express } = await import("express");
  const { createMistralClient } = await import("./lib/mistral");
  const { echoRouter } = await import("./routes/echo");
  const { notesRouter } = await import("./routes/notes");
  const { createDraftingRouter } = await import(
    "./routes/drafting"
  );
  const { ConversationStore } = await import(
    "./services/conversation-store"
  );
  const { DraftingService } = await import(
    "./services/drafting-service"
  );

  const app = express();

  // Create shared service instances.
  const mistral = createMistralClient();
  const store = new ConversationStore();
  const draftingService = new DraftingService(mistral, store);

  // Parse JSON bodies for all routes.
  app.use(express.json());

  // Mount route modules.
  app.use("/api/plugins/echo", echoRouter);
  app.use("/api/plugins/notes", notesRouter);
  app.use(
    "/api/plugins/drafting",
    createDraftingRouter(draftingService),
  );

  app.listen(PORT, () => {
    console.log(
      `Dev API server running at http://localhost:${PORT}`,
    );
  });
}

start().catch((err) => {
  console.error("Failed to start server:", err);
  process.exit(1);
});
