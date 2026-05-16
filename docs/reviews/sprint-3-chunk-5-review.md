# Sprint 3 — Chunk 5 Review

**Status:** Closed.

**Reviewer:** Claude (independent review) — single-chunk follow-up triggered by a real-user repro on `POST /api/v1/agencies/{agency}/brands`.

**Commits:**

- **work commit** (commit subject: `fix(api): normalize FormRequest 422 to the JSON:API error envelope + SPA per-field rendering`).
- **docs commit** (commit subject: `docs(reviews): close sprint 3 chunk 5 + log Laravel-default-exception tech-debt`).

**Reviewed against:** `04-API-DESIGN.md` § 8 (canonical error envelope), `PROJECT-WORKFLOW.md` § 3 (build-pass discipline) + § 5 standards #5.4 (non-fingerprinting error codes) + #34 (architecture tests), `02-CONVENTIONS.md` § 1 (modular monolith), `07-TESTING.md` § 4-5 (test coverage discipline), `tech-debt.md` (one new entry by this chunk).

This chunk closes a real architectural bug discovered post-merge in Sprint 3 Chunk 4: every `FormRequest` validation failure across the entire `api/` app was bypassing the canonical JSON:API error envelope and shipping Laravel's legacy `{message, errors:{field:[]}}` shape — invisible to the test suite, but it crashed the SPA's `ApiError.fromEnvelope` parser, which marked every 422 as `[http.invalid_response_body] Unrecognized error response (HTTP 422)` and silently dropped the per-field detail. The bug was found end-to-end during local QA of the brand-create surface: type a brand name, click Save, see `Failed to save brand.` with no further detail. The same vector affected every other validation 422 in the codebase; the brand-create page was simply the one a real user happened to exercise first.

---

## Scope

### The bug (root cause)

- `apps/api/bootstrap/app.php`'s `withExceptions()` callback was empty. Laravel's default `ValidationException` renderer therefore won the chain and emitted `{"message": "...", "errors": {"<field>": ["<msg>"]}}` for every FormRequest 422.
- `packages/api-client/src/errors.ts` → `ApiError.fromEnvelope()` defensively rejects any body that doesn't match the envelope contract from `04-API-DESIGN.md § 8`. The legacy Laravel shape is exactly such a mismatch, so it surfaced as the synthetic code `http.invalid_response_body` with `raw` set to the original Laravel body.
- `BrandCreatePage.vue` and `BrandEditPage.vue` had `catch {}` blocks (no `err` binding) that always rendered `t('app.brands.errors.saveFailed')` regardless of the underlying cause. Even when the envelope worked, the SPA was throwing away the structured detail. The empty-catch silently masked the 422-shape mismatch from observability — the bug had been present since Sprint 3 Chunk 4 sub-step 6 landed and nobody saw it because the SPA never told anyone.

### What this chunk lands

**Backend — global validation-envelope normalizer (1 new file, 1 registration site):**

- New `App\Core\Errors\ValidationExceptionRenderer` (`apps/api/app/Core/Errors/ValidationExceptionRenderer.php`) — pure function that consumes a `ValidationException` + `Request` and returns a `JsonResponse` in the canonical envelope shape from `04-API-DESIGN.md § 8`. One entry per `(field, message)` pair. Each entry carries:
  - `code: 'validation.failed'` — single canonical code, per #5.4 non-fingerprinting standard.
  - `source.pointer: '/data/attributes/<field>'` — JSON Pointer per JSON:API.
  - `meta.field: '<field>'` — denormalised for callers that prefer scanning meta.
  - `meta.rule: 'Required' | 'Min' | ...` — Laravel's rule class name when the validator exposes it (read via `$exception->validator->failed()`), useful for future per-rule i18n keys.
  - `title` and `detail` set to Laravel's human-readable message.
- Honors the FormRequest `failedValidation()` escape hatch: if the exception carries a pre-built `JsonResponse` (e.g. `AdminUpdateCreatorRequest::failedValidation()` constructs a bespoke `creator.admin.field_status_immutable` envelope), the renderer passes it through verbatim instead of clobbering with a generic `validation.failed`.
- Registered globally in `apps/api/bootstrap/app.php` `withExceptions()` and gated on `$request->expectsJson()` — non-JSON requests (none today; gate is forward-compatible) fall through to Laravel's default behaviour.

