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

import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import yaml from "js-yaml";

const apiDir = resolve(import.meta.dirname, "../api");
const outFile = resolve(import.meta.dirname, "../../dist/schemas.json");

function findSchemaFiles(dir) {
  const entries = readdirSync(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const fullPath = join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...findSchemaFiles(fullPath));
    } else if (entry.isFile() && entry.name === "schemas.yaml") {
      files.push(relative(apiDir, fullPath));
    }
  }

  return files;
}

// Find all schemas.yaml files under api/.
const files = findSchemaFiles(apiDir);
const consolidated = {};

for (const file of files) {
  const fullPath = resolve(apiDir, file);
  const doc = yaml.load(readFileSync(fullPath, "utf8"));

  if (doc && typeof doc === "object") {
    Object.assign(consolidated, doc);
  }
}

writeFileSync(outFile, JSON.stringify(consolidated, null, 2));

const count = Object.keys(consolidated).length;
console.log(`schemas: ${count} definitions written to dist/schemas.json`);
