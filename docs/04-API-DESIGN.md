# 04 — API Design

> **Status: Always active reference. Defines the conventions for the Catalyst Engine HTTP API. Every endpoint follows these rules. Cursor must apply this consistently across all phases.**

The API serves both Vue SPAs (main + admin) in Phase 1, and will serve mobile apps in Phase 2 and external integrators in Phase 3. Designing it correctly from the start means none of those clients require a redesign.

---

## 1. Versioning

- All endpoints are mounted under `/api/v1/`.
- Version 1 is the only version through Phase 4 unless a true breaking change is required.
- **Breaking changes** introduce `/api/v2/` and run alongside v1 for at least 12 months.
- **Non-breaking additions** (new fields in responses, new optional query params, new endpoints) do not bump the version.

### What counts as breaking

- Removing a field from a response
- Changing a field's type
- Renaming a field
- Changing the meaning of a value
- Removing an endpoint
- Adding a required request field
- Changing authentication semantics

### What does not count as breaking

- Adding a field to a response (clients ignore unknown fields)
- Adding an optional query parameter
- Adding an endpoint
- Adding new error codes (clients should default-handle unknown codes)
- Tightening validation that was previously permissive (debatable; document as breaking if clients depend on it)

---

## 2. URL structure

### Top-level path

```
/api/v1/
```

### Tenant-scoped resources

Tenant-scoped resources are mounted under `/api/v1/agencies/{agency}/...`. The `{agency}` parameter is the agency's ULID.

Examples:

```
GET    /api/v1/agencies/{agency}/brands
POST   /api/v1/agencies/{agency}/brands
GET    /api/v1/agencies/{agency}/brands/{brand}
GET    /api/v1/agencies/{agency}/brands/{brand}/campaigns
POST   /api/v1/agencies/{agency}/campaigns
GET    /api/v1/agencies/{agency}/campaigns/{campaign}/assignments
POST   /api/v1/agencies/{agency}/campaigns/{campaign}/assignments
```

### Global resources (creator's own data)

Creators access their own data through endpoints that don't require an agency parameter:

```
GET    /api/v1/me                              # Current user profile
GET    /api/v1/me/creator                      # Creator profile (if user is a creator)
PATCH  /api/v1/me/creator                      # Update creator profile
GET    /api/v1/me/creator/availability         # Creator's availability
POST   /api/v1/me/creator/availability         # Add availability block
GET    /api/v1/me/assignments                  # All assignments across all agencies
GET    /api/v1/me/assignments/{assignment}     # Assignment detail (auth checks creator owns it)
```

### Admin resources

Admin endpoints are under `/api/v1/admin/...` and require admin authentication.

```
GET    /api/v1/admin/agencies
GET    /api/v1/admin/creators
GET    /api/v1/admin/creators/{creator}/kyc
POST   /api/v1/admin/creators/{creator}/approve
POST   /api/v1/admin/creators/{creator}/reject
POST   /api/v1/admin/impersonate
GET    /api/v1/admin/audit-logs
```

### Resource naming

- Plural nouns for collections: `/brands`, `/campaigns`, `/creators`.
- Kebab-case for multi-word resources: `/campaign-assignments`, `/audit-logs`.
- Resources nest no more than two levels deep. Deeper nesting goes flat. Bad: `/agencies/x/brands/y/campaigns/z/assignments/a/drafts/b`. Good: `/agencies/x/assignments/a/drafts/b`.

### Action endpoints (verbs)

Most operations are CRUD on resources. When an action doesn't fit CRUD, use a verb under the resource:

```
POST /api/v1/agencies/{agency}/campaigns/{campaign}/publish
POST /api/v1/agencies/{agency}/campaigns/{campaign}/pause
POST /api/v1/agencies/{agency}/assignments/{assignment}/invite
POST /api/v1/agencies/{agency}/assignments/{assignment}/cancel
POST /api/v1/me/assignments/{assignment}/accept
POST /api/v1/me/assignments/{assignment}/decline
POST /api/v1/me/assignments/{assignment}/counter
POST /api/v1/me/assignments/{assignment}/drafts
POST /api/v1/agencies/{agency}/drafts/{draft}/approve
POST /api/v1/agencies/{agency}/drafts/{draft}/request-revision
POST /api/v1/agencies/{agency}/payments/{payment}/release
```