**Backend — test infrastructure (1 new helper, 16 migrated call sites):**

- New `assertEnvelopeValidationErrors(array $fields)` macro on `Illuminate\Testing\TestResponse` (registered in `apps/api/tests/Pest.php`). Replaces Laravel's built-in `assertJsonValidationErrors()` for API tests. Asserts:
  - Status is 422.
  - JSON structure conforms to the canonical envelope (`errors[].id|status|code|title|detail|source.pointer|meta.field` + `meta.request_id`).
  - For each expected `<field>` argument, the envelope contains an entry with `source.pointer === '/data/attributes/<field>'`.
- All 16 `assertJsonValidationErrors(...)` call sites across 7 feature-test files migrated to the new macro (`BrandCrudTest.php`, `SignUpTest.php` × 7, `PasswordResetTest.php` × 3, `VerifyEmailTest.php`, `ResendVerificationTest.php`, `InvitationTest.php`, `AgencySettingsTest.php` × 2).
- The redundant `->assertStatus(422)` / `->assertUnprocessable()` calls that previously prefixed each `assertJsonValidationErrors` were folded into the new macro (it asserts status internally), reducing the per-test boilerplate.

**Backend — focused renderer tests (4 cases, new file):**

- `apps/api/tests/Unit/Core/Errors/ValidationExceptionRendererTest.php` — 4 unit cases:
  1. One envelope entry per `(field, message)` pair, with `/data/attributes/<field>` pointers and `meta.rule` from the validator's `failed()` output.
  2. Multiple entries when a single field has multiple violations.
  3. Pre-built FormRequest response (the `failedValidation()` escape hatch) is passed through verbatim — covers the `creator.admin.field_status_immutable` use case so future refactors don't silently re-erase domain-specific codes.
  4. Inbound `X-Request-Id` is honoured on the envelope's `meta.request_id`; absent header gets a fresh ULID.
  5. End-to-end integration through the real HTTP pipeline against `POST /api/v1/auth/sign-up` — exercises the registration in `bootstrap/app.php`, not just the renderer in isolation.

**Frontend — `extractFieldErrors` helper (1 new exported function + 6 unit tests):**

- New `packages/api-client/src/errors.ts` → `extractFieldErrors<TField>(error: ApiError): Partial<Record<TField, readonly string[]>>` — groups the envelope's `details[]` by backend field name. Reads `source.pointer` first (strips the `/data/attributes/` prefix), falls back to `meta.field`. Drops entries that have neither (so transport failures and non-validation 4xx/5xx pass through cleanly as an empty object). Prefers `detail` over `title` so the UI gets the long human message when both are present.
- Generic-typed so callers narrow the result to their payload's field union (e.g. `extractFieldErrors<keyof CreateBrandPayload>(err)` — only `keyof CreateBrandPayload` keys can appear in the result type).
- Exported from `packages/api-client/src/index.ts` next to `ApiError`.
- 6 new unit cases in `packages/api-client/src/errors.spec.ts` cover: standard grouping, multiple messages per field, `meta.field` fallback, no-pointer-no-meta drop, `detail` > `title` preference, network-error empty-object case.

**Frontend — per-field rendering in `BrandForm` + pages (3 files touched):**

