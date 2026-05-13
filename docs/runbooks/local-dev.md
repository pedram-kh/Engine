# Runbook — Local development

> Operational reference for engineers working on Catalyst Engine locally.
> Keep this short, accurate, and current. The first-run setup script
> (`scripts/setup.sh`) is the canonical onboarding path — this document
> explains the rationale and shows how to debug when something goes wrong.

---

## 1. The two-SPA local layout

In Phase 1 the platform ships **two browser SPAs** that talk to the **same Laravel API**:

| App     | Vite port | Default URL             |
| ------- | --------- | ----------------------- |
| `main`  | 5173      | `http://127.0.0.1:5173` |
| `admin` | 5174      | `http://127.0.0.1:5174` |
| `api`   | 8000      | `http://127.0.0.1:8000` |

In production the two SPAs live on `app.catalystengine.com` and `admin.catalystengine.com`. **Hosts isolate cookies in production**; Phase 1 local dev needs explicit help because **browsers do NOT isolate cookies by port** — both Vite dev servers share the `127.0.0.1` origin from the cookie jar's perspective.

---

## 2. Cookie isolation in local dev

To stop the two SPAs from clobbering each other's session cookies, the API uses **distinct cookie names per guard**:

| Guard       | SPA   | Cookie name              | XSRF cookie name           |
| ----------- | ----- | ------------------------ | -------------------------- |
| `web`       | main  | `catalyst_main_session`  | `XSRF-TOKEN` (shared name) |
| `web_admin` | admin | `catalyst_admin_session` | `XSRF-TOKEN` (shared name) |

The `catalyst_main_session` name is the default — set in `config/session.php` and reachable via `SESSION_COOKIE`. The admin override is applied at runtime by [`UseAdminSessionCookie`](../../apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie.php), which is **registered globally** in `bootstrap/app.php` so it executes before Sanctum's stateful injection (the thing that triggers `StartSession`).

The middleware is path-aware: it only flips the cookie name on requests whose path starts with `api/v1/admin/`. Everything else flows through with `catalyst_main_session`.

> **Note about XSRF-TOKEN**: Sanctum issues a single `XSRF-TOKEN` cookie regardless of guard. In local dev that cookie is shared between the two SPAs because they share an origin — that's tolerable because CSRF tokens are not authenticators. In production each subdomain gets its own `XSRF-TOKEN` cookie naturally.

---

## 3. Session domain by environment

| Environment | `SESSION_DOMAIN` value        | Why                                                                                      |
| ----------- | ----------------------------- | ---------------------------------------------------------------------------------------- |
| Local       | unset (`null`)                | Cookie binds to the request host (`127.0.0.1`). Distinct cookie names handle isolation.  |
| Staging     | `.staging.catalystengine.com` | Both `app.staging.*` and `admin.staging.*` need to read each others' cookies — same name |
| Production  | `.catalystengine.com`         | Same logic as staging                                                                    |

The two-SPA isolation in production happens via the host axis, not the cookie name. The cookie name override is therefore harmless in those environments — you can leave the global middleware enabled.

---

## 4. Stateful domains (CORS + Sanctum)

Two env variables drive the trust boundary:

```env
# config/cors.php — origins allowed to make CORS requests
FRONTEND_MAIN_URL=http://127.0.0.1:5173
FRONTEND_ADMIN_URL=http://127.0.0.1:5174
FRONTEND_EXTRA_ORIGINS=

# config/sanctum.php — origins that get session cookies attached
SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174
```

If you change ports (e.g., add a Storybook server on 6006), update **both** lists. CORS misconfiguration shows up as preflight 403; Sanctum stateful misconfiguration shows up as session not being attached (login appears to "succeed" but the next request is unauthenticated).

---

## 5. Debugging auth issues locally

1. **Open DevTools → Application → Cookies → `http://127.0.0.1`.** You should see the cookie that matches the SPA you're using:
   - main SPA logged in → `catalyst_main_session`, `XSRF-TOKEN`
   - admin SPA logged in → `catalyst_admin_session`, `XSRF-TOKEN`

2. **If logging in to one SPA logs you out of the other**, the cookie isolation broke. Check:
   - The middleware `App\Modules\Identity\Http\Middleware\UseAdminSessionCookie` is still in `bootstrap/app.php` as a global `prepend(...)` (not just an alias).
   - The admin SPA's API base URL begins with `/api/v1/admin/`. If it doesn't, the middleware never fires and the admin session uses the main cookie.

3. **If the SPA receives a 419 (`CSRF token mismatch`)** on the first POST after login:
   - Confirm the SPA fetches `GET /sanctum/csrf-cookie` before the POST.
   - Confirm the SPA sends the `X-XSRF-TOKEN` header on the POST.
   - In Vite, `axios.defaults.withCredentials = true`.

