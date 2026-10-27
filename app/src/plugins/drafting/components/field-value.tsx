import DOMPurify from "dompurify";
import { Pencil } from "lucide-react";
import { useEffect, useId, useState } from "react";
import { useAppStore } from "@/store";
import {
  emptyFieldItem,
  fieldLabel,
  fieldVariant,
  HIDDEN_FIELDS,
} from "../field-schema";
import { setDraftingState, useDraftingSlice } from "../store";
import type { DraftFieldSchema } from "../types";
import { RichTextInput } from "./rich-text-input";

type Scalar = string | number | boolean | null;
type ValuePath = (string | number)[];

interface FieldValueProps {
  value: unknown;
  schema: DraftFieldSchema;
  label: string;
  path: ValuePath;
  readOnly?: boolean;
  onApply: (path: ValuePath, value: Scalar) => Promise<void>;
  onReload?: () => Promise<unknown>;
}

/** Render every item and nested content field, preserving its original path. */
export function FieldValue({
  value,
  schema: source,
  label,
  path,
  readOnly = false,
  onApply,
  onReload,
}: FieldValueProps) {
  const schema = fieldVariant(source, value);
  const locked =
    readOnly ||
    source.readOnly ||
    schema.readOnly ||
    schema.const !== undefined;
  if (schema.const !== undefined) return null;
  if (schema.type === "array" && schema.items) {
    const items = Array.isArray(value) ? value : [];
    const placeholder = emptyFieldItem(schema.items);
    const displayed =
      items.length === 0 && placeholder !== undefined ? [placeholder] : items;
    return (
      <div className="space-y-3">
        {displayed.map((item, index) => (
          <div
            key={String(index)}
            className={
              displayed.length > 1 ? "rounded border border-gray-200 p-3" : ""
            }
          >
            {displayed.length > 1 && (
              <p className="mb-2 text-xs text-gray-500">Item {index + 1}</p>
            )}
            <FieldValue
              value={item}
              schema={schema.items as DraftFieldSchema}
              label={items.length > 1 ? `${label}, item ${index + 1}` : label}
              path={[...path, index]}
              readOnly={locked}
              onApply={onApply}
              onReload={onReload}
            />
          </div>
        ))}
        {displayed.length === 0 && (
          <span className="text-sm text-gray-400">Empty</span>
        )}
      </div>
    );
  }
  if (schema.type === "object" && schema.properties) {
    const record =
      value && typeof value === "object"
        ? (value as Record<string, unknown>)
        : {};
    const entries = Object.entries(schema.properties).filter(
      ([name, child]) =>
        !HIDDEN_FIELDS.has(name) &&
        child.const === undefined &&
        (child.type !== "array" || Object.keys(record).includes(name)),
    );
    return (
      <div className="space-y-3">
        {entries.map(([name, child]) => {
          const childLabel = child.title ?? fieldLabel(name);
          const isValue =
            name === "value" || (name === "target_id" && entries.length === 1);
          return (
            <div key={name}>
              {!isValue && (
                <div className="mb-1 text-xs font-medium text-gray-500">
                  {childLabel}
                </div>
              )}
              <FieldValue
                value={record[name]}
                schema={child}
                label={isValue ? label : `${label}: ${childLabel}`}
                path={[...path, name]}
                readOnly={locked}
                onApply={onApply}
                onReload={onReload}
              />
            </div>
          );
        })}
      </div>
    );
  }
  if (!["string", "number", "integer", "boolean"].includes(schema.type ?? "")) {
    return (
      <span className="text-sm text-gray-500">
        {value == null ? "Empty" : "This value cannot be edited here."}
      </span>
    );
  }
  return (
    <InlineValue
      value={value}
      schema={schema}
      label={label}
      readOnly={locked}
      onApply={(next) => onApply(path, next)}
      onReload={onReload}
    />
  );
}

