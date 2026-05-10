import axios, { type AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios'

import { ApiError } from './errors'

/**
 * The HTTP transport surface every typed API consumer depends on.
 *
 * The interface is intentionally thin so consumers (auth API, future
 * campaigns API, future creators API, etc.) can be unit-tested with a
 * `vi.fn()`-shaped fake without pulling axios into the test surface.
 *
 * Every method:
 *   - Sends `withCredentials: true` (Sanctum SPA cookie auth).
 *   - Resolves to the parsed JSON response body, typed as `T`.
 *   - Throws an {@link ApiError} on any non-2xx response or transport
 *     failure. Network failures land as `ApiError` with `status: 0`
 *     and `code: 'network.error'`.
 *
 * `post`, `patch`, and `delete` additionally:
 *   - Issue `GET csrfCookieUrl` first to ensure the CSRF cookie is
 *     fresh before the state-changing call. This is the documented
 *     Sanctum SPA flow (`docs/04-API-DESIGN.md §4`).
 *   - Read the `XSRF-TOKEN` cookie set by that preflight and forward
 *     it on the actual request as `X-XSRF-TOKEN`.
 */
export interface HttpClient {
  get<T = unknown>(path: string, options?: HttpRequestOptions): Promise<T>
  post<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T>
  patch<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T>
  delete<T = unknown>(path: string, body?: unknown, options?: HttpRequestOptions): Promise<T>
}

export interface HttpRequestOptions {
  /**
   * Optional headers merged onto the default `{ Accept, Content-Type }`
   * pair. The CSRF token header is added automatically — callers must
   * not set it manually.
   */
  headers?: Record<string, string>
}

export interface CreateHttpClientOptions {
  /**
   * The API root path or absolute URL prepended to every request path.
   * Examples: `'/api/v1'` (proxied by Vite in dev),
   * `'https://api.catalyst-engine.com/api/v1'` (production).
   */
  baseUrl: string
  /**
   * The CSRF cookie endpoint exposed by Laravel Sanctum. Defaults to
   * `'/sanctum/csrf-cookie'` (the Sanctum default, served by the API
   * host at the same origin). Override only if the deployment moves
   * the endpoint.
   */
  csrfCookieUrl?: string
  /**
   * The cookie name Sanctum uses for the XSRF token. Laravel default
   * is `'XSRF-TOKEN'`; only override when running against a custom
   * Sanctum config.
   */
  xsrfCookieName?: string
  /**
   * The header name the API expects to receive the XSRF token on.
   * Laravel default is `'X-XSRF-TOKEN'`.
   */
  xsrfHeaderName?: string
  /**
   * Used by tests to inject a pre-configured axios instance whose
   * adapter is mocked. Production code never sets this.
   */
  axiosInstance?: AxiosInstance
  /**
   * Optional policy hook fired exactly once per response that
   * normalizes to a `401 Unauthorized`, after {@link ApiError} is
   * built but before it is thrown. The wiring layer
   * (`apps/main/src/core/api/index.ts`) plugs in
   * "clear user + redirect to /sign-in" here; the api-client itself
   * stays transport-only and never knows about routing.
   *
   * The callback receives the request path verbatim (the `path`
   * argument the caller passed to `get()` / `post()` / etc., not the
   * absolutised URL) so the consumer can exempt specific endpoints.
   * Chunk 6.5 review priority #2 + #8 require exempting
   * `/auth/login` (legitimate wrong-password path) and `/me`,
   * `/admin/me` (cold-load 401 is normal).
   *
   * The thrown {@link ApiError} is unaffected — exceptions from the
   * callback itself are caught and silently swallowed so a misbehaving
   * policy hook can never replace the original 401 surface with a
   * different exception type the caller would not be prepared for.
   */
  onUnauthorized?: (path: string) => void
}

const DEFAULT_CSRF_COOKIE_URL = '/sanctum/csrf-cookie'
const DEFAULT_XSRF_COOKIE_NAME = 'XSRF-TOKEN'
const DEFAULT_XSRF_HEADER_NAME = 'X-XSRF-TOKEN'
const STATE_CHANGING_METHODS = new Set(['POST', 'PATCH', 'DELETE'])

export function createHttpClient(options: CreateHttpClientOptions): HttpClient {
  const csrfCookieUrl = options.csrfCookieUrl ?? DEFAULT_CSRF_COOKIE_URL
  const xsrfCookieName = options.xsrfCookieName ?? DEFAULT_XSRF_COOKIE_NAME
  const xsrfHeaderName = options.xsrfHeaderName ?? DEFAULT_XSRF_HEADER_NAME

  const instance: AxiosInstance =
    options.axiosInstance ??
    axios.create({
      withCredentials: true,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      // Disable axios' built-in xsrf handling: it only fires inside a
      // browser AND when the request URL matches the cookie origin
      // exactly. We do the read+attach ourselves below so the same
      // code path runs in JSDOM (Vitest) and the browser.
      xsrfCookieName: undefined,
      xsrfHeaderName: undefined,
    })

  // Belt-and-suspenders even when an axiosInstance is injected — guard
  // against a test instance defaulting `withCredentials: false`.
  instance.defaults.withCredentials = true

  const resolvedCsrfUrl = resolveCsrfUrl(csrfCookieUrl, options.baseUrl)

  async function request<T>(config: AxiosRequestConfig): Promise<T> {
    /* c8 ignore next 2 -- @preserve: every public method passes method+url; defaults are defensive */
    const method = (config.method ?? 'GET').toUpperCase()
    const path = config.url ?? ''

    try {
      if (STATE_CHANGING_METHODS.has(method)) {
        // CSRF preflight: GET /sanctum/csrf-cookie sets the XSRF-TOKEN
        // cookie. We don't keep the response — only the side-effect.
        await instance.request({
          method: 'GET',
          url: resolvedCsrfUrl,
        })
      }

      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(config.headers as Record<string, string> | undefined),
      }

      if (STATE_CHANGING_METHODS.has(method)) {
        const token = readCookie(xsrfCookieName)
        if (token !== null) {
          headers[xsrfHeaderName] = token
        }
      }

      const response = await instance.request<T>({
        ...config,
        url: absolutize(path, options.baseUrl),
        headers,
      })
      return response.data
    } catch (error) {
      const normalized = normalizeError(error)
      if (normalized.status === 401 && options.onUnauthorized !== undefined) {
        try {
          options.onUnauthorized(path)
        } catch {
          // Swallow callback failures: the original 401 remains the
          // surface the caller sees. A misbehaving policy hook must
          // never replace the typed ApiError with a different error.
        }
      }
      throw normalized
    }
  }

  return {
    get<T = unknown>(path: string, opts?: HttpRequestOptions): Promise<T> {
      return request<T>({
        method: 'GET',
        url: path,
        ...(opts?.headers ? { headers: opts.headers } : {}),
      })
    },
    post<T = unknown>(path: string, body?: unknown, opts?: HttpRequestOptions): Promise<T> {
      return request<T>({
        method: 'POST',
        url: path,
        data: body ?? {},
        ...(opts?.headers ? { headers: opts.headers } : {}),
      })
    },
    patch<T = unknown>(path: string, body?: unknown, opts?: HttpRequestOptions): Promise<T> {
      return request<T>({
        method: 'PATCH',
        url: path,
        data: body ?? {},
        ...(opts?.headers ? { headers: opts.headers } : {}),
      })
    },
    delete<T = unknown>(path: string, body?: unknown, opts?: HttpRequestOptions): Promise<T> {
      return request<T>({
        method: 'DELETE',
        url: path,
        ...(body !== undefined ? { data: body } : {}),
        ...(opts?.headers ? { headers: opts.headers } : {}),
      })
    },
  }
}

