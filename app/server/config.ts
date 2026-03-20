/**
 * Dev server configuration.
 *
 * Shared settings for all mock API routes. Centralizes values
 * like streaming delay so they can be tuned in one place.
 */

/** Delay in ms between each SSE chunk when streaming. */
export const SSE_CHUNK_DELAY_MS = 10;

/** Delay in ms between each word when streaming a field value. */
export const FIELD_WORD_DELAY_MS = 20;

/** Delay in ms between completing one field and starting the next. */
export const FIELD_GAP_DELAY_MS = 80;
