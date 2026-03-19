/**
 * Plugin slice configuration type.
 *
 * Extracted into its own module to avoid circular imports between
 * the plugin types (which reference this config) and the plugin
 * store infrastructure (which references plugin types).
 */

/**
 * Non-generic base interface used by PluginDefinition.storeSlice.
 * Accepts any object as initial state without requiring an index signature.
 * Plugin authors should use the generic PluginSliceConfig<T> for type safety.
 */
export interface PluginSliceConfigBase {
  /** Initial state values for this plugin's slice. */
  initialState: object;
  /**
   * Selects which parts of the state to persist across page reloads.
   * If omitted, the entire slice is persisted. Return an empty object
   * to exclude the slice from persistence entirely.
   */
  partialize?: (state: never) => object;
}

/**
 * Typed configuration for a plugin's store slice.
 * Use this when defining a plugin's slice config for full type safety.
 *
 * @template T - Shape of the plugin's persisted and transient state.
 */
export interface PluginSliceConfig<T extends object>
  extends PluginSliceConfigBase {
  /** Initial state values for this plugin's slice. */
  initialState: T;
  /**
   * Selects which parts of the state to persist across page reloads.
   * If omitted, the entire slice is persisted. Return an empty object
   * to exclude the slice from persistence entirely.
   */
  partialize?: (state: T) => Partial<T>;
}
