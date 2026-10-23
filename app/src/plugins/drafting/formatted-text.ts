/**
 * Formatted text detection for drafted field items.
 *
 * Drafted items carry no text format: the field's configuration decides it
 * on the Drupal side. Markup is what tells formatted text apart from plain
 * text.
 */

/** Matches an opening or closing HTML tag. */
const HTML_TAG = /<\/?[a-z][^>]*>/i;

/**
 * Checks whether a field item holds formatted text, that is a string
 * value containing an HTML tag.
 */
export function isFormattedItem(item: unknown): item is { value: string } {
  return (
    !!item &&
    typeof item === "object" &&
    "value" in item &&
    typeof item.value === "string" &&
    HTML_TAG.test(item.value)
  );
}
