---
name: wiki-query
description: Use when answering a question using the project wiki -- when the user asks about architecture, decisions, entities, or prior investigations tracked in the wiki
---

# Wiki Query

Answer a question by searching the project wiki at
`docs/wiki/`.

## Prerequisites

Read `docs/wiki/CLAUDE.md` for page format and
conventions.

## Workflow

### 1. Read the index

Read `docs/wiki/index.md` to find pages relevant to
the question.

### 2. Read relevant pages

Read the wiki pages identified from the index. Follow
cross-references to gather full context.

### 3. Synthesize answer

Answer the question with citations to wiki pages.
Format citations as markdown links:

    The project uses SSE streaming
    ([sse-streaming.md](docs/wiki/pages/sse-streaming.md))
    with the Vercel AI SDK UI Message Stream protocol
    ([ui-message-stream.md](docs/wiki/pages/ui-message-stream.md)).

### 4. File valuable answers (optional)

If the answer represents a reusable synthesis -- a new
comparison, a connection between concepts, or a
non-obvious finding -- offer to file it as a new wiki
page (typically `investigation` or `comparison` type).

If the user agrees:

- Create the page in `docs/wiki/pages/`
- Update `docs/wiki/index.md`
- Append an entry to `docs/wiki/log.md`:

      ## [YYYY-MM-DD] query | Question Summary

      - Filed as: new-page.md
      - Key insight: one sentence
