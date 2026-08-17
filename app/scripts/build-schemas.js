/**
 * Consolidate OpenAPI schemas into a single JSON file for Drupal.
 *
 * Reads all schemas.yaml files under api/, extracts every named schema
 * definition, and writes a flat map to dist/schemas.json. The Drupal
 * module's RequestValidator reads this file to validate incoming
 * request bodies against the spec.
 *
 * Run standalone:  node scripts/build-schemas.js
 * Run via build:   npm run build (chained after vite build)
 */

import { globSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import yaml from "js-yaml";

const apiDir = resolve(import.meta.dirname, "../api");
const outFile = resolve(import.meta.dirname, "../../dist/schemas.json");

// Find all schemas.yaml files under api/.
const files = globSync("**/schemas.yaml", { cwd: apiDir });
const consolidated = {};
const componentRefPattern =
  /^(?:\.\.\/)*openapi\.yaml#\/components\/schemas\/([^/]+)$/;
const localRefPattern = /^#\/([^/]+)$/;

for (const file of files) {
  const fullPath = resolve(apiDir, file);
  const doc = yaml.load(readFileSync(fullPath, "utf8"));

  if (doc && typeof doc === "object") {
    Object.assign(consolidated, doc);
  }
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function dereference(value, trail = []) {
  if (Array.isArray(value)) {
    return value.map((item) => dereference(item, trail));
  }

  if (!value || typeof value !== "object") {
    return value;
  }

  if (typeof value.$ref === "string") {
    const schemaName =
      value.$ref.match(componentRefPattern)?.[1] ??
      value.$ref.match(localRefPattern)?.[1];
    if (schemaName) {
      if (!Object.hasOwn(consolidated, schemaName)) {
        throw new Error(`Unknown OpenAPI schema reference: ${value.$ref}`);
      }
      if (trail.includes(schemaName)) {
        throw new Error(
          `Circular OpenAPI schema reference: ${[...trail, schemaName].join(" -> ")}`,
        );
      }

      const { $ref, ...overrides } = value;
      return {
        ...dereference(clone(consolidated[schemaName]), [...trail, schemaName]),
        ...dereference(overrides, trail),
      };
    }
  }

  return Object.fromEntries(
    Object.entries(value).map(([key, item]) => [key, dereference(item, trail)]),
  );
}

const dereferenced = Object.fromEntries(
  Object.entries(consolidated).map(([name, schema]) => [
    name,
    dereference(schema, [name]),
  ]),
);

writeFileSync(outFile, JSON.stringify(dereferenced, null, 2));

const count = Object.keys(consolidated).length;
console.log(`schemas: ${count} definitions written to dist/schemas.json`);
