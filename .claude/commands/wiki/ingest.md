---
name: wiki-ingest
description: Use when processing a raw source into the project wiki -- after dropping a file into docs/wiki/raw/ or when asked to ingest a document into the wiki
---

# Wiki Ingest

Process a raw source into the project wiki at
`docs/wiki/`.

## Prerequisites

Read `docs/wiki/CLAUDE.md` for page format, frontmatter
fields, page types, and writing style conventions.

## Workflow

### 1. Identify the source

Ask the user which file in `docs/wiki/raw/` to process,
or accept a file path as an argument. Read the source
file.

### 2. Discuss key takeaways

Summarize the key points from the source. Ask the user
if there is anything to emphasize or skip before
writing pages.

### 3. Create source summary page

Create a `source` type page in `docs/wiki/pages/` with:

- YAML frontmatter (title, type: source, tags, sources,
  created, updated)
- Key takeaways as bullet points
- Reference to the raw source filename

### 4. Update or create related pages

For each significant entity, concept, or finding in
the source:

- If a wiki page already exists: update it with new
  information, update the `updated` date and `sources`
  field
- If no page exists: create one with appropriate type
  (entity, concept, investigation, or comparison)

Use cross-references (markdown links) between pages.

### 5. Update index

Read `docs/wiki/index.md`. Add entries for every new
page. Update descriptions for modified pages. Keep
entries organized by page type.

### 6. Update log

Append an entry to `docs/wiki/log.md`:

    ## [YYYY-MM-DD] ingest | Source Title

    - Created: page-a.md, page-b.md
    - Updated: page-c.md
    - Key takeaway: one sentence summary

### 7. Summary

Report to the user:

- Pages created (with links)
- Pages updated (with what changed)
- Total page count in the wiki
