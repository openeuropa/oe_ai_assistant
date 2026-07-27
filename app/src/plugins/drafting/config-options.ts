/**
 * Reads a host-provided options array from the drafting plugin config.
 *
 * Config options (tones, templates, documents) are defined by the OpenAPI
 * contract and emitted by the host, so items are trusted by shape rather than
 * validated field by field. A missing or malformed (non-array) value yields
 * an empty list.
 */
export function readConfigOptions<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}
