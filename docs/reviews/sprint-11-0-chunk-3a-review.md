# S11.0 — Notification subsystem · Chunk 3a Review — Notification center + badge + poll + render (frontend)

**Status:** Closed. Spot-check passed (no PMC) — the fallback exercises both distinct code paths (in-union-unmapped + not-in-union, type-safe via the widened-string signature over a Partial<Record>); the agency-row render test carries the positive `.toBe` assertion (a silently-empty binding fails); the free-text relocation renders + is null-safe; the poll cleanup + visibility-pause are proven; the layout-spec live-poll side effect was stubbed inert before commit.

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted.

**Reviewed against:** the S11.0 Chunk-3a kickoff (D-1…D-8) + the approved plan (Q1/Q2/Q3 + the visibility-pause addition), the Ch1/Ch2 reviews (the four `/me/notifications` endpoints + the `NotificationResource` shape + the per-type `data` contract), `PROJECT-WORKFLOW.md §5` (5.13 module-api, 5.15 localStorage ratchet, 5.16 v-slot).

This is the **first user-visible surface** of the notification subsystem: a shared, shell-agnostic center (dropdown + paginated page) in both shells, the first `<v-badge>` in the codebase, a steady-poll composable, and a client-side localized renderer driven by a structural template allowlist. Read-only consumption — preferences (read + write + UI) are Ch3b.

## Decisions (built as planned)

- **D-1 · One shared `NotificationCenter`** in a new `apps/main/src/modules/notifications/` module, mounted branch-free in both shells (the user-agnostic API needs no `currentAgencyId`). Not `@catalyst/ui` (HTTP-aware).
- **D-2 · Bell → `v-menu` dropdown (recent slice, `per_page=8`) + a `/notifications` full page** (paginated, `meta.last_page`). `v-list` + `v-pagination` (no `v-data-table` → no §5.16 waiver).
- **Q1 · Two routes** — `/notifications` (agency, `requireAuth`+`requireAgencyUser`) + `/creator/notifications` (creator, `requireAuth`), both → `NotificationsPage.vue` → the same `<NotificationCenter variant="page" />`. Mirrors the `/dashboard` vs `/creator/dashboard` split (layout dispatched off `route.meta.layout`).
- **D-3 · First `<v-badge>`** — `:model-value="count > 0"` (hidden at 0), mounted between `<v-spacer/>` and the user-menu in both app-bars (only `viewAllRoute` differs).
- **D-4/Q3 · `useNotificationPoll`** — flat `NOTIFICATION_POLL_INTERVAL_MS = 45000`, refs-only (no `localStorage`, §5.15), `onBeforeUnmount(cancel)`, **tab-visibility gating** (pause reschedule while hidden, immediate refetch on return).
- **D-5 · Optimistic + poll-reconciler** — `applyMarkRead` (−1, floored), `applyReadAll` (0); each feed fetch pushes `meta.unread_count → poll.set()`; the 45s poll is authoritative.
- **D-6 · Template only the 8 live types + fallback** — `notificationTemplateKey(type)` map (unmapped → fallback) is the structural only-8 allowlist; each template binds only its emit-site keys; `notifications.*` en/pt/it with the parity + "exactly 8 + fallback" invariant test.
- **D-7 · Deep-link deferred** — row click marks read in place, no navigation (tech-debt logged).

## Approved deviations

- **Free-text relocated to a labelled detail line.** `feedback`/`rejection_reason` render via `detailText()` (gated `typeof === 'string' && !== ''`) as a secondary "Feedback:"/"Reason:" line rather than interpolated into the title — keeps titles short and binding-safe; `draft_approved`'s `feedback: null` produces no orphan label; no data loss. Approved.
- **No-store reconcile path.** The page variant doesn't share the bell's poll handle (a shared store would trip §5.15); a page mark-all reconciles the app-bar badge via the bell's own ≤45s poll, not instantly. Accepted as the honest shape against the ratchet — a sub-45s badge lag after an explicit mark-all is a non-issue. Do not add a store.

## Spot-check anchors → evidence

| Anchor                           | Evidence                                                                                                                                                                                                  |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fallback = two distinct paths    | Separate `it()`s for `payment_funded` (in-union, unmapped) + `'totally.unrecognised.future.verb'` (not in union); `Partial<Record>` lookup → `?? FALLBACK_KEY`, no throw; unit-pinned in the parity spec. |
| Agency-row binds only its keys   | `expect(body).toBe('Alex submitted a draft for Spring Launch.')` + `!includes('01CAMPAIGN')` + `!includes('{')` — silently-empty binding fails the `.toBe`.                                               |
| Free-text rendered + null-safe   | `detailText()` renders Feedback/Reason; `feedback: null` → `v-if` absent (no orphan label).                                                                                                               |
| Poll cleanup + visibility-pause  | `onBeforeUnmount` frozen-call-count proof + hidden→visible pause/resume test.                                                                                                                             |
| `{}`-tolerant `creator.approved` | Renders cleanly from empty `data`.                                                                                                                                                                        |
| Layout-spec side effect stubbed  | `CreatorDashboardLayout.spec` `vi.mock`s `notificationsApi` (bell inert); 6/6 green, no `AggregateError` noise.                                                                                           |

## Verification

main suite 104 files / 928 tests green (incl. the updated agency-routes-guard expected set + the stubbed layout spec); api-client 100 tests + typecheck; vue-tsc + ESLint clean.

## Out of scope

- **Ch3b:** prefs read endpoint + `PATCH /me/notification-preferences` + prefs UI (the first user self-write surface).
- **Deferred tech-debt:** deep-link-to-subject (no uniform `subject→route` map; agency rows lack an assignment route); the §945 banner repoint + bucket-c verb-mints (Ch2 entry); bespoke templates for the 7 emit-less types.
- **S13:** admin SPA center.

## Docs

`tech-debt.md` — deep-link-to-subject deferred (Ch3a-follow-up; trigger = an agency assignment-detail route or a per-type route map). The three notification entries stay in-progress (Ch3b closes the user-facing-surface portion; bucket-c verb-mints remain open). No `03-DATA-MODEL`/`tenancy.md` change (FE-only).

---

_Provenance: drafted by Cursor (S11.0 Chunk 3a build); merged + spot-checked by Claude (two-path fallback / positive agency-row assertion / null-safe free-text all verified at anchor; free-text relocation + no-store reconcile approved as deviations; layout-spec stub fixed pre-commit). No PMC._
