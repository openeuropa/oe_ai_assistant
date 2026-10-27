import type { DraftFieldSchema } from "./types";

export const HIDDEN_FIELDS = new Set([
  "format",
  "type",
  "status",
  "parent_id",
  "parent_type",
  "parent_field_name",
  "behavior_settings",
]);

export function fieldLabel(name: string): string {
  return name
    .replace(/^field_/, "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Resolve the schema for the existing bundle; never change discriminators. */
export function fieldVariant(
  schema: DraftFieldSchema,
  value: unknown,
): DraftFieldSchema {
  if (!schema.oneOf) return schema;
  const primitive = schema.oneOf.find((variant) =>
    ["string", "integer", "number", "boolean"].includes(variant.type ?? ""),
  );
  if (primitive) return { ...schema, ...primitive };
  const record =
    value && typeof value === "object"
      ? (value as Record<string, unknown>)
      : {};
  return (
    schema.oneOf.find((variant) =>
      Object.entries(variant.properties ?? {}).some(([key, property]) => {
        const bundle = property.items?.properties?.target_id?.const;
        const items = record[key];
        return (
          bundle !== undefined &&
          Array.isArray(items) &&
          items[0]?.target_id === bundle
        );
      }),
    ) ?? { readOnly: true }
  );
}

/** A missing optional primitive can be filled without inventing entity types. */
export function emptyFieldItem(schema: DraftFieldSchema): unknown {
  if (schema.type === "object" && !schema.oneOf) return {};
  return undefined;
}