Action endpoints are `POST` (they cause state changes). They take the action's parameters in the body.

---

## 3. HTTP methods

Standard semantics:

| Method   | Use                                                                |
| -------- | ------------------------------------------------------------------ |
| `GET`    | Read; safe and idempotent                                          |
| `POST`   | Create resource OR perform action                                  |
| `PUT`    | Full replacement of a resource (rarely used; prefer PATCH)         |
| `PATCH`  | Partial update of a resource                                       |
| `DELETE` | Soft-delete (default) or hard-delete (rare, explicitly documented) |

We default to `PATCH` over `PUT` for updates because partial updates match how SPAs typically work.

---

## 4. Authentication

### Main app and admin SPA

- **Sanctum SPA authentication** with cookie-based sessions.
- Login flow: `POST /api/v1/auth/login` with email + password (+ 2FA code if MFA enabled). Sets session cookie.
- Subsequent requests carry the cookie; `XSRF-TOKEN` header required for state-changing requests.
- Logout: `POST /api/v1/auth/logout`.

### Mobile (Phase 2)

- **Sanctum personal access tokens.**
- Login flow returns a token in the response body.
- Token sent as `Authorization: Bearer {token}` on subsequent requests.

### Public API (Phase 3)

- **Sanctum personal access tokens** with scopes.
- OAuth2 (Laravel Passport) added if needed.

### Admin authentication

- Same Sanctum cookie auth as main app, but on a separate domain (`admin.domain.com`).
- 2FA mandatory.
- Optional IP allowlist enforced if set.
- Session timeout: 30 minutes idle, 8 hours absolute.

### Endpoints

```
POST   /api/v1/auth/login                          # Email + password (+ 2FA token if required)
POST   /api/v1/auth/logout
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
POST   /api/v1/auth/verify-email                   # With token from email
POST   /api/v1/auth/resend-verification

POST   /api/v1/auth/2fa/enable                     # Returns QR code data
POST   /api/v1/auth/2fa/confirm                    # Confirm with code
POST   /api/v1/auth/2fa/disable
POST   /api/v1/auth/2fa/recovery-codes/regenerate
```

---

## 5. Authorization

- Authorization is per-endpoint, enforced via Laravel policies.
- **Tenant access is verified at every entry point** that touches tenant data. The route's `{agency}` parameter must match an agency the authenticated user belongs to.
- Policy decisions on resources are made via `$this->authorize($action, $resource)` in controllers.
- Authorization failures return `403 Forbidden`. Authentication failures return `401 Unauthorized`.

---

## 6. Request format

### Headers

| Header                           | Required                                       | Notes                                                       |
| -------------------------------- | ---------------------------------------------- | ----------------------------------------------------------- |
| `Accept: application/json`       | Yes                                            | API will refuse to serve HTML responses                     |
| `Content-Type: application/json` | For POST/PUT/PATCH with body                   |                                                             |
| `Accept-Language`                | No                                             | Locale for response (defaults to user preference, then en)  |
| `X-XSRF-TOKEN`                   | For SPA cookie auth on state-changing requests |                                                             |
| `Authorization: Bearer {token}`  | For token auth                                 |                                                             |
| `X-Idempotency-Key`              | Optional                                       | For mutating endpoints to prevent duplicate execution       |
| `X-Client-Version`               | Recommended                                    | Helps with debugging and feature flagging by client version |

### Request body

- JSON only.
- Field names in `snake_case` (matches PHP/Laravel conventions). The frontend translates to camelCase at the API client layer if preferred.
- Dates in ISO 8601 with timezone (`2026-05-04T14:30:00Z`).
- Money as integer minor units, plus separate currency field.
- Foreign keys as ULIDs, never internal integer IDs.

### Query parameters

Standard query parameters across listing endpoints:

| Parameter          | Type    | Notes                                                          |
| ------------------ | ------- | -------------------------------------------------------------- |
| `page`             | integer | 1-indexed page number                                          |
| `per_page`         | integer | Items per page, default 25, max 100                            |
| `sort`             | string  | Field name; prefix with `-` for descending: `sort=-created_at` |
| `filter[field]`    | string  | Filter expressions (see filtering below)                       |
| `include`          | string  | Comma-separated relationships to include                       |
| `fields[resource]` | string  | Comma-separated fields to return (sparse fieldsets)            |
| `search`           | string  | Full-text search on the resource                               |

---

## 7. Response format

### Success envelope

```json
{
  "data": { ... } | [ ... ],
  "meta": { ... },
  "links": { ... }
}
```

- `data`: the resource or array of resources.
- `meta`: pagination, totals, version info.
- `links`: hypermedia links (self, related resources, pagination).

### Single resource

```json
{
  "data": {
    "id": "01HQVKWP0M4XKMJWR5J2PXKKKQ",
    "type": "campaign",
    "attributes": {
      "name": "Summer 2026 Launch",
      "objective": "awareness",
      "status": "active",
      "budget": {
        "amount_minor_units": 5000000,
        "currency": "EUR",
        "amount_formatted": "€50,000.00"
      },
      "starts_at": "2026-06-01T00:00:00Z",
      "ends_at": "2026-08-31T23:59:59Z",
      "created_at": "2026-05-04T14:30:00Z",
      "updated_at": "2026-05-04T14:30:00Z"
    },
    "relationships": {
      "brand": {
        "data": { "id": "01HQ...", "type": "brand" },
        "links": { "related": "/api/v1/agencies/.../brands/01HQ..." }
      },
      "assignments": {
        "links": { "related": "/api/v1/agencies/.../campaigns/01HQ.../assignments" },
        "meta": { "total": 12 }
      }
    },
    "links": {
      "self": "/api/v1/agencies/.../campaigns/01HQ..."
    }
  },
  "meta": {
    "api_version": "1.0"
  }
}
```

The structure above mirrors JSON:API loosely. We don't claim full JSON:API compliance, but the shape is recognizable to anyone who knows it.

### Collection

```json
{
  "data": [
    { "id": "...", "type": "campaign", "attributes": { ... } },
    { "id": "...", "type": "campaign", "attributes": { ... } }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 137,
      "total_pages": 6
    },
    "api_version": "1.0"
  },
  "links": {
    "self": "/api/v1/agencies/.../campaigns?page=1",
    "first": "/api/v1/agencies/.../campaigns?page=1",
    "prev": null,
    "next": "/api/v1/agencies/.../campaigns?page=2",
    "last": "/api/v1/agencies/.../campaigns?page=6"
  }
}
```

### Includes (related resources)

Clients can request related resources be embedded:

```
GET /api/v1/agencies/x/campaigns/y?include=brand,assignments.creator
```

Response includes a top-level `included` array:

```json
{
  "data": { ... },
  "included": [
    { "id": "...", "type": "brand", "attributes": { ... } },
    { "id": "...", "type": "campaign_assignment", "attributes": { ... } },
    { "id": "...", "type": "creator", "attributes": { ... } }
  ]
}
```

Maximum include depth: 2. Maximum included items: 50 per request (controlled by per_page on the parent).

### Sparse fieldsets

Clients can request only specific fields:

```
GET /api/v1/agencies/x/campaigns?fields[campaign]=name,status,budget
```

---

## 8. Error format

### Envelope

```json
{
  "errors": [
    {
      "id": "01HQVKWP0M4XKMJWR5J2PXKKKQ",
      "status": "422",
      "code": "validation.field_required",
      "title": "The brand_id field is required.",
      "detail": "A brand must be selected before creating a campaign.",
      "source": {
        "pointer": "/data/attributes/brand_id"
      },
      "meta": {
        "field": "brand_id"
      }
    }
  ],
  "meta": {
    "request_id": "01HQ..."
  }
}
```

### Fields

- `id` — unique error instance ID, traceable in logs and Sentry
- `status` — HTTP status code as string
- `code` — stable, machine-readable error code (translation key for the client)
- `title` — short human-readable summary
- `detail` — longer human-readable explanation
- `source.pointer` — JSON pointer to the offending field, if applicable
- `meta` — error-specific extra context

