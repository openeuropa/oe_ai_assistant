/**
 * CSRF token handling for cookie-authenticated API requests.
 *
 * The CMS accepts plugin requests only when they carry the session's CSRF
 * token in the X-CSRF-Token header. The token is fetched once from the URL
 * the host page announces at bootstrap and reused for the app's lifetime.
 * Every API call goes through apiFetch() or getCsrfHeaders() so the header
 * is never forgotten.
 */

import { getConfig } from "@/config";

/** Header name the CMS checks the token in. */
export const CSRF_HEADER = "X-CSRF-Token";

/** In-flight or resolved token request, shared by all callers. */
let tokenRequest: Promise<string> | null = null;

/**
 * Returns the CSRF token, fetching it on first use.
 *
 * A failed fetch clears the cache so the next call retries instead of
 * reusing a rejected promise.
 */
export async function getCsrfToken(): Promise<string> {
  if (!tokenRequest) {
    tokenRequest = fetch(getConfig().csrfTokenUrl, {
      credentials: "include",
    })
      .then(async (response) => {
        if (!response.ok) {
          throw new Error(`CSRF token error: ${response.status}`);
        }
        return (await response.text()).trim();
      })
      .catch((error: unknown) => {
        // Covers transport failures too, not only error responses, so a
        // rejected promise is never cached.
        tokenRequest = null;
        throw error;
      });
  }
  return tokenRequest;
}

/** Forgets the cached token. Used by tests and after a session change. */
export function resetCsrfToken(): void {
  tokenRequest = null;
}

/** Returns the headers that carry the CSRF token. */
export async function getCsrfHeaders(): Promise<Record<string, string>> {
  return { [CSRF_HEADER]: await getCsrfToken() };
}

/** Request options accepted by apiFetch(): plain-object headers only. */
export type ApiRequestInit = Omit<RequestInit, "headers"> & {
  headers?: Record<string, string>;
};

/**
 * fetch() for API calls: sends the session cookie and the CSRF token.
 *
 * Caller headers are kept as a plain object with the token added, so
 * request shapes stay easy to assert in tests.
 */
export async function apiFetch(
  url: string,
  init: ApiRequestInit = {},
): Promise<Response> {
  return fetch(url, {
    credentials: "include",
    ...init,
    headers: { ...init.headers, ...(await getCsrfHeaders()) },
  });
}
