/**
 * Dev server configuration.
 *
 * Shared settings for all API routes. Mock routes use the
 * streaming delay constants; the real drafting route uses
 * the Mistral configuration.
 */

/** Delay in ms between each SSE chunk when streaming. */
export const SSE_CHUNK_DELAY_MS = 10;

/** Delay in ms between each word when streaming a field value. */
export const FIELD_WORD_DELAY_MS = 20;

/** Delay in ms between completing one field and starting the next. */
export const FIELD_GAP_DELAY_MS = 80;

/** Mistral model ID. */
export const MISTRAL_MODEL =
  process.env.MISTRAL_MODEL ?? "mistral-large-latest";

/** Mistral API key (shared with DDEV env). */
export const MISTRAL_API_KEY =
  process.env.DRUPAL_MISTRAL_API_KEY ?? "";