### HTTP status codes

| Status                      | Use                                                                             |
| --------------------------- | ------------------------------------------------------------------------------- |
| `200 OK`                    | Success with body                                                               |
| `201 Created`               | Resource created                                                                |
| `202 Accepted`              | Async work started                                                              |
| `204 No Content`            | Success with no body (e.g., DELETE)                                             |
| `400 Bad Request`           | Malformed request                                                               |
| `401 Unauthorized`          | Not authenticated                                                               |
| `403 Forbidden`             | Authenticated but not authorized                                                |
| `404 Not Found`             | Resource doesn't exist OR exists but caller can't see it (don't leak existence) |
| `409 Conflict`              | State conflict (e.g., trying to publish a draft that's already published)       |
| `410 Gone`                  | Soft-deleted resource                                                           |
| `422 Unprocessable Entity`  | Validation errors                                                               |
| `423 Locked`                | Resource is locked (e.g., agency suspended)                                     |
| `429 Too Many Requests`     | Rate limit hit                                                                  |
| `500 Internal Server Error` | Bug                                                                             |
| `502 Bad Gateway`           | Upstream provider failed (Stripe, KYC, etc.)                                    |
| `503 Service Unavailable`   | Maintenance mode or scheduled downtime                                          |

### Error code namespacing

Error codes are dot-namespaced strings:

```
auth.invalid_credentials
auth.mfa_required
auth.mfa_invalid
validation.field_required
validation.field_type
validation.unique_violation
authorization.forbidden
authorization.cross_tenant
campaigns.not_found
campaigns.invalid_state_transition
payments.escrow_already_released
payments.provider_error
creators.kyc_required
creators.contract_not_signed
creators.blacklisted
rate_limit.exceeded
maintenance.scheduled
```

Frontend maps codes to localized messages via i18n keys: `__('errors.campaigns.invalid_state_transition')`.

---

## 9. Filtering

Listing endpoints support filtering via the `filter[field]` syntax:

```
GET /api/v1/agencies/x/campaigns?filter[status]=active
GET /api/v1/agencies/x/campaigns?filter[status]=active,paused
GET /api/v1/agencies/x/campaigns?filter[starts_at][gte]=2026-01-01
GET /api/v1/agencies/x/campaigns?filter[brand]=01HQ...
GET /api/v1/agencies/x/creators?filter[categories]=lifestyle,sports
GET /api/v1/agencies/x/creators?filter[country]=PT,IT
```

### Operators

- Default: equality. `filter[status]=active`
- Multiple values: comma-separated, treated as `IN`. `filter[status]=active,paused`
- Range operators: `[gte]`, `[gt]`, `[lte]`, `[lt]`. `filter[starts_at][gte]=2026-01-01`
- Negation: `[not]`. `filter[status][not]=cancelled`
- Null: `filter[deleted_at]=null` or `filter[verified_at]=not_null`

### Allowed filter fields

Each resource defines which fields are filterable. Attempting to filter on a non-allowed field returns `400`. The set of allowed filters is documented per-endpoint in the OpenAPI spec.

---

## 10. Sorting

Listing endpoints support sorting via `sort`:

```
sort=created_at         # ascending
sort=-created_at        # descending
sort=-priority,name     # multi-field
```

Allowed sort fields are also resource-defined.

---

## 11. Pagination

### Offset pagination (default)

```
?page=1&per_page=25
```

Response meta includes `pagination` block.

### Cursor pagination (for high-volume endpoints)

For endpoints that return very high-volume data (audit logs, messages, social metrics):

```
?cursor=eyJpZCI6MTIzfQ&per_page=50
```

Response includes `meta.cursor` with `next_cursor` and `prev_cursor`.

Cursor pagination is opaque to the client. It encodes the position internally.

### Defaults and limits

- Default `per_page`: 25
- Maximum `per_page`: 100
- Going over max returns `400 Bad Request`

---

## 12. Idempotency

State-changing endpoints support optional idempotency:

```
X-Idempotency-Key: 01HQVKWP0M4XKMJWR5J2PXKKKQ
```

When provided:

- The server stores the request's idempotency key with the response for 24 hours.
- A second request with the same key returns the same response without re-executing.
- A second request with the same key but different body returns `409 Conflict`.

Idempotency is mandatory for: payment operations, contract signing, sending invitations. Recommended for all state-changing operations.

---

## 13. Rate limiting

Tiered limits enforced via Laravel's `throttle` middleware:

| Group                             | Limit                             |
| --------------------------------- | --------------------------------- |
| Unauthenticated (auth endpoints)  | 10 requests / minute / IP         |
| Authenticated, regular            | 120 requests / minute / user      |
| Authenticated, admin              | 600 requests / minute / user      |
| Webhooks (inbound from providers) | 1000 requests / minute / provider |
| Search endpoints                  | 30 requests / minute / user       |
| Bulk operations                   | 10 requests / minute / user       |

Response headers on every request:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1714824600
```

When rate limit is hit:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 23
```

Body:

```json
{
  "errors": [
    {
      "code": "rate_limit.exceeded",
      "title": "Too many requests.",
      "detail": "You have exceeded the rate limit. Try again in 23 seconds."
    }
  ]
}
```

---

## 14. Webhooks (inbound)

Inbound webhooks from external providers (Stripe, social APIs, KYC, e-sign) are received at:

```
POST /api/v1/webhooks/{provider}
```

### Verification

- Every provider's signature is verified before processing.
- Stripe: `Stripe-Signature` header verified against signing secret.
- DocuSign: HMAC verification.
- Persona: HMAC verification.
- Meta/TikTok/YouTube: signature verification per their docs.

### Idempotency

- The `provider_event_id` is stored in `integration_events` to prevent reprocessing.
- Always return `200 OK` quickly. Heavy processing happens in a queued job.

### Endpoint pattern

```
POST /api/v1/webhooks/stripe
POST /api/v1/webhooks/persona
POST /api/v1/webhooks/docusign
POST /api/v1/webhooks/meta
POST /api/v1/webhooks/tiktok
POST /api/v1/webhooks/youtube
```

---

## 15. Outbound webhooks (Phase 3)

Designed for Phase 3 public API. Phase 1 doesn't expose outbound webhooks.

When built, the pattern will be:

- Agency configures webhook endpoints with shared secrets.
- We send POST requests with HMAC signature header.
- Retry policy: exponential backoff, up to 24 hours.
- Delivery log surfaced in admin SPA and agency UI.

---

## 16. Localization

### Response language

Determined by:

1. `Accept-Language` header
2. Authenticated user's `preferred_language`
3. Default `en`

Affects:

- Error messages
- Validation messages
- Translatable enum labels (when sent — usually we send the key, frontend translates)
- Mail content

Resource fields are returned as-stored (creator content is in whatever language the creator wrote it).

### Currency formatting

Currency values always sent as both:

- `amount_minor_units`: integer (e.g., 5000000)
- `currency`: ISO code (e.g., "EUR")
- `amount_formatted`: locale-formatted string (e.g., "€50,000.00")

Formatted is for display convenience. Clients should ideally do their own formatting using `Intl.NumberFormat` for full locale control.

---

## 17. Bulk operations

Bulk operations are explicit endpoints, not generic. Each operation is a separate endpoint.

```
POST /api/v1/agencies/{agency}/creators/bulk-invite
POST /api/v1/agencies/{agency}/assignments/bulk-message
POST /api/v1/agencies/{agency}/drafts/bulk-approve
```

### Pattern

Request body:

```json
{
  "items": [
    { "id": "01HQ...", "data": { ... } },
    { "id": "01HQ...", "data": { ... } }
  ],
  "options": {
    "stop_on_error": false
  }
}
```

Response (always 200, even with partial failures):

```json
{
  "data": {
    "succeeded": [
      { "id": "01HQ...", "result": { ... } }
    ],
    "failed": [
      { "id": "01HQ...", "error": { "code": "...", "title": "..." } }
    ]
  },
  "meta": {
    "total": 50,
    "succeeded_count": 47,
    "failed_count": 3
  }
}
```

Bulk operations are queued for execution. The endpoint returns when:

- Synchronous bulk (≤ 25 items): processed inline.
- Async bulk (> 25 items): returns `202 Accepted` with a job ID. Client polls `/api/v1/jobs/{job}` for progress.

---

## 18. Long-running operations

### Job tracking

Async operations (bulk, exports, large imports) return a job:

```
POST /api/v1/agencies/{agency}/data-export
↓
202 Accepted
{
  "data": {
    "job_id": "01HQ...",
    "status": "queued"
  },
  "links": {
    "self": "/api/v1/jobs/01HQ..."
  }
}
```

```
GET /api/v1/jobs/{job}
↓
{
  "data": {
    "id": "01HQ...",
    "type": "data_export",
    "status": "processing",
    "progress": 0.65,
    "started_at": "...",
    "estimated_completion_at": "...",
    "result": null
  }
}
```

When complete, `status: "complete"` and `result` includes a download URL or relevant data.

### Server-sent events (Phase 2+)

For real-time progress updates, SSE endpoints under `/api/v1/streams/...` (Phase 2 consideration). Not in Phase 1.

---

## 19. File uploads

### Direct upload

Small files (≤ 10MB) can be uploaded directly via `multipart/form-data`:

```
POST /api/v1/agencies/{agency}/brands/{brand}/logo
Content-Type: multipart/form-data
```

### Pre-signed S3 upload (recommended for large files)

For files larger than 10MB (creator videos especially), use the pre-signed URL pattern:

```
POST /api/v1/uploads/initiate
{
  "kind": "creator_portfolio_video",
  "filename": "audition.mp4",
  "mime_type": "video/mp4",
  "size_bytes": 524288000
}

↓

200 OK
{
  "data": {
    "upload_id": "01HQ...",
    "upload_url": "https://...s3.amazonaws.com/...?signature=...",
    "expires_at": "...",
    "fields": { ... }
  }
}
```

Client uploads directly to S3 with the pre-signed URL, then notifies us:

```
POST /api/v1/uploads/{upload}/complete
```

Server validates the upload and links the file to the appropriate resource.

---

## 20. Health checks

Public health check endpoints:

```
GET /api/v1/health           # Liveness — returns 200 if process is up
GET /api/v1/health/ready     # Readiness — checks DB, Redis, S3
```

These are unauthenticated. Used by load balancer and monitoring.

---

## 21. Realtime (Phase 2+)

Realtime updates (new messages, draft submitted, payment released) come via:

- **Phase 1:** Polling every 30 seconds for active campaigns.
- **Phase 2:** WebSockets via Laravel Reverb or Pusher channels. Channel naming `agency.{agency}.campaign.{campaign}`, etc. Authorized via Laravel Broadcasting.

API design supports both — the same data is available via REST whenever needed.

---

## 22. Caching

### Response caching

- `GET` endpoints that return slowly-changing data include `Cache-Control` and `ETag` headers.
- Public profile photos: `Cache-Control: public, max-age=3600`.
- Creator listings (changes often): no cache.
- Aggregate stats endpoints: short-cache `Cache-Control: private, max-age=60`.

### Conditional requests

Endpoints that support `ETag` honor `If-None-Match`:

```
GET /api/v1/me/creator
If-None-Match: "abc123"
↓
304 Not Modified
```

---

## 23. Documentation

- **OpenAPI 3.1 spec** generated from controller annotations using Scribe (or similar).
- Spec is committed to the repo and regenerated on every PR.
- Spec is accessible at `/api/docs` in non-production environments.
- Examples for every endpoint, including success and error responses.
- Interactive Swagger UI in non-production for testing.

---

## 24. Validation patterns

### Input validation

- Form Request validation handles all input.
- Validation errors return `422` with a list of field-level errors.
- Each field error has its own entry in the `errors` array with `source.pointer` to the field.

Example response:

```json
{
  "errors": [
    {
      "code": "validation.required",
      "title": "The name field is required.",
      "source": { "pointer": "/data/attributes/name" }
    },
    {
      "code": "validation.min",
      "title": "The budget must be at least 100.",
      "source": { "pointer": "/data/attributes/budget_minor_units" },
      "meta": { "min": 10000 }
    }
  ]
}
```

### State validation

- Validation that depends on the resource's current state happens after input validation.
- These return `409 Conflict` with a code like `campaigns.invalid_state_transition`.

---

## 25. Search endpoints

### Within a resource

```
GET /api/v1/agencies/{agency}/creators?search=marina+lifestyle
```

Searches the relevant text fields of the resource.

### Global search

```
GET /api/v1/agencies/{agency}/search?q=marina&types=creator,campaign,brand
```

Returns mixed-type results:

```json
{
  "data": [
    { "id": "01HQ...", "type": "creator", "title": "Marina Silva", "attributes": { ... } },
    { "id": "01HQ...", "type": "campaign", "title": "Marina Beach Launch", "attributes": { ... } }
  ]
}
```

---

## 26. Audit-aware endpoints

Sensitive endpoints require additional metadata:

### Reason header for destructive actions

```
DELETE /api/v1/admin/creators/{creator}
X-Action-Reason: Account abandoned per support ticket #1234
```

If `X-Action-Reason` is missing on endpoints that require it, the request returns `400 Bad Request` with code `validation.reason_required`.

Endpoints requiring reason (Phase 1):

- `DELETE /api/v1/admin/...` (all admin destructive actions)
- `POST .../blacklist` actions
- `POST .../impersonate`
- `POST .../refund`

---

## 27. Phase-by-phase API additions

Phase 1 ships the API as defined above. Later phases add:

| Phase | Additions                                                                                                                                                                                                            |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P2    | Brand portal endpoints under `/api/v1/agencies/{agency}/brands/{brand}/portal/...`. Mobile-specific endpoints via the same routes (auth via Bearer token). WebSocket channels for realtime. Calendar sync endpoints. |
| P3    | Marketplace endpoints (creators browsing campaigns). AI endpoints (`/api/v1/agencies/{agency}/ai/...` — matching, brief generation, QC). Public API expansion with scope-based tokens.                               |
| P4    | Vertical AI agent endpoints. Affiliate / performance attribution endpoints. Content licensing marketplace endpoints. Embedded analytics endpoints.                                                                   |

The shape of the API never breaks. Only additions.

---

## 28. Examples

### Create a campaign

```http
POST /api/v1/agencies/01HQ.../campaigns HTTP/1.1
Host: api.catalyst-engine.com
Content-Type: application/json
Accept: application/json
X-XSRF-TOKEN: ...
X-Idempotency-Key: 01HQ...

{
  "data": {
    "type": "campaign",
    "attributes": {
      "name": "Summer 2026 Launch",
      "objective": "awareness",
      "budget_minor_units": 5000000,
      "budget_currency": "EUR",
      "starts_at": "2026-06-01T00:00:00Z",
      "ends_at": "2026-08-31T23:59:59Z",
      "brief": {
        "deliverables": [
          { "kind": "instagram_reel", "count": 1 },
          { "kind": "instagram_story", "count": 3 }
        ],
        "do_not": ["mention competitors", "use prohibited language"],
        "hashtags": ["#summer2026", "#brandlaunch"]
      }
    },
    "relationships": {
      "brand": { "data": { "id": "01HQ...", "type": "brand" } }
    }
  }
}
```

Response:

```http
HTTP/1.1 201 Created
Content-Type: application/json
Location: /api/v1/agencies/01HQ.../campaigns/01HQ...

{
  "data": {
    "id": "01HQ...",
    "type": "campaign",
    "attributes": { ... },
    "relationships": { ... },
    "links": { "self": "..." }
  },
  "meta": { "api_version": "1.0" }
}
```

### List creators with filters

```http
GET /api/v1/agencies/01HQ.../creators?filter[country]=PT,IT&filter[categories]=lifestyle&sort=-last_active_at&include=social_accounts&page=1&per_page=25 HTTP/1.1
```

### Validation error

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/json

{
  "errors": [
    {
      "id": "01HQ...",
      "status": "422",
      "code": "validation.required",
      "title": "The brand_id field is required.",
      "source": { "pointer": "/data/relationships/brand" }
    }
  ],
  "meta": { "request_id": "01HQ..." }
}
```

---

**End of API design. Every endpoint follows these rules.**
