/**
 * Dev server configuration.
 *
 * Shared settings for all mock API routes. Centralizes values
 * like streaming delay so they can be tuned in one place.
 */

/** Delay in ms between each SSE chunk when streaming. */
export const SSE_CHUNK_DELAY_MS = 10;
