# Sprint 6 — Chunk 2a Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** The first **agency-side per-creator detail view** — reached by clicking a roster row (the `D-c5-4` reversal). A new agency-scoped detail endpoint + a dedicated detail resource; the **rating/notes edit** the roster shipped read-only (write endpoint + `update` policy ability + admin/manager gate + a **redacted** notes-audit event); a new `apps/main` detail page re-composing `@catalyst/ui` primitives; the read-only **agency availability calendar** consuming the Sprint-5 endpoint (closing its consumer loop); and **honest empty states** for the two data-blocked sections (social metrics, campaign history).

**Out of scope (unchanged):** talent pools + "add to pool" → **Chunk 2b**; blacklist **editing** → Sprint 7 (status displayed read-only here); real social metrics → social adapters (empty state); real campaign history → Sprint 8 (empty state). The `platform_admin` admin detail endpoint is untouched.

**Reviewed against:** `02-CONVENTIONS.md` (modular monolith, ULID, tenancy), `docs/security/tenancy.md` (relation-exists scope), `05-SECURITY-COMPLIANCE.md` §3.3 (audit / GDPR free-text redaction), `07-TESTING.md` §5.17 (defense-in-depth) + §5.35 (break-revert), the locked decisions **D-2a-1…D-2a-10**, and the Chunk-2 read-pass inventory.

---

## Divergences from the kickoff (all surfaced at plan-pause, all approved)

1. **Dedicated agency-availability FE type (not a "reason-optional" change).** Rather than loosen `AvailabilityOccurrenceAttributes.reason` to optional, a **dedicated** `AgencyAvailabilityOccurrenceAttributes` was added where `reason` is structurally **absent**. Mirrors the backend's dedicated `AgencyAvailabilityResource`; the creator-self path's `reason` guarantee is untouched.
2. **Star-rating control co-located in the roster module**, not promoted to `@catalyst/ui`. Net-new, single-SPA; light/button-based (no `v-rating`) so it unit-tests under jsdom. Promote on a second consumer.
3. **Signed-URL helper duplicated, not refactored.** `CreatorResource::signedViewUrl()` is private; the ~12-line S3-or-null mint is duplicated in the new resource. One creator, no list → trigger #1 (N+1) stays clear.
4. **`blacklist_reason` withheld.** Free-text GDPR-sensitive (same data class as `internal_notes`, which D-2a-5 redacts). Only the structured blacklist facts (flag / scope / type / date) ship; the justification text never leaves the backend.
5. **PATCH scope guard = "ignored" by construction.** The request validates only `internal_rating` + `internal_notes`; the controller `array_intersect_key`s to exactly those two keys, so a stray blacklist/counter/`relationship_status` field has no path to the model (no error — silently ignored).

**Notes-audit pin (the spot-check anchor):** the redacted `agency_creator_relation.notes_updated` event fires **only when `internal_notes` actually changed** (compared before the save) — never on a rating-only edit — and carries **empty `before`/`after` + no notes content**. `internal_rating` edits keep the `Audited` trait's normal allowlisted before/after diff.

---

## What was built

### Backend (Agencies + Audit modules)

- **Detail endpoint + relation-exists tenancy (D-2a-1).** `AgencyCreatorDetailController::show` + `update` under `auth:web → tenancy.agency → tenancy`. `requireRosterRelation()` 404s unless an `AgencyCreatorRelation` (any status) exists between agency + creator — mirrors `AgencyCreatorAvailabilityController` exactly. Routes `agencies.creators.show` (GET) + `agencies.creators.update` (PATCH) in `Agencies/Routes/api.php`.
- **Dedicated detail resource (D-2a-2).** `AgencyCreatorDetailResource` wraps the **relation** (rating, notes, read-only blacklist status, counters) + the nested **creator profile** (display fields, signed avatar/cover URLs, contact **email** per D-2a-8, social **accounts**, portfolio). **No** admin-only KYC PII; **no** `blacklist_reason`.
- **Write endpoint + policy + gate (D-2a-3/4).** `UpdateAgencyCreatorRelationRequest` (rating 1–5 nullable, notes nullable, both `sometimes`); `AgencyCreatorRelationPolicy::update` added (admin/manager via `hasAnyRole`, mirroring `BrandPolicy`). Read stays any-member (`viewAny`).
- **Redacted notes audit (D-2a-5).** New `AuditAction::AgencyCreatorRelationNotesUpdated`. The controller emits it via `AuditLogger::log()` (actor + subject + timestamp; empty before/after; no metadata) **only on an actual notes change**. Rating diffs remain the trait's auto-logged `agency_creator_relation.updated`.

### Frontend (`apps/main`, roster module + api-client)

