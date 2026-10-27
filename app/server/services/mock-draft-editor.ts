/** In-memory counterpart of Drupal's draft edits for standalone development. */
interface SchemaNode {
  type?: string;
  properties?: Record<string, SchemaNode>;
  items?: SchemaNode;
  oneOf?: SchemaNode[];
  const?: unknown;
  enum?: unknown[];
  maxLength?: number;
  readOnly?: boolean;
}

interface StoredDraft {
  fields: Record<string, unknown>;
  revision: number;
  schema: SchemaNode;
}

export class MockDraftEditor {
  private drafts = new Map<string, Map<number, StoredDraft>>();
  private transcript = new Map<string, Record<string, unknown>[]>();
  private snapshots = new Map<string, Map<number, Record<string, unknown>>>();
  private approvals = new Map<
    string,
    { id: string; version: number; call: Record<string, unknown> }
  >();

  record(
    sessionId: string,
    result: Record<string, unknown>,
    schema: SchemaNode,
  ) {
    result = (result.draft as Record<string, unknown> | undefined) ?? result;
    if (typeof result.version !== "number" || !result.fields) return;
    const versions = this.drafts.get(sessionId) ?? new Map();
    const fields = structuredClone(result.fields) as Record<string, unknown>;
    versions.set(result.version, { fields, revision: 0, schema });
    this.drafts.set(sessionId, versions);
    const snapshot = { ...result, fields };
    const snapshots = this.snapshots.get(sessionId) ?? new Map();
    snapshots.set(result.version, snapshot);
    this.snapshots.set(sessionId, snapshots);
    const messages = this.transcript.get(sessionId) ?? [];
    messages.push({
      role: "assistant",
      content: "",
      toolCalls: [
        {
          id: `draft-${result.version}`,
          type: "function",
          function: { name: "draft_group", arguments: "{}" },
          result: { group: "main_fields", draft: snapshot },
        },
      ],
    });
    this.transcript.set(sessionId, messages);
  }

  get(sessionId: string, version: number) {
    return this.drafts.get(sessionId)?.get(version);
  }

  messages(sessionId: string) {
    return this.transcript.get(sessionId) ?? [];
  }

  requestSave(sessionId: string, version: number, id: string) {
    const call = {
      id,
      type: "function",
      function: { name: "save_draft", arguments: JSON.stringify({ version }) },
    };
    const messages = this.transcript.get(sessionId) ?? [];
    messages.push({ role: "assistant", content: "", toolCalls: [call] });
    this.transcript.set(sessionId, messages);
    this.approvals.set(sessionId, { id, version, call });
  }

  pending(sessionId: string) {
    return this.approvals.get(sessionId);
  }

  finishSave(sessionId: string, result: Record<string, unknown>) {
    const pending = this.approvals.get(sessionId);
    if (pending) pending.call.result = result;
    this.approvals.delete(sessionId);
  }

  reset(sessionId: string) {
    this.drafts.delete(sessionId);
    this.transcript.delete(sessionId);
    this.snapshots.delete(sessionId);
    this.approvals.delete(sessionId);
  }

  edit(
    sessionId: string,
    version: number,
    field: string,
    path: (string | number)[],
    value: unknown,
    revision: number,
  ) {
    const draft = this.get(sessionId, version);
    if (!draft) throw new Error("The draft was not found in this session.");
    if (draft.revision !== revision)
      throw new Error(
        "This draft has changed. Reload the draft before applying your edit.",
      );
    let schema = draft.schema.properties?.[field];
    if (!schema || !Object.hasOwn(draft.fields, field) || path.length === 0)
      throw new Error("This field is not editable.");
    const next = structuredClone(draft.fields);
    let current: unknown = next[field];
    let parent = next;
    let key: string | number = field;
    for (const segment of path) {
      if (schema.readOnly || schema.const !== undefined)
        throw new Error("This value is read-only.");
      if (schema.oneOf) {
        const record = current as Record<string, { target_id: string }[]>;
        schema = schema.oneOf.find((variant) =>
          Object.entries(variant.properties ?? {}).some(
            ([name, property]) =>
              property.items?.properties?.target_id?.const !== undefined &&
              property.items.properties.target_id.const ===
                record[name]?.[0]?.target_id,
          ),
        );
      }
      if (!schema) throw new Error("This inline entity cannot be edited.");
      parent = current as Record<string, unknown>;
      key = segment;
      if (schema.type === "array") {
        if (
          !Array.isArray(current) ||
          typeof segment !== "number" ||
          segment < 0 ||
          segment > current.length ||
          (segment === current.length && current.length > 0)
        )
          throw new Error("The field item does not exist.");
        schema = schema.items;
      } else {
        if (
          [
            "type",
            "status",
            "format",
            "parent_id",
            "parent_type",
            "parent_field_name",
            "behavior_settings",
          ].includes(String(segment))
        )
          throw new Error("This value is read-only.");
        schema = schema.properties?.[segment];
      }
      if (!schema || parent === null || typeof parent !== "object")
        throw new Error("This value cannot be edited.");
      current = parent[String(segment)];
      if (
        current === undefined &&
        (schema.type === "object" || schema.type === "array")
      ) {
        current = schema.type === "array" ? [] : {};
        parent[String(segment)] = current;
      }
    }
    if (schema.oneOf) {
      schema = {
        ...schema,
        ...schema.oneOf.find((variant) =>
          ["string", "integer", "number", "boolean"].includes(
            variant.type ?? "",
          ),
        ),
      };
    }
    if (schema.readOnly || schema.const !== undefined)
      throw new Error("This value is read-only.");
    const expectedType = schema.type === "integer" ? "number" : schema.type;
    if (
      (value !== null && typeof value !== expectedType) ||
      (value !== null &&
        schema.type === "integer" &&
        !Number.isInteger(value)) ||
      (value !== null && schema.enum && !schema.enum.includes(value)) ||
      (typeof value === "string" &&
        schema.maxLength !== undefined &&
        value.length > schema.maxLength)
    )
      throw new Error("Enter a valid field value.");
    if (current === value) return draft;
    parent[String(key)] = value;
    // Keep the field map shared with the transcript's tool result.
    draft.fields[field] = next[field];
    draft.revision += 1;
    const snapshot = this.snapshots.get(sessionId)?.get(version);
    if (snapshot) snapshot.editRevision = draft.revision;
    return draft;
  }
}
