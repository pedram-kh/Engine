# Creator Profile everywhere — one dialog, four mount contexts (AH-080)

- **Status: Closed — approved.** Full loop — kickoff with locked decisions (D1–D5) → plan-pause
  (five proposals ratified) → build → two-commit pair, push held → this close.
- **Verdict (Pedram, 2026-08-18).** **D1–D5 verified as built.** **The §5.34 thin-mode proof
  accepted, with its withheld-vs-thin distinction ratified as the model** other dual-payload
  surfaces should follow: a full-mode payload that happens to carry no contact keys (a related but
  gated case) and a thin-mode payload (no relation at all) are different empty states for
  different reasons, and only the test suite that keeps them structurally separate — rather than
  collapsing both into "no contact block" — actually proves the distinction holds. **The
  fallback-sequence proof verified**: exactly one roster call, exactly one discover call, never a
  second roster attempt, and `assumeFull`'s short-circuit confirmed for the applicant contexts.
  **The D4 same-component citations verified** — `StarRatingInput`, `BlacklistCreatorDialog`,
  `rosterApi.updateRelation`/`.unblacklist` — imports read directly off the roster detail page, not
  re-implemented. **The D5 untouched-pin table verified**: all five pre-existing spec files
  (`BoardApplicationsColumn`, `BoardColumns`, `BoardCardDrawer`, `ApplicationsTab`,
  `CampaignDetailPage`) re-run green with their pre-existing cases intact, new cases additive only.
  **The i18n reuse overturn ratified as recorded** — `app.roster.*` read verbatim, net-new held to
  the two leaves actually asked for, flaky-10 checked against real per-locale MT rather than
  assumed. **The lazy-tab deviation ratified as recorded** — the Profile tab's fetch-on-first-
  activation departure from the drawer's eager idiom, reasoned and named rather than silently
  inconsistent. **The flake rider closed** — mechanism named (cold-compile of lazy routes under
  dev-mode Vite on constrained runners), timeout bump shipped separately from this chunk's diff, so
  a future flake reopens cleanly as its own finding rather than reopening this chunk. **LOW
  confirmed on exactly the forecast trigger** — the inventory named `avatar_url` on
  `CampaignAssignmentResource` as the one thing that would move #4 off NONE, and that is exactly
  what step 1 did; the named-ripple check (no exact-keyset pin exists on that resource) closes the
  loop rather than leaving it assumed.
- **Date:** 2026-08-18
- **Provenance:** built by Cursor directly against Pedram's kickoff (D1–D5 below, locked at kickoff
  time) and the five plan-pause proposals (i18n-reuse overturn, D2c, D3's lazy-tab deviation, no new
  Playwright leg, the flake disposition) — all ratified verbatim before any code was written; no
  separate independent-review round in this loop — reviewed and approved by Pedram directly,
  2026-08-18.
