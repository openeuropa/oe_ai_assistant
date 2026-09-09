/**
 * Live preview URL templating.
 *
 * The host config provides the preview URL as a template containing
 * {sessionId} and {versionId} placeholders. This helper resolves the
 * template into the concrete URL loaded by the preview iframe.
 */

/**
 * Builds the preview iframe URL from the configured template by
 * substituting the {sessionId} and {versionId} placeholders with the
 * URL-encoded values.
 */
export function buildPreviewUrl(
  template: string,
  sessionId: string,
  versionId: number,
): string {
  return template
    .replace("{sessionId}", encodeURIComponent(sessionId))
    .replace("{versionId}", encodeURIComponent(String(versionId)));
}