- `apps/main/src/modules/brands/components/BrandForm.vue` — new `fieldErrors?: Partial<Record<keyof CreateBrandPayload, readonly string[]>>` prop wired to every `v-text-field`/`v-textarea`/`v-select`'s `error-messages` slot. Vuetify renders the messages inline beneath each input with its standard error styling — keyboard-navigable, screen-reader accessible. The top-level `<v-alert>` banner is retained for non-validation failures (auth, tenancy, 5xx, etc.) so each error class has exactly one signal source.
- Same file — submit-side slug fallback. Previously `onNameBlur` was the only path that auto-populated `slug` from `name`. A user who typed name and hit `Enter` in that field (focus never leaves the input → blur never fires) bypassed the autofill and submitted with `slug` undefined, which the backend correctly 422'd. The form's `@submit.prevent` handler is now `onSubmit()` which re-runs `slugify(name)` if `slug` is blank before emitting `submit` up to the page. The on-blur path is preserved so the slug field visually populates while the user is still on the page; the on-submit path closes the keyboard-only race.
- `apps/main/src/modules/brands/pages/BrandCreatePage.vue` + `BrandEditPage.vue` — both pages now `catch (err)` on save, narrow to `ApiError`, run `extractFieldErrors<keyof CreateBrandPayload>(err)`, and pass the grouped field errors into the form. When `extractFieldErrors` returns an empty object (no per-field detail to render — auth, 5xx, etc.) the page falls back to a top-level banner showing the `ApiError.code`. Console-logs the full envelope (`status`, `code`, `details`, `requestId`) for debugging.

---

## Why this matters beyond the brand surface

The bug was in `bootstrap/app.php`. The fix is in `bootstrap/app.php`. Every FormRequest validation 422 across the entire `api/` app is now rendered through the canonical envelope, not just the brand-create endpoint. The 7 feature-test files migrated to `assertEnvelopeValidationErrors` cover sign-up, password reset, email verification, resend verification, brand CRUD, agency settings, and invitations — and every other endpoint with a FormRequest gets the same envelope automatically.

The `extractFieldErrors` helper is exported from `@catalyst/api-client` (not locked into the brands module), so the next page that ships per-field error rendering reuses the same call: `extractFieldErrors<keyof MyPayload>(err)`. The brands pages are the reference implementation.

---

## Provenance trail

1. **Sprint 3 Chunk 4 close.** Brand restore UI lands as sub-step 6 (commit `eeb7d2b`). The brand-create page was already wired to `POST /api/v1/agencies/{agency}/brands`. CI green.
2. **Local QA — real user repro.** Pedram runs `pnpm dev`, logs in as the seeded agency admin, navigates to `/brands/new`, types a brand name, clicks Save. SPA renders `Failed to save brand.` with no further detail. No console output (page was using a bare `catch {}`).
3. **First diagnostic patch.** `BrandCreatePage.vue` `catch {}` widened to `catch (err)` with envelope introspection. Re-click surfaces `[http.invalid_response_body] Unrecognized error response (HTTP 422).` — the symptom that exposed the actual architectural bug.
4. **End-to-end reproduction.** `curl POST /api/v1/agencies/.../brands -d '{"name":"Test"}'` returns HTTP 422 with body `{"message":"The slug field is required.","errors":{"slug":["The slug field is required."]}}` — Laravel's legacy validation shape. Cross-referenced with `04-API-DESIGN.md § 8` — the envelope contract was never being honoured for FormRequest failures.
5. **This chunk.** Global normalizer + per-field SPA rendering + 16 test migrations + new shared `extractFieldErrors` helper. The diagnostic patch from step 3 is folded into the page's permanent error-handling shape.

---

## Honest deviations from the kickoff plan

- **There was no kickoff plan.** This chunk was triggered by a real-user-found bug discovered hours after Chunk 4 closed. The work was scoped inline (see [next-step question](../../docs/reviews/sprint-3-chunk-4-review.md) preamble in the chunk-4 conversation transcript): user chose `land_full_fix` over `workaround_only`, three smaller incremental options, or a SPA-only fix. The plan was the option chosen + the todo list followed during execution.
- **No new locale strings.** The 422 per-field rendering relies on Laravel's already-i18n'd validation messages (the backend speaks the user's preferred language and ships the message in the envelope's `detail`). Frontend i18n keys would have duplicated the same strings on both sides — a non-trivial drift risk versus the marginal control gained — so the SPA renders `entry.detail` verbatim. If a future chunk needs override copy for a specific `meta.rule + meta.field` combination, the `useErrorMessage` resolver pattern from Chunk 6 of Sprint 1 generalises (key path: `app.brands.fields.<field>.<rule>`).
- **Pre-built `failedValidation()` escape hatch retained.** The renderer respects `ValidationException::$response` instead of clobbering it. This preserves `AdminUpdateCreatorRequest::failedValidation()`'s bespoke `creator.admin.field_status_immutable` code — that surface ships a domain-specific code, not a generic `validation.failed`, and the chunk-4 review-#9 standard is "preserve backend error codes verbatim." A test case pins the escape-hatch contract so a future refactor doesn't accidentally erase it.
- **Other Laravel exception shapes (401, 403, 404, 405, 5xx) still emit defaults.** Out of scope. Filed as tech-debt; see `docs/tech-debt.md` entry "Laravel default exception shapes outside `ValidationException` still bypass the canonical envelope."

