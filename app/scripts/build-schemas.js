/**
 * Consolidate OpenAPI schemas into a single JSON file for Drupal.
 *
 * Dereferences the root OpenAPI spec (api/openapi.yaml) and writes its
 * components/schemas section as a flat map to dist/schemas.json. The
 * Drupal module's RequestValidator reads this file to validate incoming
 * request bodies against the spec, so every schema must be fully
 * self-contained: the PHP validator cannot resolve file-based $refs.
 *
 * Run standalone:  node scripts/build-schemas.js
 * Run via build:   npm run build (chained after vite build)
 */

import { writeFileSync } from "node:fs";
import { resolve } from "node:path";
import $RefParser from "@apidevtools/json-schema-ref-parser";

const specFile = resolve(import.meta.dirname, "../api/openapi.yaml");
const outFile = resolve(import.meta.dirname, "../../dist/schemas.json");

// Resolve every $ref inline. Circular references cannot be represented
// in the flat JSON output, so fail the build instead of allowing them.
const spec = await $RefParser.dereference(specFile, {
  dereference: { circular: false },
});

const schemas = spec.components?.schemas ?? {};

writeFileSync(outFile, JSON.stringify(schemas, null, 2));

const count = Object.keys(schemas).length;
console.log(`schemas: ${count} definitions written to dist/schemas.json`);