function normalizeError(error: unknown): ApiError {
  if (isAxiosError(error)) {
    if (error.response) {
      return ApiError.fromEnvelope(error.response.status, error.response.data)
    }
    return ApiError.fromNetworkError(error)
  }
  if (error instanceof ApiError) {
    return error
  }
  return ApiError.fromNetworkError(error)
}

function isAxiosError(error: unknown): error is AxiosError {
  return axios.isAxiosError(error)
}

/**
 * Resolve a regular API call's `path` against `baseUrl`:
 *
 *   - If `path` is already an absolute URL (`http(s)://…`) or starts
 *     with `//`, return it unchanged — the caller is targeting an
 *     external host directly.
 *   - Otherwise concatenate `baseUrl` + `path`, collapsing the
 *     boundary slashes. `baseUrl` may be `'/api/v1'`, `'/api/v1/'`,
 *     or absolute; `path` may be `'me'` or `'/me'`.
 *
 * The CSRF preflight is resolved separately via {@link resolveCsrfUrl}
 * because the Sanctum endpoint lives at the host root, not under the
 * versioned API tree.
 */
function absolutize(path: string, baseUrl: string): string {
  if (/^https?:\/\//i.test(path) || path.startsWith('//')) {
    return path
  }

  const trimmedBase = baseUrl.endsWith('/') ? baseUrl.slice(0, -1) : baseUrl
  const trimmedPath = path.startsWith('/') ? path : `/${path}`
  return `${trimmedBase}${trimmedPath}`
}

