/**
 * Echo API client.
 *
 * Sends a message to the echo plugin endpoint and returns the raw
 * Response so the caller can read the SSE stream from its body.
 */

import { getConfig } from "@/config";
import type { EchoRequest } from "../types";

/**
 * POST /plugins/echo/stream
 *
 * Returns a Response whose body is an SSE stream. The caller is
 * responsible for reading and parsing the stream.
 */
export async function postEcho(request: EchoRequest): Promise<Response> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/echo/stream`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(request),
    },
  );

  if (!response.ok) {
    throw new Error(`Echo API error: ${response.status}`);
  }

  return response;
}
