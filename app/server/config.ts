/**
 * Dev server configuration.
 *
 * Shared settings for all API routes. Mock routes use the
 * streaming delay constants; the real drafting route uses
 * the Mistral configuration.
 */

export type DraftingMode = "mock" | "mistral";

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
export const MISTRAL_API_KEY = process.env.MISTRAL_API_KEY ?? "";

/** Parse "--drafting-mode=value" or "--drafting-mode value" from argv. */
function readDraftingModeArg(argv: string[]): string | undefined {
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (!arg) {
      continue;
    }

    if (arg.startsWith("--drafting-mode=")) {
      return arg.slice("--drafting-mode=".length);
    }

    if (arg === "--drafting-mode") {
      return argv[i + 1];
    }
  }

  return undefined;
}

/** Resolve the active drafting mode, defaulting to standalone mock mode. */
export function resolveDraftingMode(
  argv: string[] = process.argv,
  env: NodeJS.ProcessEnv = process.env,
): DraftingMode {
  const rawMode = (
    readDraftingModeArg(argv) ??
    env.DRAFTING_MODE ??
    "mock"
  ).toLowerCase();

  if (rawMode === "mock" || rawMode === "mistral") {
    return rawMode;
  }

  throw new Error(
    `Unsupported drafting mode "${rawMode}". Use "mock" or "mistral".`,
  );
}