/**
 * Resolve the CSRF cookie URL once at construction time. The Sanctum
 * endpoint conventionally lives at the host root (e.g.
 * `https://api.example.com/sanctum/csrf-cookie`), not under the
 * versioned API path. This helper:
 *
 *   - Returns absolute URLs (`http(s)://…`, `//…`) unchanged.
 *   - When `baseUrl` is absolute, anchors `csrfCookieUrl` at that
 *     host's root, ignoring the API path prefix.
 *   - When `baseUrl` is itself relative (typical Vite-proxied dev
 *     setup), returns `csrfCookieUrl` as-is so the dev proxy can
 *     route it.
 */
function resolveCsrfUrl(csrfCookieUrl: string, baseUrl: string): string {
  if (/^https?:\/\//i.test(csrfCookieUrl) || csrfCookieUrl.startsWith('//')) {
    return csrfCookieUrl
  }
  const hostPrefix = extractHostPrefix(baseUrl)
  if (hostPrefix === null) {
    return csrfCookieUrl
  }
  const trimmedPath = csrfCookieUrl.startsWith('/') ? csrfCookieUrl : `/${csrfCookieUrl}`
  return `${hostPrefix}${trimmedPath}`
}

function extractHostPrefix(baseUrl: string): string | null {
  const match = baseUrl.match(/^(https?:\/\/[^/]+)/i)
  /* c8 ignore next -- @preserve: regex group 1 is always present when match succeeds */
  return match ? (match[1] ?? null) : null
}

/**
 * Read a cookie value by name from the JSDOM/browser document. Returns
 * `null` outside a DOM (e.g. SSR) or when the cookie is absent. The
 * stored value is `decodeURIComponent`-ed because Laravel writes the
 * XSRF-TOKEN cookie URL-encoded.
 */
function readCookie(name: string): string | null {
  /* c8 ignore start -- @preserve: SSR guard, unreachable under JSDOM/Vitest */
  if (typeof document === 'undefined') {
    return null
  }
  /* c8 ignore stop */
  /* c8 ignore next -- @preserve: defensive ?? '' — JSDOM always returns a string */
  const cookies = document.cookie ?? ''
  if (cookies.length === 0) {
    return null
  }
  const escapedName = name.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')
  const pattern = new RegExp(`(?:^|;\\s*)${escapedName}=([^;]*)`)
  const match = cookies.match(pattern)
  if (match === null || match[1] === undefined) {
    return null
  }
  try {
    return decodeURIComponent(match[1])
  } catch {
    return match[1]
  }
}
