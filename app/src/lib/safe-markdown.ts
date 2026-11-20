import type { Components } from "streamdown";

/**
 * Streamdown overrides shared by every markdown render of model output.
 *
 * A markdown image makes the browser fetch its URL on render, which would let
 * a prompt-injected document leak data in the query string. Images render as
 * nothing.
 */
export const safeMarkdownComponents: Components = {
  img: () => null,
};

/** Element names Streamdown must never render from model output. */
export const safeMarkdownDisallowedElements: readonly string[] = ["img"];
