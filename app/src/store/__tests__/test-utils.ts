import { vi } from "vitest";

class MemoryStorage implements Storage {
  private store = new Map<string, string>();

  get length(): number {
    return this.store.size;
  }

  clear(): void {
    this.store.clear();
  }

  getItem(key: string): string | null {
    return this.store.get(key) ?? null;
  }

  key(index: number): string | null {
    return Array.from(this.store.keys())[index] ?? null;
  }

  removeItem(key: string): void {
    this.store.delete(key);
  }

  setItem(key: string, value: string): void {
    this.store.set(key, value);
  }
}

export function installMemoryStorage(
  seed: Record<string, string> = {},
): Storage {
  const storage = new MemoryStorage();

  for (const [key, value] of Object.entries(seed)) {
    storage.setItem(key, value);
  }

  vi.stubGlobal("localStorage", storage);
  vi.stubGlobal("window", { localStorage: storage });

  return storage;
}

export function persistedStoreState(state: Record<string, unknown>): string {
  return JSON.stringify({ state, version: 0 });
}

export async function loadFreshStore(seed: Record<string, string> = {}) {
  vi.resetModules();
  const storage = installMemoryStorage(seed);
  const store = await import("../index");
  const pluginStore = await import("../plugin-store");

  return { storage, ...store, ...pluginStore };
}
