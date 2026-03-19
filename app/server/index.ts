/**
 * Development API server.
 *
 * Lightweight Express server with in-memory storage that implements
 * the dev plugin API endpoints. Runs alongside the Vite dev server
 * and receives proxied requests from /api/*.
 *
 * URL structure follows the spec:
 *   /api/plugins/echo/...   Echo plugin endpoints
 *   /api/plugins/notes/...  Notes plugin endpoints
 */

import express from "express";
import { draftingRouter } from "./routes/drafting";
import { echoRouter } from "./routes/echo";
import { notesRouter } from "./routes/notes";

const app = express();
const PORT = 5150;

// Mount SSE routes BEFORE express.json(). These handlers parse
// the body manually because Express 5's async body parser
// interferes with SSE streaming on the response.
app.use("/api/plugins/echo", echoRouter);
app.use("/api/plugins/drafting", draftingRouter);

// Parse JSON request bodies for all other routes.
app.use(express.json());

// Mount remaining route modules.
app.use("/api/plugins/notes", notesRouter);

app.listen(PORT, () => {
  console.log(`Dev API server running at http://localhost:${PORT}`);
});
