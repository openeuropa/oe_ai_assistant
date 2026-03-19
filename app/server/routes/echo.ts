/**
 * Echo SSE endpoint.
 *
 * POST /api/plugins/echo/stream
 * Body: { "message": "some text" }
 *
 * Streams the message back word-by-word as Server-Sent Events,
 * simulating the AI streaming pattern. Each event is a JSON object
 * with { word, index, done }. Words are sent with a 150ms delay
 * between them.
 *
 * This route parses the request body manually (instead of using
 * express.json()) because Express 5's body parser is async and
 * can interfere with SSE streaming on the response.
 */

import { Router } from "express";
import { SSE_CHUNK_DELAY_MS } from "../config";

export const echoRouter = Router();

/** Reads the raw request body and parses it as JSON. */
function readJsonBody(req: import("express").Request): Promise<unknown> {
  return new Promise((resolve, reject) => {
    let data = "";
    req.on("data", (chunk: Buffer) => {
      data += chunk.toString();
    });
    req.on("end", () => {
      try {
        resolve(JSON.parse(data));
      } catch {
        reject(new Error("Invalid JSON"));
      }
    });
    req.on("error", reject);
  });
}

echoRouter.post("/stream", async (req, res) => {
  // Parse body manually to avoid express.json() interfering with SSE.
  const body = (await readJsonBody(req)) as { message?: string };
  const message = body.message;

  if (!message || typeof message !== "string" || message.trim().length === 0) {
    res
      .status(400)
      .json({ code: "bad_request", message: "message is required" });
    return;
  }

  // Disable Nagle's algorithm so each write flushes immediately.
  res.socket?.setNoDelay(true);

  // Set SSE headers and send them to the client right away.
  res.writeHead(200, {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache",
    Connection: "keep-alive",
    "X-Accel-Buffering": "no",
  });

  const words = message.trim().split(/\s+/);
  let index = 0;

  // Send one word per interval as an SSE "data:" frame.
  const interval = setInterval(() => {
    if (index >= words.length) {
      clearInterval(interval);
      res.end();
      return;
    }

    const event = {
      word: words[index]!,
      index,
      done: index === words.length - 1,
    };

    res.write(`data: ${JSON.stringify(event)}\n\n`);
    index++;
  }, SSE_CHUNK_DELAY_MS);

  // Clean up if the client disconnects early.
  req.on("close", () => {
    clearInterval(interval);
  });
});
