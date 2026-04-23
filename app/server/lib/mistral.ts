/**
 * Mistral client factory.
 *
 * Creates a configured Mistral SDK client using the API key
 * from the environment. The client is created once and shared
 * across all requests.
 */

import { Mistral } from "@mistralai/mistralai";
import { MISTRAL_API_KEY } from "../config";

/**
 * Creates a Mistral client instance. Throws if the API key
 * is not configured.
 */
export function createMistralClient(): Mistral {
  if (!MISTRAL_API_KEY) {
    throw new Error(
      "DRUPAL_MISTRAL_API_KEY is not set. " +
        "Standalone mock mode does not need credentials; " +
        'for provider-backed development, copy "app/.env.dist" ' +
        'to "app/.env" and run "npm run dev:integration".',
    );
  }
  return new Mistral({ apiKey: MISTRAL_API_KEY });
}