function InlineValue({
  value,
  schema,
  label,
  readOnly,
  onApply,
  onReload,
}: {
  value: unknown;
  schema: DraftFieldSchema;
  label: string;
  readOnly?: boolean;
  onApply: (next: Scalar) => Promise<void>;
  onReload?: () => Promise<unknown>;
}) {
  const { isEditingField } = useDraftingSlice();
  const isSaving = useAppStore((state) => state.pendingWork["drafting:save"]);
  const [editing, setEditing] = useState(false);
  const [buffer, setBuffer] = useState<Scalar>("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const id = useId();

  useEffect(() => {
    if (!editing) return;
    setDraftingState({ isEditingField: true });
    useAppStore.getState().setPendingWork("drafting:inline-edit", true);
    return () => {
      setDraftingState({ isEditingField: false });
      useAppStore.getState().setPendingWork("drafting:inline-edit", false);
    };
  }, [editing]);

  if (!editing) {
    const optionIndex =
      schema.enum?.findIndex((option) => String(option) === String(value)) ??
      -1;
    const display =
      optionIndex >= 0
        ? (schema["x-enum-labels"]?.[optionIndex] ?? String(value))
        : schema.type === "boolean" && typeof value === "boolean"
          ? value
            ? "Yes"
            : "No"
          : String(value ?? "");
    return (
      <div className="flex items-start gap-2">
        {schema["x-editor"] === "richtext" && display ? (
          <div
            className="min-w-0 flex-1 text-sm text-gray-700 [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5"
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(display) }}
          />
        ) : (
          <p className="min-w-0 flex-1 whitespace-pre-wrap break-words text-sm text-gray-700">
            {display || <span className="text-gray-400">Empty</span>}
          </p>
        )}
        {!readOnly && (
          <button
            type="button"
            aria-label={`Edit ${label}`}
            title={`Edit ${label}`}
            disabled={isEditingField || isSaving}
            onClick={() => {
              setBuffer(
                typeof value === "boolean" || typeof value === "number"
                  ? value
                  : String(value ?? ""),
              );
              if (
                schema.format === "date-time" &&
                typeof value === "string" &&
                value
              ) {
                const date = new Date(value);
                if (!Number.isNaN(date.getTime()))
                  setBuffer(date.toISOString().slice(0, 19));
              }
              setError("");
              setEditing(true);
            }}
            className="shrink-0 rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-blue-600 disabled:opacity-30"
          >
            <Pencil size={14} />
          </button>
        )}
      </div>
    );
  }

  const inputClass =
    "w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500";
  return (
    <form
      aria-label={`Edit ${label}`}
      onSubmit={async (event) => {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
          let next = buffer;
          if (schema.format === "date-time" && buffer !== "") {
            const date = new Date(`${buffer}Z`);
            if (Number.isNaN(date.getTime()))
              throw new Error("Enter a valid date and time.");
            next = date.toISOString().replace(/\.\d{3}Z$/, "Z");
          }
          if (
            (schema.format === "date" || schema.format === "date-time") &&
            buffer === ""
          )
            next = null;
          if (
            schema.enum &&
            !schema.enum.some((option) => String(option) === String(buffer))
          )
            next = null;
          if (schema.type === "integer" || schema.type === "number") {
            next = String(buffer).trim() === "" ? null : Number(buffer);
            if (
              next !== null &&
              (!Number.isFinite(next) ||
                (schema.type === "integer" && !Number.isInteger(next)))
            ) {
              throw new Error(
                schema.type === "integer"
                  ? "Enter a whole number."
                  : "Enter a number.",
              );
            }
          }
          await onApply(next);
          setEditing(false);
        } catch (failure) {
          setError(
            failure instanceof Error
              ? failure.message
              : "The value could not be updated.",
          );
        } finally {
          setBusy(false);
        }
      }}
      className="space-y-2"
    >
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <fieldset disabled={busy} className="min-w-0">
        {schema.enum ? (
          <select
            id={id}
            aria-label={label}
            className={inputClass}
            value={String(
              schema.enum.findIndex(
                (option) => String(option) === String(buffer),
              ),
            )}
            onChange={(event) =>
              setBuffer(schema.enum?.[Number(event.target.value)] ?? "")
            }
          >
            <option value="-1">None</option>
            {schema.enum.map((option, index) => (
              <option key={String(index)} value={String(index)}>
                {schema["x-enum-labels"]?.[index] ?? String(option)}
              </option>
            ))}
          </select>
        ) : schema.type === "boolean" ? (
          <label className="flex items-center gap-2 text-sm">
            <input
              id={id}
              aria-label={label}
              type="checkbox"
              checked={buffer === true}
              onChange={(event) => setBuffer(event.target.checked)}
            />
            {label}
          </label>
        ) : schema["x-editor"] === "richtext" ? (
          <RichTextInput
            value={String(buffer)}
            label={label}
            disabled={busy}
            onChange={setBuffer}
          />
        ) : schema["x-editor"] === "textarea" ? (
          <textarea
            id={id}
            aria-label={label}
            className={inputClass}
            rows={5}
            value={String(buffer)}
            maxLength={schema.maxLength}
            onChange={(event) => setBuffer(event.target.value)}
          />
        ) : (
          <input
            id={id}
            aria-label={label}
            className={inputClass}
            type={
              schema.type === "integer" || schema.type === "number"
                ? "number"
                : schema.format === "date"
                  ? "date"
                  : schema.format === "date-time"
                    ? "datetime-local"
                    : schema.format === "email"
                      ? "email"
                      : "text"
            }
            step={
              schema.type === "integer"
                ? 1
                : schema.type === "number" || schema.format === "date-time"
                  ? "any"
                  : undefined
            }
            min={schema.minimum}
            max={schema.maximum}
            maxLength={schema.maxLength}
            value={String(buffer)}
            onChange={(event) => setBuffer(event.target.value)}
          />
        )}
      </fieldset>
      {schema.format === "date-time" && (
        <p className="text-xs text-gray-500">Date and time in UTC.</p>
      )}
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
      {error.includes("has changed") && onReload && (
        <button
          type="button"
          disabled={busy}
          onClick={() => void onReload()}
          className="text-sm text-blue-700 underline"
        >
          Load latest draft (keeps your input)
        </button>
      )}
      <div className="flex gap-2">
        <button
          type="submit"
          disabled={busy}
          className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {busy ? "Applying…" : "Apply"}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => setEditing(false)}
          className="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