- **Flake commit:** `368970d4` —
  `fix(playwright): bump the roster row-click navigation assertion timeout to 15s` (ships outside
  this chunk's diff, per the plan-pause disposition — see [§11](#11-the-flake-rider)).
- **Feature commit:** `1754560e` —
  `feat(campaigns,boards,roster): creator profile everywhere (AH-080)`.
- **Docs commit:** `docs(reviews): AH-080 entry and review file for Creator Profile everywhere` —
  carries this file, so its own hash cannot be cited from inside it; see `git log` at the tip for
  the exact hash.
- **Evidence base:**
  [`admin-filter-profile-modal-richtext-inventory.md`](admin-filter-profile-modal-richtext-inventory.md)
  §0.1, I4.1–I4.3 — the roster-detail/discover resource survey, the AH-051 contact gate, the three
  mount-context carry-what-they-carry table, and the one-endpoint-per-context feasibility finding
  this chunk builds from.
- **§5.40 risk: LOW**, re-derived at build exactly on the inventory's forecast trigger (§0, row
  "#4"): _"LOW only if the kickoff widens the roster-detail gate or adds `avatar_url` to
  `CampaignAssignmentResource`."_ Step 1 did the latter — a creator-identity accretion (a signed,
  short-lived GET URL) on a live agency payload, named at kickoff as the one ripple to watch, not
  discovered afterward. No migration, no write path touched, no existing behaviour changed; see
  [§6](#6-the-one-named-ripple-avatar_url-on-a-live-payload).

---

## 1. What shipped, against the kickoff's D1–D5

| Decision | Asked                                                                                                                                       | Shipped                                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1**   | One `CreatorProfileDialog`, fed only by existing resources, full/thin payload modes, never a new endpoint.                                  | Held. Split into `CreatorProfileContent.vue` (the body — reusable inline or in a dialog) + `CreatorProfileDialog.vue` (the `v-dialog` shell), so the identical markup renders in a modal (D2a/D2c) or inline inside an existing `v-window-item` (D2b) without nesting dialogs. Two resources only: `rosterApi.show` (full) and `discoveryApi.show` (thin) — see [§2](#2-the-two-payload-modes-and-the-534-thin-mode-proof). |
| **D2**   | Four mount contexts: (a) Creators-tab rows + avatar, (b) board drawer Profile tab, (c) application pseudo-card + tab row, (d) nothing else. | Held, all four. See [§3](#3-the-four-mount-contexts).                                                                                                                                                                                                                                                                                                                                                                       |
| **D3**   | Mode selection via try-roster/404-fallback-discover, no console-spray, no double-fetch, `assumeFull` for guaranteed relations.              | Held. `useCreatorProfile` composable — see [§4](#4-d3--the-fallback-sequence-proof).                                                                                                                                                                                                                                                                                                                                        |
| **D4**   | Rating/notes + blacklist render in full mode only, using the SAME wired components as the roster detail page, not copies.                   | Held — see [§5](#5-d4--reuse-verification-the-same-components-not-copies).                                                                                                                                                                                                                                                                                                                                                  |
| **D5**   | No behaviour change to the cards/rows themselves — drag, actions, accept/reject, statuses untouched; profile access is additive UI.         | Held — see [§7](#7-d5--the-pins-re-run-green-untouched).                                                                                                                                                                                                                                                                                                                                                                    |

---

## 2. The two payload modes, and the §5.34 thin-mode proof

`useCreatorProfile.ts` resolves a creator into exactly one of two shapes, both already-shipped
resources:

- **FULL** — `AgencyCreatorDetailResource` via `rosterApi.show(agencyId, creatorUlid)`, when a
  relation row exists (any status `AgencyCreatorRelationGuard::requireExisting` accepts).
- **THIN** — `CreatorPublicProfile` via `discoveryApi.show(agencyId, creatorUlid)`, the truthful
  fallback for a creator this agency has never had a relation with. Renders **Profile + Socials
  only** — no contact, no account-creation, no rating/notes, no blacklist section, ever.

**§5.34 — the dialog renders zero contact section on a thin payload**, pinned in
`CreatorProfileContent.spec.ts` (`§5.34 — renders ZERO contact section, ZERO account section, ZERO
rating/notes, ZERO blacklist on a thin payload`) by asserting every one of those five
`data-test` blocks is absent from the DOM on a thin-mode mount, plus a companion test asserting
the honest thinness notice (`app.creatorProfile.thinNotice`) renders in its place — no empty
contact skeleton.

**The server-side citation this client-side pin rests on:**
`apps/api/tests/Feature/Modules/Creators/ContactDetailsWithholdingTest.php:71-78` —
_"`CreatorPublicProfileResource` (discover detail) withholds the contact details"_ — asserts the
resource backing thin mode structurally carries none of the four contact keys
(`phone`/`whatsapp`/`address_street`/`address_postal_code`), and its own docblock (`:36-38`) names
it the **break-revert artifact**: add `phone` to that resource and this spec fails. Re-ran clean
this session: **11 passed, 71 assertions** (full file, not the filtered slice).

A **full**-mode payload can _also_ carry no contact block — a related-but-withheld case (e.g. a
`pending_request` relation, or the AH-051 contact gate closing on a blacklisted-rostered creator).
`CreatorProfileContent.spec.ts` keeps these structurally distinct: `hides the contact block on a
FULL payload with no contact keys (withheld, not thin)` proves the component reasons from
"did the server ship the keys," not "is this thin mode" — the two are different empty states for
different reasons, and only one of them shows the thinness notice.

---

## 3. The four mount contexts

### (a) Campaign Creators-tab rows

`CampaignDetailPage.vue` — every assignment row in the Creators tab gained a `v-avatar`
(`#prepend` slot, signed `avatar_url` with an initial fallback — the AH-075 precedent) and a row
`@click` that opens `CreatorProfileDialog` with `assumeFull: false` (an assignment's creator is
not guaranteed rostered — the roster relation could have ended since the assignment was made, so
the 404-fallback stays live here). Every existing action button
(`reinvite`/`attach-contract`/`proceed-without-contract`/`review`/`resolve`/`view-post`) gained
`@click.stop`, so an action click never also opens the profile dialog (D5).

### (b) Board assignment cards — drawer Profile tab

`BoardCardDrawer.vue` gained a fifth tab, `Profile`, alongside Drafts/Messages/Details/History (the
AH-072 tab pattern). **Deliberate divergence from the drawer's eager idiom, ratified at
plan-pause:** every other tab's data loads eagerly on drawer open; Profile is lazy — it mounts
`CreatorProfileContent` (inline, no nested `v-dialog` — the drawer already is one) only once
`profileActivated` flips true on the tab's first activation, and stays mounted (never
re-fetches) across later re-visits within the same drawer instance. Reasoning recorded in the
component's own docblock (`BoardCardDrawer.vue:659-664`): an optional fifth tab layered on an
already-two-request drawer open (assignment detail + movements) earns its keep by not adding a
third request nobody asked to see yet.

### (c) Application pseudo-cards + Applications-tab rows

Both hosts — the board's `BoardApplicationsColumn.vue` pseudo-column and the campaign-detail
`ApplicationsTab.vue` — gained a **clickable identity block** (avatar + name, wrapped in a
`role="button"` element with `tabindex`/Enter/Space handling for keyboard access) that opens
`CreatorProfileDialog` with **`assumeFull: true`**. An applicant is rostered by definition
(applying requires the relation), so the composable is told to skip the 404-fallback dance
entirely — **one fetch, not two-then-maybe-more**. Deliberately **not** wrapping the whole card:
Accept/Reject remain their own click targets, with no change to their handlers or the dialogs
they open (D5).

### (d) Nothing else this chunk

Roster (`CreatorDetailPage.vue`) and Discover (`DiscoverProfilePage.vue`) keep their existing
dedicated pages, untouched — the dialog is new UI for the three contexts that had no profile
access at all, not a replacement for the two that already did.

---

## 4. D3 — the fallback-sequence proof

`useCreatorProfile.spec.ts` (7 tests) pins the exact contract the kickoff asked for:

- **Happy path, full:** `resolves FULL on a roster hit — discover is never called`.
- **Fallback, thin:** `falls back to THIN on a roster 404 — exactly one roster call, exactly one
discover call`.
- **No re-attempt:** `never re-attempts roster after the fallback — one of each, never two roster
calls` — the mechanism a naive retry-loop bug would violate.
- **`assumeFull` short-circuit:** `assumeFull skips the fallback — a roster 404 surfaces as a real
error, discover never called` — the D2c contract for applicants.
- **Non-404 errors never fall back:** `a non-404 roster error never falls back and classifies as
load-failed` — a 500 is a real error, not a mode signal.
- **Independent second-failure classification:** `a discover-side failure after a roster 404
classifies independently` — a 500 from discover after a 404 from roster is `load-failed`, not
  `not-found`.
- **No state leakage:** `resets state on each load — a stale profile/error never leaks into the
next call`.

`CreatorProfileContent.spec.ts` re-proves the same sequence at the component boundary
(`FULL mode … resolves via roster only, renders the profile + name, and never calls discover`;
`fallback mechanics … assumeFull (applicant context, D2c) skips the fallback dance — one fetch, no
wasted 404 call to discover`; `a genuine 404 on BOTH endpoints surfaces the not-found error, never
a phantom profile`). Neither branch logs to the console on the expected-and-common no-relation
case — the fallback is silent by design (`useCreatorProfile.ts:24-25`).

---

## 5. D4 — reuse verification: the SAME components, not copies

`CreatorProfileContent.vue` imports, and directly wires:

- `StarRatingInput` (`@/modules/roster/components/StarRatingInput.vue`) — the identical rating
  input `CreatorDetailPage.vue` uses, bound to the same `internal_rating` field.
- `BlacklistCreatorDialog` (`@/modules/roster/components/BlacklistCreatorDialog.vue`) — mounted
  with `:has-relation="true"`, the same component `CreatorDetailPage.vue` opens for its own
  blacklist action.
- `rosterApi.updateRelation` for the rating/notes save, and `rosterApi.unblacklist` for lifting a
  blacklist — the same `roster.api.ts` client functions, not parallel HTTP calls.

All four gated on `isFull.value && canEdit.value` (admin/manager only — the same role gate the
roster detail page enforces), and absent entirely in thin mode (`v-if="isFull"` /
`v-if="isFull && canEdit"` on the two `v-card` blocks). `CreatorProfileContent.spec.ts` proves both
the editable and read-only paths (`D4 — shows the rating/notes EDITOR for admin/manager, wired to
rosterApi.updateRelation`, `D4 — renders rating/notes READ-ONLY for staff (no editor)`,
`D4 — shows blacklist management for admin/manager, wired to rosterApi.unblacklist`,
`hides blacklist management from staff`) and the resources agree structurally: full mode's
`AgencyCreatorDetailResource` carries `internal_rating`/`is_blacklisted`; thin mode's
`CreatorPublicProfile` carries neither key at all — nothing to rate against a relation that
doesn't exist.

---

## 6. The one named ripple: `avatar_url` on a live payload

Step 1 added `creator.avatar_url` to `CampaignAssignmentResource` (a signed, 60-minute GET URL via
the same `AwsS3V3Adapter::temporaryUrl` pattern the roster/discover resources already use) so the
Creators-tab row (D2a) and the two application surfaces (D2c) can render a real photo instead of an
initial. **Checked for an exact-keyset pin covering this resource — none exists**: a repo-wide
search for `CampaignAssignmentResource` inside `apps/api/tests` finds only
`ContactDetailsWithholdingTest.php` (a withholding assertion, not a full-keyset snapshot), and
`apps/api/tests/Feature/Modules/Campaigns/` has no test enumerating the resource's complete
attribute set. The named ripple didn't materialize — recorded as checked, not assumed.

`CampaignAssignmentResolutionTest.php` gained one new case:
`the Creators-tab listing exposes creator.avatar_url (AH-080, null under the non-S3 test disk)` —
asserting `null` under the test environment's local disk (the same posture every other
`avatar_url`-bearing resource's test suite takes, since `AwsS3V3Adapter::temporaryUrl` only exists
on the real S3 driver). `packages/api-client/src/types/campaign.ts`'s `CampaignAssignmentResource`
type gained `avatar_url?: string | null` as optional, documented as back-compat for call sites
whose eager-load doesn't select it (single-object mutation responses).

---

## 7. D5 — the pins re-run green untouched

All four surfaces this chunk touches carry pre-existing negative/behavioural pins from earlier
chunks (AH-059's no-drag negatives, AH-072's drawer tab specs, the Creators-tab action-button
specs). Re-run after this chunk's changes, named rather than assumed:

| Spec file                         | Result                                  | What it protects                                                                                                                                                                                                                                                                            |
| --------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BoardApplicationsColumn.spec.ts` | **17 passed** (14 pre-existing + 3 new) | The AH-059 §5.34 no-drag negatives (`contains NO draggable and NO drag handle`), Accept/Reject dialog wiring, the dual refetch — all untouched; 3 new: profile-dialog wiring + two D5 no-cross-trigger cases.                                                                               |
| `BoardColumns.spec.ts`            | **11 passed**                           | The board/applications-column drag-group separation — untouched, no new cases needed (nothing here mounts the profile dialog).                                                                                                                                                              |
| `BoardCardDrawer.spec.ts`         | **34 passed** (30 pre-existing + 4 new) | Drafts/Messages/History tab behaviour, the review-from-drawer flow, the ability gates — untouched; 4 new: the Profile tab's lazy-activation contract (no mount on open, mounts on first activation with the right props, stays mounted across re-visits, no mount for a null-creator card). |
| `ApplicationsTab.spec.ts`         | **14 passed** (11 pre-existing + 3 new) | The AH-058 badge-count/reject-confirmation/refusal-surfacing pins — untouched; 3 new: profile dialog from both halves of the identity block, and the D5 no-cross-trigger case.                                                                                                              |
| `CampaignDetailPage.spec.ts`      | **41 passed** (38 pre-existing + 3 new) | The jobs-board toggle, proceed-without-contract, and tab-mounting pins — untouched; 3 new: avatar rendering, row-click opens the dialog, and the D5 append-button no-cross-trigger case.                                                                                                    |

One expected console note, not a regression: `CampaignDetailPage.spec.ts`'s D5 append-click test
mounts `CreatorProfileDialog` for real (not stubbed) on the row-click branch of the same test,
producing the same benign unmocked-`fetch` `AggregateError` console noise already accepted for
`ReinviteDialog` in this file — the assertion under test (no dialog opens on the append click)
still passes cleanly.

---

## 8. What this chunk deliberately did not do

- **No new Playwright leg.** Conscious, recorded deferral (plan-pause proposal, ratified). D5 makes
  every mount point additive-only, so Vitest carries the full correctness weight — the §5.34
  contact-absence proof, the fallback sequence, and the reuse imports are all Vitest-level
  assertions, not E2E ones. The existing `creator-detail.spec.ts` roster-row-to-detail leg and the
  board/applications Playwright legs (c4/c5) continue to pass through pages this chunk touches
  without a dedicated Profile-tab click step.
- **No new endpoint.** Both payload modes read resources that shipped before this chunk
  (`rosterApi.show`, `discoveryApi.show`); the only server-side change is the one named ripple in
  [§6](#6-the-one-named-ripple-avatar_url-on-a-live-payload).
- **No duplicate i18n namespace.** See [§9](#9-i18n--the-reuse-overturn).
- **No index change** — this chunk adds no query, correlated or otherwise; nothing here follows
  AH-079's tech-debt shape.

---

## 9. i18n — the reuse overturn

**Plan-pause overturn, ratified:** `CreatorProfileContent.vue` reads `app.roster.*` keys **verbatim**
for every section header and field label (`app.roster.detail.sections.profile`,
`app.roster.fields.country`, `app.roster.blacklist.section.title`, etc.) — the cross-module `t()`
norm already established elsewhere (`BoardCardDrawer.vue` itself reads
`app.campaigns.tabs.drafts`). No parallel `creatorProfile.*` namespace was created to duplicate
words that already exist and are already translated in all 24 locales.

**Net-new copy is exactly two leaves, ×24 locales = 48 strings:**

| Key                                       | Purpose                           |
| ----------------------------------------- | --------------------------------- |
| `app.campaigns.board.drawer.tabs.profile` | The new fifth tab's label (D2b).  |
| `app.creatorProfile.thinNotice`           | The honest thin-mode notice (§2). |

The tab label reused each locale's existing translation of "Profile" (already present elsewhere in
every locale's `app.json`) rather than re-translating a one-word string; the thinness line is real
per-locale MT. **Flaky-10 spot-check** (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) — all ten carry
distinct, non-English copy for both leaves (see the table below for two representative locales;
the full ten were checked, not sampled from a subset).

| Locale | `thinNotice` (truncated)                                                  | `tabs.profile` |
| ------ | ------------------------------------------------------------------------- | -------------- |
| en     | "This is a limited profile — your agency has no roster relationship…"     | Profile        |
| ga     | "Is próifíl theoranta í seo — níl caidreamh rosta ag do ghníomhaireacht…" | Próifíl        |
| ro     | "Acesta este un profil limitat — agenția dvs. nu are încă o relație…"     | Profil         |

`tests/unit/architecture/i18n-locale-parity.spec.ts` (keyset + placeholder + plural-form parity
across all 24 locales) passed as part of the full suite run — see [§10](#10-gate-board).

---

## 10. Gate board

| Gate                                                                       | Result                                                                                                                                                                                                               |
| -------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full suite                                                 | **2529 passed, 1 skipped** · 9387 assertions                                                                                                                                                                         |
| `CampaignAssignmentResolutionTest.php` (targeted)                          | **10 passed** · 37 assertions, incl. the new `avatar_url` case                                                                                                                                                       |
| `ContactDetailsWithholdingTest.php` (targeted — the §5.34 server citation) | **11 passed** · 71 assertions                                                                                                                                                                                        |
| Backend Campaigns-scoped feature filter                                    | **563 passed** · 2388 assertions                                                                                                                                                                                     |
| PHPStan (project-wide, `--memory-limit=1G`)                                | **0 errors** (919 files)                                                                                                                                                                                             |
| Pint (project-wide)                                                        | **passed**                                                                                                                                                                                                           |
| Frontend (Vitest), full `apps/main` suite                                  | **1593 passed / 162 files**                                                                                                                                                                                          |
| `useCreatorProfile.spec.ts` (new)                                          | **7 passed**                                                                                                                                                                                                         |
| `CreatorProfileContent.spec.ts` (new)                                      | **12 passed**, incl. the §5.34 zero-contact case                                                                                                                                                                     |
| `CreatorProfileDialog.spec.ts` (new)                                       | **4 passed**                                                                                                                                                                                                         |
| `CampaignDetailPage.spec.ts` (D2a additions)                               | **41 passed** (3 new)                                                                                                                                                                                                |
| `BoardCardDrawer.spec.ts` (D2b additions)                                  | **34 passed** (4 new)                                                                                                                                                                                                |
| `BoardApplicationsColumn.spec.ts` (D2c additions)                          | **17 passed** (3 new)                                                                                                                                                                                                |
| `ApplicationsTab.spec.ts` (D2c additions)                                  | **14 passed** (3 new)                                                                                                                                                                                                |
| `BoardColumns.spec.ts` (D5 pin, unmodified)                                | **11 passed**                                                                                                                                                                                                        |
| `i18n-locale-parity.spec.ts` (`apps/main`)                                 | **passed** — keyset, placeholder, and plural-form parity across all 24 locales, incl. the 2 new leaves                                                                                                               |
| `api-client` (`tsc --noEmit`)                                              | **clean**                                                                                                                                                                                                            |
| `vue-tsc --noEmit` (`apps/main`, project-wide)                             | **clean**                                                                                                                                                                                                            |
| ESLint (`apps/main`, project-wide, `--max-warnings=0`)                     | **2 pre-existing `vue/no-v-html` warnings, unrelated to this chunk's files** (`ClickThroughAccept.vue`, `ProfileBasicsForm.vue`) — 0 new                                                                             |
| Prettier (touched files)                                                   | **clean** (auto-fixed once during build)                                                                                                                                                                             |
| Playwright                                                                 | **No new leg** — conscious deferral, see [§8](#8-what-this-chunk-deliberately-did-not-do). `creator-detail.spec.ts` timeout fix ships as its own commit (see [§11](#11-the-flake-rider)), outside this chunk's diff. |

---

## 11. The flake rider

`creator-detail.spec.ts`'s roster-row-to-detail navigation assertion had two consecutive
first-attempt failures (local, post-AH-078; and CI run `32110681822`), both post-dating the
AH-071–078 touches to that surface. **Disposition: environmental, mechanism named** — cold-compile
of lazy routes under dev-mode Vite on constrained runners, not a real navigation-timing bug in the
app or the spec's wait strategy. Fix: bumped the `toHaveURL` assertion's timeout from the default
5s to 15s at `creator-detail.spec.ts:71`. **Shipped as its own one-line commit**, `368970d4` —
`fix(playwright): bump the roster row-click navigation assertion timeout to 15s` — outside this
chunk's diff, per the plan-pause disposition. The rider is closed; a third flake after this bump
reopens it as a real finding, not a retry-and-shrug.
