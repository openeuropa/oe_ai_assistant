---
name: wiki-lint
description: Use when health-checking the project wiki -- to find contradictions, stale claims, orphan pages, missing cross-references, or gaps in coverage
---

# Wiki Lint

Health-check the project wiki at `docs/wiki/` and fix
issues.

## Prerequisites

Read `docs/wiki/CLAUDE.md` for page format and
conventions.

## Workflow

### 1. Read all pages

Read `docs/wiki/index.md`, then read every page in
`docs/wiki/pages/`.

### 2. Check for issues

Scan for each of these problems:

**Contradictions:** Do any pages make claims that
conflict with other pages? Flag with the specific
pages and conflicting statements.

**Stale claims:** Are there claims that newer sources
have superseded? Check `updated` dates and `sources`
fields.

**Orphan pages:** Are there pages with no inbound
links from other pages or the index? Every page should
be reachable.

**Missing cross-references:** Are there pages that
mention concepts or entities that have their own page
but don't link to them?

**Missing pages:** Are there important concepts or
entities mentioned across multiple pages that don't
have their own page yet?

**Data gaps:** Are there topics where a web search or
additional source could fill in missing information?

**Frontmatter issues:** Missing required fields,
outdated `updated` dates, empty `tags`.

### 3. Fix what you can

- Add missing cross-references
- Fix frontmatter issues
- Create pages for important missing concepts
- Update the index for any changes
- Flag contradictions and stale claims for human review

### 4. Report

Report to the user:

- Issues found (grouped by type)
- Issues fixed automatically
- Issues needing human input
- Suggestions for new sources to investigate

### 5. Update log

Append an entry to `docs/wiki/log.md`:

    ## [YYYY-MM-DD] lint

    - Pages scanned: N
    - Issues found: N (N fixed, N need review)
    - Pages created: list
    - Pages updated: list
