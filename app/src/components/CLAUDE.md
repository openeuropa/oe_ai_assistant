# Shared Components Directory

## Purpose

Reusable UI primitives shared across plugins. Built on shadcn/ui for
accessible, themeable components with consistent styling.

## Architecture

- `ui/` subdirectory: shadcn/ui primitives (Button, Input, Textarea,
  Label). These are copied into the project by the `npx shadcn` CLI and
  can be customised freely. They use CSS variable theming and the `cn()`
  utility from `src/lib/utils.ts`.
Plugins import shadcn components directly from `@/components/ui/button`
etc. No wrapper layer.

## Key Files

| File | Purpose |
|---|---|
| `ui/button.tsx` | shadcn Button with variant system (default, outline, secondary, ghost, destructive, link). |
| `ui/input.tsx` | shadcn Input primitive. |
| `ui/textarea.tsx` | shadcn Textarea primitive. |
| `ui/label.tsx` | shadcn Label (Radix-based) primitive. |
| `ui/field.tsx` | shadcn Field layout: Field, FieldLabel, FieldError, FieldDescription, FieldGroup, etc. |
| `ui/separator.tsx` | shadcn Separator primitive (dependency of Field). |

## Rules

- Components here must be **generic and stateless**. No business logic,
  no API calls, no plugin-specific knowledge.
- To add a new shadcn component, run `npx shadcn@latest add <name>`.
  It lands in `ui/` automatically.
- Only promote a component from a plugin's `components/` directory to
  here when a second plugin needs it. Do not create speculative shared
  components.
- Styling uses shadcn's CSS variable theming (`--primary`, `--border`,
  etc.) defined in `src/index.css`.
