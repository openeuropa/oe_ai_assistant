/**
 * Development API server.
 *
 * Express server with mock endpoints for standalone frontend
 * work. The drafting plugin runs in fixture-backed mock mode by
 * default and can be switched to explicit Mistral integration
 * mode when provider-backed development is needed.
 *
 * Uses dynamic imports so dotenv loads before any module that
 * reads process.env (ES module imports are hoisted, so static
 * imports would evaluate before dotenv.config() runs).
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import { config } from "dotenv";
import type { ConversationStore } from "./services/conversation-store";
import type { DraftingService } from "./services/drafting-service";
import type { TranscriptStore } from "./services/transcript-store";

// Load .env before any other modules read env vars.
// The server runs as a standalone Node process via tsx,
// not through Vite, so Vite's .env loading does not apply.
config({ path: join(import.meta.dirname, "..", ".env") });

const PORT = 5150;

async function start(): Promise<void> {
  // Dynamic imports so modules that read process.env at the
  // top level (config.ts) see the dotenv-injected values.
  const { default: express } = await import("express");
  const { resolveDraftingMode } = await import("./config");
  const { createDraftingRouter } = await import("./routes/drafting");
  const { echoRouter } = await import("./routes/echo");
  const { notesRouter } = await import("./routes/notes");
  const { ConversationStore } = await import("./services/conversation-store");
  const { TranscriptStore } = await import("./services/transcript-store");
  const draftingMode = resolveDraftingMode();

  const app = express();

  // Create shared service instances.
  const store = new ConversationStore();
  const transcript = new TranscriptStore();
  const draftingService: DraftingService =
    draftingMode === "mistral"
      ? await createMistralDraftingService(store, transcript)
      : await createMockDraftingService(store, transcript);

  // Parse JSON bodies for all routes.
  app.use(express.json());

  // Content schema endpoint. Serves fixture files that mirror
  // the Drupal /api/ai/content-schema/{entityTypeId}/{bundle}
  // endpoint. The frontend fetches this to resolve field labels,
  // types, and inline entity definitions.
  app.get("/api/content-schema/:entityTypeId/:bundle", (req, res) => {
    const { bundle } = req.params;
    const fixturePath = join(
      import.meta.dirname,
      "fixtures",
      `content-schema-${bundle}.json`,
    );
    try {
      const data = readFileSync(fixturePath, "utf-8");
      res.type("json").send(data);
    } catch {
      res.status(404).json({
        error: `No schema fixture for bundle "${bundle}"`,
      });
    }
  });

  // Mount route modules.
  app.use("/api/plugins/echo", echoRouter);
  app.use("/api/plugins/notes", notesRouter);
  app.use("/api/plugins/drafting", createDraftingRouter(draftingService));

  app.listen(PORT, () => {
    console.log(
      `Dev API server running at http://localhost:${PORT} ` +
        `(drafting mode: ${draftingMode})`,
    );
  });
}

async function createMockDraftingService(
  store: ConversationStore,
  transcript: TranscriptStore,
): Promise<DraftingService> {
  const { MockDraftingService } = await import(
    "./services/mock-drafting-service"
  );
  return new MockDraftingService(store, transcript);
}

async function createMistralDraftingService(
  store: ConversationStore,
  transcript: TranscriptStore,
): Promise<DraftingService> {
  const { createMistralClient } = await import("./lib/mistral");
  const { MistralDraftingService } = await import(
    "./services/drafting-service"
  );
  return new MistralDraftingService(createMistralClient(), store, transcript);
}

start().catch((err) => {
  console.error("Failed to start server:", err);
  process.exit(1);
});
