# Project Wiki

LLM-maintained knowledge base for the OpenEuropa AI
Editorial Assistant project. Team members feed raw
sources into `raw/`; the LLM consolidates them into
cross-referenced pages in `pages/`.

## Directory Layout

- `raw/` -- gitignored scratch area. Drop sources here
  (articles, notes, transcripts, investigation dumps).
  Each team member has their own local copy.
- `pages/` -- committed, LLM-maintained wiki pages.
- `index.md` -- navigational catalog of all pages.
- `log.md` -- chronological record of wiki operations.

## Page Format

Every page in `pages/` starts with YAML frontmatter:

    ---
    title: "Page Title"
    type: entity
    tags: [tag-a, tag-b]
    sources: [source-filename-without-extension]
    created: YYYY-MM-DD
    updated: YYYY-MM-DD
    ---

### Frontmatter Fields

| Field     | Required | Purpose                          |
|-----------|----------|----------------------------------|
| `title`   | yes      | Human-readable page title        |
| `type`    | yes      | Page category (see below)        |
| `tags`    | yes      | Freeform tags for filtering      |
| `sources` | no       | Filenames (no .md) this derives  |
|           |          | from -- wiki pages or raw files  |
| `created` | yes      | Date page was first created      |
| `updated` | yes      | Date page was last modified      |

## Page Types

| Type            | Purpose                              |
|-----------------|--------------------------------------|
| `entity`        | A specific thing: module, service,   |
|                 | protocol, tool, library              |
| `concept`       | An idea, pattern, or principle       |
| `source`        | Summary of an ingested raw source    |
| `investigation` | Debugging session, research finding  |
| `comparison`    | Side-by-side evaluation of options   |

## Writing Style

**Default: short and concise.** Pages are notes and
pointers -- scannable, easy to review. Use bullet
points over prose. Link to sources and other pages
rather than repeating content.

**Exception:** Architecture decisions and comparisons
can be longer-form when the rationale needs to be
captured in full.

Per page type:

- `entity`: brief description, key facts, links.
- `concept`: short explanation, cross-references.
- `source`: key takeaways as bullets, link to raw.
- `investigation`: findings and pointers, not journals.
- `comparison`: tables with trade-offs, fuller rationale
  where needed.

## Cross-References

Use standard markdown links between wiki pages:

    See [Plugin Architecture](plugin-architecture.md)
    for the overall design.

Always use relative links within `pages/`. When
referencing raw sources, use the filename without path:
"Source: onboarding-presentation".

## index.md Conventions

Each entry is one line with a link and a short hook:

    - [Page Title](pages/filename.md) -- one-line
      description

Organized by page type. Keep entries under 120
characters. Update the index on every ingest or page
creation.

## log.md Conventions

Append-only. Each entry starts with a date and
operation type:

    ## [YYYY-MM-DD] ingest | Source Title

    - Created: page-a.md, page-b.md
    - Updated: page-c.md
    - Key takeaway: one sentence summary

Operation types: `ingest`, `query`, `lint`.

## Diagrams

Use Mermaid syntax for architecture and flow diagrams
where they help explain relationships. Keep diagrams
small and focused.

## Comparison Tables

Use markdown tables for side-by-side evaluations:

    | Criterion | Option A | Option B |
    |-----------|----------|----------|
    | ...       | ...      | ...      |

Include a recommendation row at the bottom.

## Operations

Wiki operations are driven by slash commands:

- `/wiki:ingest` -- process a raw source into pages
- `/wiki:query` -- answer a question using the wiki
- `/wiki:lint` -- health-check and fix wiki issues

See the skill files in `.claude/skills/wiki/` for the
full workflow of each operation.