---

## Verification log

Sequence run before commit:

| Command                                                               | Result                                                            |
| --------------------------------------------------------------------- | ----------------------------------------------------------------- |
| `php -d memory_limit=1G vendor/bin/pest tests/Unit/Core/Errors/`      | 5 / 5 pass                                                        |
| `php -d memory_limit=1G vendor/bin/pest` (full backend suite)         | 815 / 815 pass                                                    |
| `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G` | 0 errors                                                          |
| `php vendor/bin/pint --test`                                          | 0 issues                                                          |
| `cd packages/api-client && pnpm test -- --run`                        | 100 / 100 pass (6 new `extractFieldErrors` cases)                 |
| `cd apps/main && pnpm test -- --run`                                  | 497 / 497 pass                                                    |
| `cd apps/main && pnpm typecheck`                                      | 0 errors                                                          |
| `cd apps/admin && pnpm typecheck`                                     | 0 errors                                                          |
| `cd apps/main && pnpm lint`                                           | 0 errors (2 pre-existing v-html warnings unrelated to this chunk) |

End-to-end live-stack verification against the local `php artisan serve` dev server:

```bash
# Empty payload
$ curl -s -b $JAR -H "Origin: http://127.0.0.1:5173" -X POST \
    http://127.0.0.1:8000/api/v1/agencies/<ulid>/brands \
    -H "Content-Type: application/json" -d '{}' -w "\nHTTP %{http_code}\n"
# Returns envelope: errors[0..N] with /data/attributes/name + /data/attributes/slug pointers.
```

---

## Files touched

**Backend (production):**

- `apps/api/app/Core/Errors/ValidationExceptionRenderer.php` — new.
- `apps/api/bootstrap/app.php` — registered global renderer + imports.

**Backend (tests):**

- `apps/api/tests/Pest.php` — `assertEnvelopeValidationErrors` macro.
- `apps/api/tests/Unit/Core/Errors/ValidationExceptionRendererTest.php` — new (5 cases).
- `apps/api/tests/Feature/Modules/Brands/BrandCrudTest.php` — 1 migration.
- `apps/api/tests/Feature/Modules/Identity/SignUpTest.php` — 7 migrations.
- `apps/api/tests/Feature/Modules/Identity/PasswordResetTest.php` — 3 migrations.
- `apps/api/tests/Feature/Modules/Identity/VerifyEmailTest.php` — 1 migration.
- `apps/api/tests/Feature/Modules/Identity/ResendVerificationTest.php` — 1 migration.
- `apps/api/tests/Feature/Modules/Agencies/InvitationTest.php` — 1 migration.
- `apps/api/tests/Feature/Modules/Agencies/AgencySettingsTest.php` — 2 migrations.

**Frontend (production):**

- `packages/api-client/src/errors.ts` — `extractFieldErrors` helper.
- `packages/api-client/src/index.ts` — export.
- `apps/main/src/modules/brands/components/BrandForm.vue` — `fieldErrors` prop + on-submit slug fallback.
- `apps/main/src/modules/brands/pages/BrandCreatePage.vue` — envelope introspection + per-field passthrough.
- `apps/main/src/modules/brands/pages/BrandEditPage.vue` — envelope introspection + per-field passthrough.

**Frontend (tests):**

- `packages/api-client/src/errors.spec.ts` — 6 new cases for `extractFieldErrors`.

**Docs:**

- `docs/reviews/sprint-3-chunk-5-review.md` — this file.
- `docs/tech-debt.md` — one new entry (Laravel-default-exception coverage gap).
