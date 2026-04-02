/**
 * SSE (Server-Sent Events) helpers for Express routes.
 *
 * Provides functions to set up SSE responses and send
 * individual events using the Vercel AI SDK UI Message Stream
 * Protocol. Express writes directly to the Node.js response
 * without any pipeline interference.
 */

import type { Response } from "express";

/**
 * Sets up SSE headers on an Express response.
 * Disables Nagle's algorithm for immediate per-write flushing.
 * Includes the x-vercel-ai-ui-message-stream header to signal
 * protocol version to the client.
 */
export function setupSseResponse(res: Response): void {
  res.socket?.setNoDelay(true);
  res.writeHead(200, {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache",
    Connection: "keep-alive",
    "X-Accel-Buffering": "no",
    "x-vercel-ai-ui-message-stream": "v1",
  });
}

/**
 * Sends a single Data Stream Protocol event as an SSE data frame.
 */
export function sendEvent(res: Response, data: Record<string, unknown>): void {
  res.write(`data: ${JSON.stringify(data)}\n\n`);
}

/**
 * Sends the [DONE] sentinel to signal the end of the SSE stream.
 * This is a literal string, not a JSON object.
 */
export function sendDone(res: Response): void {
  res.write("data: [DONE]\n\n");
}
