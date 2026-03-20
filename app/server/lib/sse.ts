/**
 * SSE (Server-Sent Events) helpers for Express routes.
 *
 * Provides functions to set up SSE responses and send
 * individual events. Express writes directly to the Node.js
 * response without any pipeline interference.
 */

import type { Response } from "express";

/**
 * Sets up SSE headers on an Express response.
 * Disables Nagle's algorithm for immediate per-write flushing.
 */
export function setupSseResponse(res: Response): void {
  res.socket?.setNoDelay(true);
  res.writeHead(200, {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache",
    Connection: "keep-alive",
    "X-Accel-Buffering": "no",
  });
}

/**
 * Sends a single AG-UI event as an SSE data frame.
 */
export function sendEvent(
  res: Response,
  data: Record<string, unknown>,
): void {
  res.write(`data: ${JSON.stringify(data)}\n\n`);
}
