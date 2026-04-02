/**
 * Echo SSE endpoint (mock).
 *
 * POST /api/plugins/echo/stream
 * Body: { "message": "some text" }
 *
 * Streams the message back word-by-word as Server-Sent Events
 * using the Data Stream Protocol. Each word is sent as a
 * data-echo custom event. The stream is wrapped in start/finish
 * lifecycle events.
 */

import { randomUUID } from "node:crypto";
import { Router } from "express";
import { SSE_CHUNK_DELAY_MS } from "../config";
import { sendDone, sendEvent, setupSseResponse } from "../lib/sse";

export const echoRouter = Router();

/** Delay helper. */
function delay(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

echoRouter.post("/stream", async (req, res) => {
  const message = req.body?.message;

  if (!message || typeof message !== "string" || message.trim().length === 0) {
    res
      .status(400)
      .json({ code: "bad_request", message: "message is required" });
    return;
  }

  setupSseResponse(res);

  // Emit stream lifecycle start event.
  const messageId = randomUUID();
  sendEvent(res, { type: "start", messageId });

  const words = message.trim().split(/\s+/);

  // Stream words one at a time with a delay between each.
  for (let i = 0; i < words.length; i++) {
    sendEvent(res, {
      type: "data-echo",
      data: {
        word: words[i]!,
        index: i,
        done: i === words.length - 1,
      },
    });
    await delay(SSE_CHUNK_DELAY_MS);
  }

  // Emit stream lifecycle finish event.
  sendEvent(res, { type: "finish", finishReason: "stop" });

  // Send the [DONE] sentinel to signal stream termination.
  sendDone(res);
  res.end();
});
