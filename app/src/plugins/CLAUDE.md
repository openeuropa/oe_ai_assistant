# Plugin Development Guide

## Directory Structure

Each plugin lives under `src/plugins/<plugin-name>/`:

```
src/plugins/
  <plugin-name>/
    root.tsx            # root component (default export, lazy-loaded)
    store.ts            # optional Zustand store slice
    types.ts            # plugin-specific types
    components/         # React components
    hooks/              # custom hooks
    api/                # API helpers
    __tests__/          # tests
```

## Plugin Definition

Every plugin must be registered in `src/plugins/registry.ts` with a
`PluginDefinition` (defined in `src/plugins/types.ts`):

```typescript
{
  id: "my-plugin",               // unique key, also the store namespace
  name: "My Plugin",             // sidebar label
  description: "...",            // tooltip text
  icon: SomeIcon,                // Lucide-compatible icon component
  component: lazy(() => import("./my-plugin/root")),
  requiredEndpoints: ["/api/..."],
  path: "/my-plugin",            // hash route
  storeSlice: myPluginSliceConfig, // optional, see below
}
```

## Store Slices

Plugins can own a Zustand state slice mounted at `pluginStates[pluginId]`
in the global app store (`src/store/index.ts`).

### Creating a store slice

Create `store.ts` in your plugin directory. Follow this pattern:

```typescript
import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";

const PLUGIN_ID = "my-plugin";

// 1. Define the state interface
export interface MyPluginSliceState {
  count: number;
  label: string;
}

// 2. Define the slice config with initial state and partialize
export const myPluginSliceConfig: PluginSliceConfig<MyPluginSliceState> = {
  initialState: {
    count: 0,
    label: "",
  },
  // Return {} to persist nothing (transient state).
  // Omit partialize entirely to persist the whole slice.
  // Return a subset to persist only specific keys.
  partialize: (state) => ({ label: state.label }),
};

// 3. Export a typed read hook (for React components)
export function useMyPluginSlice(): MyPluginSliceState {
  const state = usePluginSlice<MyPluginSliceState>(PLUGIN_ID);
  return state ?? myPluginSliceConfig.initialState;
}

// 4. Export a typed getter (for async callbacks, outside React)
export function getMyPluginState(): MyPluginSliceState {
  return (
    getPluginState<MyPluginSliceState>(PLUGIN_ID)
    ?? myPluginSliceConfig.initialState
  );
}

// 5. Export a typed setter
export function setMyPluginState(partial: Partial<MyPluginSliceState>): void {
  setPluginState(PLUGIN_ID, partial as Record<string, unknown>);
}
```

### Wiring the slice to the registry

Import the config in `src/plugins/registry.ts` and add it to the plugin
definition:

```typescript
import { myPluginSliceConfig } from "./my-plugin/store";

// In the PluginDefinition:
storeSlice: myPluginSliceConfig,
```

The shell calls `initializePluginSlices(plugins)` in `src/main.tsx` before
the first render. This merges each plugin's `initialState` with any values
already persisted in localStorage.

### Key files in the store infrastructure

| File | Purpose |
|---|---|
| `src/store/index.ts` | Global Zustand store with `pluginStates` map |
| `src/store/plugin-slice-config.ts` | `PluginSliceConfig<T>` type definition |
| `src/store/plugin-store.ts` | `initializePluginSlices()`, `usePluginSlice`, `getPluginState`, `setPluginState` helpers |

### Partialize rules

- `partialize: () => ({})` : nothing persisted (use for transient/stream data)
- `partialize: (s) => ({ foo: s.foo })` : only `foo` is persisted
- omit `partialize` entirely : the whole slice is persisted

### Reading another plugin's state

Plugins may read other plugins' slices (read-only) via:

```typescript
import { usePluginSlice } from "@/store/plugin-store";
import type { EchoSliceState } from "@/plugins/echo/store";

const echoState = usePluginSlice<EchoSliceState>("echo");
```

Do NOT write to another plugin's slice.

## State Management Rules

- **Client UI state** (view mode, sidebar state, form step) : plugin store slice
- **Server data** (fetched resources, mutations) : TanStack Query hooks
- **Transient side-effect handles** (stream readers, timers) : React refs inside hooks
- **Cross-plugin notifications** : event bus (`src/lib/events.ts`, uses `mitt`)

## Reference Implementations

- `src/plugins/echo/` : store slice with transient SSE stream state, async
  callbacks using `getEchoState()`/`setEchoState()` outside React
- `src/plugins/notes/` : store slice for view navigation, TanStack Query for
  server data, `useNote()` with `enabled` option for conditional fetching