- **Detail page (D-2a-7/8/10).** `CreatorDetailPage.vue` re-composes `CategoryChips` / `CountryDisplay` / `LanguageList` / `SocialAccountList` / `PortfolioGallery` / `CEmptyState` — **not** the admin page. Header surfaces the contact email as a `mailto:` link. Rating/notes **editor** (admin/manager) vs **read-only** display (staff). Honest empty states for social metrics + campaign history; social **accounts** still render.
- **Read-only agency calendar (D-2a-9).** `AgencyAvailabilityCalendar.vue` reuses the `CMonthGrid` primitive + the pure `datetime.ts` bucketing/tz helpers (NOT the creator-coupled `AvailabilityCalendar.vue`), behind a new `agencyAvailability.api.ts` wrapper. No add button, no dialog, no click-to-create; `reason` structurally absent.
- **Star-rating input.** `StarRatingInput.vue` — real `<button>` radios + `mdi-star`; click sets, re-click clears to null; `readonly` renders plain icons.
- **Row navigation + route + arch-test (D-2a-6).** `roster.detail` (`/roster/:ulid`) added with `requireAuth → requireAgencyUser`; the roster table `@click:row` pushes to it keyed on `creator_id`; the `agency-routes-agency-user-guard.spec.ts` expected set grew by `roster.detail`.
- **Types + i18n.** `AgencyCreatorDetailResource` / `…Profile` / `UpdateAgencyCreatorRelationPayload` / `AgencyAvailability*` in `api-client`; `app.roster.detail.*` strings in **en/pt/it** (28 keys, parity-checked).

---

## Coverage (§5.17; break-revert §5.35)

**Backend — `tests/Feature/Modules/Agencies/AgencyCreatorDetailTest.php` (18 tests):**

- 401 unauthenticated; 404 non-member; **404 no-relation** (break-revert: drop `requireRosterRelation` → an agency reads a non-related creator); reads across roster/prospect/external.
- Resource carries profile + relation + portfolio + social; **surfaces email** (D-2a-8); **does NOT carry admin KYC PII** (break-revert: `admin_attributes`/`kyc_method`/`verified_by_user_id`/`kyc_verifications` absent); blacklist **status shows but `blacklist_reason` is withheld** (break-revert: the reason text never appears).
- Admin + manager can edit; **staff 403** (break-revert: the policy gate); **only rating+notes mutable** — a blacklist/counter/`relationship_status` field in the payload is ignored (break-revert: the scope guard); out-of-range rating → 422.
- Rating edit emits the trait before/after diff; **notes edit emits the redacted event with NO content** (break-revert: assert the row exists AND contains no notes text); **rating-only edit emits NO notes event** (the pin).

**Frontend:**

- `StarRatingInput.spec.ts` (4) — select / clear-on-reselect / fill / readonly-no-buttons.
- `CreatorDetailPage.spec.ts` (8, heavy children stubbed) — agency+ulid-scoped load; email surface; **two blocked empty states**; **admin/manager editor vs staff read-only** (D-2a-4); save threads rating+notes; cleared notes → `null`; 404 not-in-roster message.
- `CreatorRosterPage.spec.ts` — **row-click navigates** to `roster.detail` keyed on the creator ulid (D-2a-6).
- `playwright/specs/creator-detail.spec.ts` — heavy detail DOM + the **live CMonthGrid calendar** + row-nav + the rating/notes edit round-trip (admin), per the Chunk-1 jsdom/Playwright split.
- Arch-test: `agency-routes-agency-user-guard.spec.ts` expected set updated (break-revert: drop the guard → the test fails).
- `AuditActionEnumTest.php` catalogue updated to include `agency_creator_relation.notes_updated`.

---

## Gate results

- **Backend:** `AgencyCreatorDetailTest` 18/18 green; `tests/Feature/Modules/{Agencies,Audit}` 188 passed (1 pre-existing skip). `composer pint --test` passed; `composer stan` (Larastan L8) — **No errors**.
- **Frontend:** `pnpm --filter @catalyst/main test` — **709 passed**; `vue-tsc --noEmit` clean; `eslint` clean (only 2 pre-existing `v-html` warnings, unrelated). `@catalyst/api-client typecheck` clean. i18n en/pt/it `app.roster.detail.*` parity verified (28 keys each).
- **Playwright** (`creator-detail.spec.ts`) is written; it runs in the `e2e-main` job against the live stack (not executed in this local pass).

---

## Spot-check anchors

- Relation-exists tenancy on the detail endpoint (break-revert).
- The resource excludes admin-only KYC PII (break-revert) **and** `blacklist_reason` (divergence 4).
- Rating/notes edit gated admin/manager; staff 403 (break-revert).
- Only rating+notes mutable; blacklist/counters not (break-revert).
- The notes-edit audit event is **redacted** (no content) and fires **only on an actual notes change** (break-revert + the pin).
- The availability calendar reuses `CMonthGrid` read-only + the agency wrapper; `reason` absent.
- Email surfaced as the deliberate privacy decision (D-2a-8).
- Blocked sections show empty states; row-nav route carries `requireAgencyUser` (arch-test updated).

---

## Docs follow-up

- **`tech-debt.md`:** added a CLOSED entry — the Sprint-5 agency availability read endpoint now has its consumer (the detail page), and the dedicated agency-availability FE type (divergence 1) recorded as the chosen approach over a "reason-optional" loosening.
- **`services.md`:** no change.
- The `requireAgencyUser` arch-test set growing by `roster.detail` is code, not docs.

---

## Commit plan (two-commit pair, not committed until spot-check)

1. `feat(agencies): per-creator detail endpoint + resource + rating/notes edit with redacted notes audit` — controller, resource, request, policy `update`, `AuditAction` case, routes, backend tests + catalogue update.
2. `feat(main): agency per-creator detail page + read-only availability calendar + row navigation` — api-client types, `roster.api`/`agencyAvailability.api`, `StarRatingInput`, `AgencyAvailabilityCalendar`, `CreatorDetailPage`, route + row-click + arch-test, i18n (en/pt/it), FE specs + Playwright, tech-debt note.
