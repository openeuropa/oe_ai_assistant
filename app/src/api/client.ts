/**
 * Typed API client.
 *
 * Built from the OpenAPI spec using openapi-fetch (thin typed fetch wrapper)
 * and openapi-react-query (auto-generated TanStack Query hooks). All path
 * parameters, request bodies, and responses are inferred from the spec --
 * no hand-written hooks needed.
 *
 * Usage in components:
 *
 *   const { data } = $api.useQuery("get", "/drafts/{nodeId}", {
 *     params: { path: { nodeId: "123" } },
 *   });
 *
 *   const mutation = $api.useMutation("post", "/drafts/{nodeId}");
 *   mutation.mutate({ params: { path: { nodeId: "123" } }, body: { ... } });
 *
 * The fetchClient is also exported for non-React contexts (e.g. SSE
 * streaming helpers that need raw fetch access).
 */

import createFetchClient from "openapi-fetch";
import createClient from "openapi-react-query";
import type { paths } from "./schema";

/**
 * Low-level fetch client typed against the OpenAPI spec.
 */
export const fetchClient = createFetchClient<paths>({
  baseUrl: "",
});

/**
 * TanStack Query hooks auto-generated from the spec.
 * Provides useQuery, useMutation, useSuspenseQuery, etc.
 * Query keys are generated automatically from the path and params,
 * so cache invalidation is type-safe.
 */
export const $api = createClient(fetchClient);