4. **If the SPA receives a 401 unexpectedly after login**:
   - Confirm `SANCTUM_STATEFUL_DOMAINS` includes the SPA's exact `host:port`.
   - Confirm the SPA sends `withCredentials: true` so the session cookie comes back.

5. **If `php artisan route:list` shows zero auth routes**, the `IdentityServiceProvider` isn't booted. Check `bootstrap/providers.php`.

6. **If `pnpm test` works but `php artisan migrate` fails** with a port-mismatch error, your local `apps/api/.env` was regenerated by `scripts/setup.sh` from the example without your custom ports. Copy `DB_PORT` / `REDIS_PORT` from your root `.env` (or the docker-compose file) into `apps/api/.env`.

---

## 6. MinIO (S3-compatible storage) — Sprint 3 Chunk 1

The Creators module uses four named Laravel disks backed by MinIO in
local dev:

| Disk           | Bucket               | Visibility | Purpose                                                  |
| -------------- | -------------------- | ---------- | -------------------------------------------------------- |
| `media`        | `catalyst-media`     | private    | Avatars, portfolio uploads, anything served via API auth |
| `media-public` | `catalyst-public`    | public     | Public assets (avatars served via CDN; future use)       |
| `contracts`    | `catalyst-contracts` | private    | Signed master contracts (Sprint 4+)                      |
| `exports`      | `catalyst-exports`   | private    | GDPR exports + per-agency archives (Sprint 14)           |

The new MinIO disk is named `media-public` (NOT `public`) to avoid
colliding with Laravel's default `public` disk. The collision would
silently re-route any `Storage::disk('public')` consumer to S3 — which
breaks Sprint 1 features that legitimately use the local-filesystem
`public` disk. See [`docs/reviews/sprint-3-chunk-1-review.md`](../reviews/sprint-3-chunk-1-review.md)
"D-pause-5" for the rationale.

### 6.1 Bootstrap (one-time)

Local-dev uses the `minio/mc` CLI in a sidecar container to create the
four buckets once on first `docker compose up`:

```bash
docker compose run --rm mc \
  mb \
  catalyst/catalyst-media \
  catalyst/catalyst-public \
  catalyst/catalyst-contracts \
  catalyst/catalyst-exports
```

The `media-public` bucket is then made publicly readable:

```bash
docker compose run --rm mc anonymous set download catalyst/catalyst-public
```

### 6.2 Connection settings

The Laravel disks read from these env vars (defaults wired in
`apps/api/.env.example`):

```env
AWS_ACCESS_KEY_ID=catalyst-local
AWS_SECRET_ACCESS_KEY=catalyst-local-secret
AWS_DEFAULT_REGION=us-east-1
AWS_ENDPOINT_URL=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_BUCKET_MEDIA=catalyst-media
AWS_BUCKET_PUBLIC=catalyst-public
AWS_BUCKET_CONTRACTS=catalyst-contracts
AWS_BUCKET_EXPORTS=catalyst-exports
```

If the API runs OUTSIDE docker (e.g. via `php artisan serve` against a
dockerised MinIO), set `AWS_ENDPOINT_URL=http://127.0.0.1:9000` instead.

### 6.3 Troubleshooting

- **`PutObject failed: SignatureDoesNotMatch`**: clock skew between
  the API container and MinIO. `docker compose restart` fixes it
  (re-syncs container clocks).
- **`The specified bucket does not exist`**: run the bootstrap step
  above; check the bucket actually exists with
  `docker compose run --rm mc ls catalyst/`.
- **Avatar uploads succeed but the SPA can't load them**: the SPA
  fetches via authenticated API proxy, NOT via direct MinIO URL.
  Avatars live on the `media` (private) disk by design — see
  `app/Modules/Creators/Services/AvatarUploadService.php`.

---

## 7. Where this is enforced

| Concern                     | Component                                                                 |
| --------------------------- | ------------------------------------------------------------------------- |
| Cookie name flip            | `apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie.php` |
| Stateful API wiring         | `bootstrap/app.php` → `$middleware->statefulApi()`                        |
| Allowed origins (CORS)      | `config/cors.php`                                                         |
| Stateful domains (Sanctum)  | `config/sanctum.php`                                                      |
| Two-SPA-cookie test         | `apps/api/tests/Feature/Modules/Identity/TwoSpaCookieIsolationTest.php`   |
| Integration tests for login | `apps/api/tests/Feature/Modules/Identity/LoginTest.php`                   |

The tests above are the single source of truth for the cookie-isolation contract. If you change the middleware, the contract test must change in the same commit.
