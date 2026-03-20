/**
 * Echo SSE endpoint (mock).
 *
 * POST /api/plugins/echo/stream
 * Body: { "message": "some text" }
 *
 * Streams the message back word-by-word as Server-Sent Events.
 * Each event is a JSON object with { word, index, done }.
 * Words are sent with a configurable delay between them.
 */

import { Router } from "express";
import { SSE_CHUNK_DELAY_MS } from "../config";
import { sendEvent, setupSseResponse } from "../lib/sse";

export const echoRouter = Router();

/** Delay helper. */
function delay(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

echoRouter.post("/stream", async (req, res) => {
  const message = req.body?.message;

  if (
    !message ||
    typeof message !== "string" ||
    message.trim().length === 0
  ) {
    res
      .status(400)
      .json({ code: "bad_request", message: "message is required" });
    return;
  }

  setupSseResponse(res);
  const words = message.trim().split(/\s+/);

  // Stream words one at a time with a delay between each.
  for (let i = 0; i < words.length; i++) {
    sendEvent(res, {
      word: words[i]!,
      index: i,
      done: i === words.length - 1,
    });
    await delay(SSE_CHUNK_DELAY_MS);
  }

  res.end();
});
