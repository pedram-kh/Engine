/**
 * Single error class thrown by every typed function exposed from this
 * package. Mirrors the backend error envelope from
 * `docs/04-API-DESIGN.md §8`:
 *
 * ```json
 * {
 *   "errors": [
 *     {
 *       "id": "01HQ...",
 *       "status": "422",
 *       "code": "validation.field_required",
 *       "title": "...",
 *       "detail": "...",
 *       "source": { "pointer": "/data/attributes/brand_id" },
 *       "meta": { "field": "brand_id" }
 *     }
 *   ],
 *   "meta": { "request_id": "01HQ..." }
 * }
 * ```
 *
 * `code` is preserved verbatim from the backend. Several distinct
 * internal failure modes have been intentionally collapsed into single
 * codes for non-fingerprinting (Sprint 1 chunk-4 / chunk-5 standard
 * 5.4 in `docs/PROJECT-WORKFLOW.md`); this class never re-expands them
 * back into different sub-codes.
 *
 * `raw` exposes the parsed JSON body of the failing response (or
 * whatever non-JSON shape we got) so callers writing diagnostics can
 * inspect the unfiltered server reply without re-issuing the call.
 */

/**
 * The shape of a single error entry inside the envelope's `errors`
 * array. The backend always emits at least one entry per `4xx` / `5xx`
 * response.
 */
export interface ApiErrorDetail {
  readonly id?: string
  readonly status?: string
  readonly code?: string
  readonly title?: string
  readonly detail?: string
  readonly source?: {
    readonly pointer?: string
    readonly parameter?: string
  }
  readonly meta?: Record<string, unknown>
}

/**
 * Constructor parameters for {@link ApiError}.
 *
 * Kept as a single options bag so we can grow the surface (e.g. to
 * carry response headers in a later chunk for `Retry-After` handling)
 * without breaking callers.
 */
export interface ApiErrorOptions {
  readonly status: number
  readonly code: string
  readonly message: string
  readonly details?: readonly ApiErrorDetail[]
  readonly raw?: unknown
  readonly requestId?: string
  readonly cause?: unknown
}

const NETWORK_ERROR_CODE = 'network.error'
const UNKNOWN_ERROR_CODE = 'http.unknown_error'
const NOT_JSON_BODY_CODE = 'http.invalid_response_body'

export class ApiError extends Error {
  /**
   * HTTP status code. `0` for non-HTTP failures (network, abort).
   */
  public readonly status: number

  /**
   * Canonical, machine-readable error code (e.g. `auth.mfa_required`,
   * `validation.field_required`). Preserved verbatim from the backend
   * envelope's first error entry; falls back to a synthetic
   * `http.*` / `network.*` code on transport failures.
   */
  public readonly code: string

  /**
   * The full list of error entries from the envelope. Most responses
   * have exactly one entry; validation errors can have many (one per
   * offending field).
   */
  public readonly details: readonly ApiErrorDetail[]

  /**
   * The unfiltered JSON body of the failing response. Useful for
   * debugging when the backend ships extra metadata we have not yet
   * surfaced as fields on this class.
   */
  public readonly raw: unknown

  /**
   * The `meta.request_id` value from the envelope, when present.
   * Surfaced separately so observability code can correlate without
   * re-walking `raw`.
   */
  public readonly requestId: string | undefined

  public constructor(options: ApiErrorOptions) {
    super(options.message)
    this.name = 'ApiError'
    this.status = options.status
    this.code = options.code
    this.details = options.details ?? []
    this.raw = options.raw
    this.requestId = options.requestId
    if (options.cause !== undefined) {
      // `Error.cause` (ES2022) ships at runtime in every browser and
      // Node version we target, but the SPA's tsconfig pins `lib` to
      // ES2020 — its `Error` constructor doesn't accept the options
      // bag. Set `cause` as an own property to stay compatible with
      // both lib levels without losing the runtime semantics.
      Object.defineProperty(this, 'cause', {
        value: options.cause,
        writable: true,
        configurable: true,
        enumerable: false,
      })
    }
    Object.setPrototypeOf(this, ApiError.prototype)
  }

  /**
   * Build an {@link ApiError} from a parsed JSON body that follows the
   * envelope contract from `docs/04-API-DESIGN.md §8`. Defensive: if
   * the body is not the expected shape (an upstream proxy injecting
   * HTML, a 502 with no body, etc.) the call still produces a usable
   * {@link ApiError} with `code: 'http.invalid_response_body'` and the
   * original body preserved on `raw`.
   */
  public static fromEnvelope(status: number, body: unknown): ApiError {
    if (!isObjectRecord(body) || !Array.isArray(body['errors']) || body['errors'].length === 0) {
      return new ApiError({
        status,
        code: NOT_JSON_BODY_CODE,
        message: `Unrecognized error response (HTTP ${status}).`,
        raw: body,
      })
    }

    const entries = body['errors'].filter(isObjectRecord) as readonly Record<string, unknown>[]
    const details: ApiErrorDetail[] = entries.map(toDetail)
    const first = details[0]
    const code =
      typeof first?.code === 'string' && first.code.length > 0 ? first.code : UNKNOWN_ERROR_CODE
    const message =
      typeof first?.title === 'string' && first.title.length > 0 ? first.title : `HTTP ${status}`
    const meta = isObjectRecord(body['meta']) ? body['meta'] : undefined
    const requestId =
      meta && typeof meta['request_id'] === 'string' ? meta['request_id'] : undefined

    return new ApiError({
      status,
      code,
      message,
      details,
      raw: body,
      ...(requestId !== undefined ? { requestId } : {}),
    })
  }

  /**
   * Build an {@link ApiError} for a transport failure (no HTTP
   * response at all — the request never made it back).
   */
  public static fromNetworkError(cause: unknown): ApiError {
    return new ApiError({
      status: 0,
      code: NETWORK_ERROR_CODE,
      message: 'The server could not be reached. Check your connection and try again.',
      cause,
    })
  }
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function toDetail(entry: Record<string, unknown>): ApiErrorDetail {
  const detail: Mutable<ApiErrorDetail> = {}
  if (typeof entry['id'] === 'string') detail.id = entry['id']
  if (typeof entry['status'] === 'string') detail.status = entry['status']
  if (typeof entry['code'] === 'string') detail.code = entry['code']
  if (typeof entry['title'] === 'string') detail.title = entry['title']
  if (typeof entry['detail'] === 'string') detail.detail = entry['detail']
  if (isObjectRecord(entry['source'])) {
    const src: { pointer?: string; parameter?: string } = {}
    const source = entry['source']
    if (typeof source['pointer'] === 'string') src.pointer = source['pointer']
    if (typeof source['parameter'] === 'string') src.parameter = source['parameter']
    detail.source = src
  }
  if (isObjectRecord(entry['meta'])) detail.meta = entry['meta']
  return detail
}

type Mutable<T> = {
  -readonly [K in keyof T]: T[K]
}
