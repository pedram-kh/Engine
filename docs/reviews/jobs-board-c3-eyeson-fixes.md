# AH-056 eyes-on fixes — inventory report (read-only)

- **Date:** 2026-07-27 (same session as the AH-056 close + push)
- **Status:** report only. **No docs edited, nothing committed, nothing pushed.**
- **Closed review this hangs off:** [`jobs-board-c3-review.md`](jobs-board-c3-review.md) (Closed — approved)

---

## 0. Two corrections to the request's premise, stated first

**There are no commits after `978bce5`.** `git log --oneline 978bce5..HEAD` returns **nothing**.
`HEAD` and `origin/main` are both `978bce5` — the AH-056 close commit. The fixes below exist only as
**uncommitted working-tree modifications**, so:

- **The tree is NOT clean.** 54 modified files, 0 untracked, 0 staged.
- **Nothing is pushed.** The ruling does not need to adjust for follow-up-vs-amend: there is no
  commit yet to amend or follow. Whatever shape you rule, it can be authored directly.

**The changes were made by Cursor in-session, not by Pedram.** Pedram ran the eyes-on and reported
three findings from a real phone and a real desktop; each fix below was then built, tested and
break-reverted in this session on his confirmation (he was asked to pick the shape before each one
and did). Recording that because "Pedram made minor changes" would misattribute the diff.

### Working-tree inventory, grouped

| Group                              | Files                                                    | Lines           |
| ---------------------------------- | -------------------------------------------------------- | --------------- |
| Fix 1 — mobile bottom bar          | `CreatorDashboardLayout.vue`, `.spec.ts`                 | +263 / −8       |
| Fix 2 — job detail card            | `CreatorJobDetailPage.vue`, `.spec.ts`                   | +163 / −108     |
| Fix 3 — campaigns Job board column | `CampaignListPage.vue`, `.spec.ts`                       | +47 / −0        |
| i18n                               | `availability.json` ×24 (1 key), `app.json` ×24 (2 keys) | 72 leaves added |

**Backend: zero diff.** `git diff -- apps/api packages/api-client` is empty, both against the
working tree and across `978bce5..HEAD`. No migration, no resource, no controller, no enum, no
route, no mailable, no flag, no api-client type. Everything below is `apps/main` only.

---

## 1. Per-fix breakdown

### Fix 1 — the mobile bottom bar clipped two tabs

**(a) As observed.** Eyes-on step: Pedram opened the creator shell on his phone over the LAN
(`192.168.1.133:5173`) and screenshotted the bottom navigation. "Dashboard" was clipped to "board"
on the left edge and "Messages" to "Mess" on the right. Six tabs, a viewport's worth of room for
about five.

**(b) Root cause — a regression AH-056 introduced, in an untested seam.** `v-bottom-navigation`
neither wraps nor scrolls, and every `navItems` entry was handed to it directly, so the bar's width
grew with the section count. An approved creator had five items before AH-056 (which just fit) and
**six** after "Job Posts" was added. Two compounding causes worth separating:

1. **No pin existed.** During S9 a mobile bottom-bar test was written and then dropped, because
   Vuetify's `useDisplay()` reads `window.innerWidth`, which jsdom fixes at 1024 — the mobile chrome
   never rendered under test, so the assertion was vacuous. Dropping it rather than solving it is
   what let a layout regression reach a phone. **This is the honest root cause: not "we didn't
   think of it" but "we knew it was unpinned and shipped anyway."**
2. **The design had no ceiling.** Even with five items the bar was one addition from breaking, and
   chunks 4–5 will add sections. A count-dependent bar is a latent bug regardless of AH-056.

**(c) The fix.** Four permanent slots (`dashboard`, `jobs`, `assignments`, `messages` — the working
loop) declared as `MOBILE_PRIMARY_KEYS`, plus a **"More" menu** carrying the remainder (`profile`,
`availability`). The bar's width is now independent of how many sections exist, and a newly added
item lands in More by default rather than breaking the row. Labels also ellipsis on one line: four
slots stop the bar overflowing, but a single long label still bursts its own slot, and English is
the short case — the same keys run to `Табло за управление` (bg, 19 chars) and
`Postijiet tax-xogħol` (mt, 20).

Files: `CreatorDashboardLayout.vue`, `CreatorDashboardLayout.spec.ts`, `availability.json` ×24
(`creatorNav.more`).

**(d) Risk questions.**

| Surface                      | Touched?                                                                                                                                                                                                       |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Six-leg predicate            | **No** — `apps/api` zero diff.                                                                                                                                                                                 |
| Apply path                   | **No.**                                                                                                                                                                                                        |
| Fan-out / stamp / cap / flag | **No.**                                                                                                                                                                                                        |
| Exact-keyset brand subset    | **No** — the resources are untouched; this file never renders brand data.                                                                                                                                      |
| `listed_at` semantics        | **No.**                                                                                                                                                                                                        |
| Any §5.34-pinned behaviour   | **No** — the seven-case set is server-side; the client branch here is the `applicationStatus === 'approved'` UX gate, which is **unchanged** (still hides Job Posts for non-approved, still not the boundary). |
| Notification / mail path     | **No.**                                                                                                                                                                                                        |
| i18n keyset                  | **YES** — 1 key ×24 added (`availability.creatorNav.more`). Additive only; nothing renamed or removed.                                                                                                         |

### Fix 2 — the job detail page had no frame

**(a) As observed.** Eyes-on step: Pedram browsed board → job detail on desktop and noted the
detail page had no border, unlike "the other pages". Correct: the list page's job cards are
`v-card variant="outlined"`, so clicking a bordered card landed on an unframed page.

**(b) Root cause — cosmetic, and a house-pattern miss rather than a bug.** The page rendered bare
`<section>` elements with no container. The precedent it should have followed exists and is
unambiguous: `BrandDetailPage.vue:110` puts the back link and heading outside, then wraps the whole
body in one card. No test could have caught this — no assertion in the codebase describes "a detail
page is framed", and inventing one after the fact is exactly what happened (see §3).

**(c) The fix.** The job body moved into one `v-card variant="outlined"` (the list page's border, so
the two jobs surfaces read as one), back link kept outside at page level, and Apply moved into
`v-card-actions` as the card's action. The skeleton and both failure alerts stay **outside** the
card deliberately: a tonal alert inside a bordered card double-frames the message. Two supporting
CSS rules — `align-self: stretch` (the page column is `align-items: flex-start`) and the 16px column
rhythm the sections used to inherit directly from the page.

Files: `CreatorJobDetailPage.vue`, `CreatorJobDetailPage.spec.ts`. **No i18n change** — every string
reuses an existing key.

**(d) Risk questions.**

| Surface                      | Touched?                                                                                                                                                                                                                                                                                                                                         |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Six-leg predicate            | **No.**                                                                                                                                                                                                                                                                                                                                          |
| Apply path                   | **Rendering only — the LOGIC is byte-identical.** The Apply button, its `:disabled="!canApply"` binding, `openDialog()`, the dialog, `submitApplication()`, the two 409 branches and the apply-time-404 branch are all unchanged; only the button's DOM position moved into `v-card-actions`. `git diff` on the `<script setup>` block is empty. |
| Fan-out / stamp / cap / flag | **No.**                                                                                                                                                                                                                                                                                                                                          |
| Exact-keyset brand subset    | **No.** This page RENDERS the three permitted brand fields (name, logo, website) and continues to render exactly those three; the emission is decided server-side by `CreatorJobDetailResource`, which is untouched, and the two exact-keyset pins are backend tests, also untouched.                                                            |
| `listed_at` semantics        | **No** — the detail page does not render the recency chip.                                                                                                                                                                                                                                                                                       |
| Any §5.34-pinned behaviour   | **No.** The 404 fail-closed path is unchanged; the new spec asserts the 404 alert renders **and** that no card wraps it.                                                                                                                                                                                                                         |
| Notification / mail path     | **No.**                                                                                                                                                                                                                                                                                                                                          |
| i18n keyset                  | **No.**                                                                                                                                                                                                                                                                                                                                          |

### Fix 3 — the campaigns table did not show listing state

**(a) As observed.** Not a bug — a gap Pedram spotted while looking at the agency campaigns table
after listing "vawe 1": nothing on the row said whether a campaign was on the jobs board. The one
listed campaign was indistinguishable from the unlisted one.

**(b) Root cause — a genuine AH-054/AH-056 scope gap, not a defect.** `listed_on_jobs_board` has
been emitted by `CampaignResource` since AH-054 and typed in
`packages/api-client/src/types/campaign.ts:87` since then; the index endpoint uses that resource. So
the flag has been arriving on every table row all along and nothing rendered it. Neither chunk put a
read surface on the agency side — chunk 2 shipped the toggle on the Settings tab only, and chunk 3
was the creator half by definition. Nothing was wrong; something was missing.

**(c) The fix.** A "Job board" column between Status and Budget. A tonal chip reading "Listed" when
the flag is true; a muted dash otherwise, so the eye scans for the exception rather than parsing two
chips per row. Its **own column rather than a second chip in Status**, because listing is orthogonal
to lifecycle — a campaign can be active-and-unlisted or active-and-listed — and conflating them is
what makes a later "only listed" filter awkward.

Files: `CampaignListPage.vue`, `CampaignListPage.spec.ts`, `app.json` ×24
(`campaigns.fields.jobBoard`, `campaigns.listing.listed`).

**(d) Risk questions.**

| Surface                      | Touched?                                                                                                                                                                                                   |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Six-leg predicate            | **No** — this reads a column off an already-emitted resource.                                                                                                                                              |
| Apply path                   | **No.**                                                                                                                                                                                                    |
| Fan-out / stamp / cap / flag | **No.**                                                                                                                                                                                                    |
| Exact-keyset brand subset    | **No** — agency-side table, no brand fields beyond the name it already showed.                                                                                                                             |
| `listed_at` semantics        | **No, and deliberately so.** The column reads `listed_on_jobs_board` (the authority), NOT `listed_at` (display metadata). `listed_at` is not even emitted by `CampaignResource` — see the owed item in §3. |
| Any §5.34-pinned behaviour   | **No.**                                                                                                                                                                                                    |
| Notification / mail path     | **No.**                                                                                                                                                                                                    |
| i18n keyset                  | **YES** — 2 keys ×24 added. Additive; the existing `fields.listedOnJobsBoard` ("Add to jobs board") is untouched and still the form label.                                                                 |

---

## 2. Test-gap closure

| Fix                  | Should a test have caught it?                                 | Pin added                                                | Verdict                                            |
| -------------------- | ------------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------- |
| 1 — bottom bar       | **Yes, and one had been written and dropped.**                | **7 tests** in `CreatorDashboardLayout.spec.ts` (9 → 16) | **Closed, including the reason it was droppable.** |
| 2 — detail card      | **No** — no assertion in the codebase describes page framing. | 1 test (12 → 13)                                         | **Was eyes-only; now pinned anyway.**              |
| 3 — Job board column | **No** — a missing feature, not a broken one.                 | 1 test (4 → 5)                                           | **N/A → pinned.**                                  |

**Fix 1, what closed the hole properly.** The jsdom blocker is solved, not worked around:
narrowing `window.innerWidth` **before the Vuetify instance is created** puts `smAndDown` on, so the
mobile chrome renders under test. `VMenu` also had to stop being stubbed to nothing (it swallowed
its own activator, and the "More" button lives in that slot) — a passthrough stub keeps both slots
inline, the same approach the apply-dialog spec takes with `VDialog`. The seven tests pin: the
five-button ceiling, which four keys hold slots, that the union of bar + sheet is the whole nav
(nothing unreachable), that a named slot actually resolved, the shrink case for a non-approved
creator, the localized "More", and that the bar stays off desktop.

**Two break-reverts prove they bite.** Growing the primary list to six reds 6 tests. Renaming a nav
key out from under the policy list — the silent failure mode, where a slot quietly empties — reds 6
including the assertion written for exactly that. Both reverted; `git diff` on the subjects is clean.

**Fix 3's break-revert:** forcing the chip to always render reds the new test with
`expected true to be false`. Reverted.

### The one hole left open, named

**The mobile bottom bar has no E2E coverage at a phone viewport.** Playwright runs at desktop width,
where `v-bottom-navigation` never renders — which is precisely why the full 24/24 suite was green
while the bar was visibly broken on Pedram's phone. The seven new unit tests now cover structure at
390px in jsdom, but **no gate renders the bar in a real browser at a real phone width**, so a purely
visual regression (a label overflowing its slot despite the ellipsis, a tap target too small) would
still be eyes-only.

**Verdict: owed, small, and not blocking these fixes.** The honest options are a mobile-viewport
Playwright project (a `devices['iPhone 13']` entry plus one leg that visits the shell and asserts
every bottom-nav item is inside the viewport) or an explicit accept-as-eyes-only entry in
`tech-debt.md`. I'd take the first — it is roughly one config block and one assertion, and it closes
the exact class of bug this session opened with. **Recommending, not deciding.**

### Second owed item, surfaced by fix 3

`CampaignResource` does not emit `listed_at`, although the column exists and AH-056 writes it. The
agency therefore cannot see _when_ a campaign was listed, only _that_ it is — while creators see
"Listed today / N days ago" from the same column. Not a defect (the flag is the authority and the
chip is honest), but an asymmetry worth recording: one resource line plus a resource-emission test
closes it. Pedram was offered this as part of fix 3 and chose the boolean-only shape, so it is a
**deliberate deferral**, not an oversight.

---

## 3. Gates

One 2d answer is **yes** on two fixes (i18n keyset), and no 2d answer is yes on any
predicate/apply/fan-out/keyset/`listed_at`/§5.34/mail surface. Gates run accordingly.

### Zero-diff confirmations (the "if yes" clause, discharged)

```
$ git diff --stat -- apps/api packages/api-client
(empty)

$ git diff --stat 978bce5..HEAD -- apps/api packages/api-client
(empty)
```

Every break-revert subject from the closed review, checked individually — **all zero diff**:
`JobsBoardVisibility.php`, `JobPostedFanOutService.php`, `Campaign.php`,
`AdminFeatureFlagController.php`, `modules/creators/routes.ts`, `modules/auth/routes.ts`,
`creator-routes-guard.spec.ts`.

Because the backend is untouched, the §5.34 seven-case set, the two exact-keyset pins, the
apply-time re-validation, the no-re-apply pin, the §5.6 race, the cap/once-only/dry-run evidence and
the flag-OFF no-op are all **unchanged at the byte level**. No re-run can tell us anything a zero
diff does not, so the backend board was not re-run on that basis. Say the word if you want it run
regardless as a belt.

### Board on touched packages

| Gate                                | Result                                                                           |
| ----------------------------------- | -------------------------------------------------------------------------------- |
| `vitest` (apps/main, full)          | **1287 passed** / 139 files (was 1278 at close — +9: 7+1+1)                      |
| `vue-tsc --noEmit` (apps/main)      | **clean**                                                                        |
| `eslint` (apps/main)                | **0 errors** (the 2 pre-existing `v-html` warnings)                              |
| `i18n-locale-parity.spec.ts`        | **green** — 24 locales, both moved namespaces                                    |
| `CreatorDashboardLayout.spec.ts`    | 16 passed                                                                        |
| `CreatorJobDetailPage.spec.ts`      | 13 passed                                                                        |
| `CampaignListPage.spec.ts`          | 5 passed                                                                         |
| `apps/admin`, `packages/api-client` | **not re-run — zero diff** (`app.json` is per-app; the admin bundle is separate) |
| Backend `pest` / PHPStan / Pint     | **not re-run — zero diff** (last green at close: 2234 passed, 0 errors, clean)   |

**Locale parity detail.** Both keysets moved additively and symmetrically across all 24 bundles, so
the parity spec's key-parity, placeholder-parity and plural-form-count checks all stay green. Real
MT baseline in every locale including the flaky 10, per the AH-046/047 ruling — spot values:
`more` = `Още` (bg) / `Tuilleadh` (ga) / `Aktar` (mt); `jobBoard` = `Bord tax-xogħol` (mt) /
`Álláshirdetések` (hu) / `Darba piedāvājumi` (lv); `listed` = `Δημοσιευμένη` (el) /
`Liostaithe` (ga) / `Zverejnené` (sk).

### Playwright — NOT re-run, and why, plus the exposure

**One E2E-traversed surface changed structurally: the job detail page** (fix 2). `creator-jobs-board.spec.ts`
is the only spec touching any of these three surfaces, and it drives the detail page through
`creator-job-detail-name`, `-description`, `creator-job-website`, `creator-job-apply`,
`creator-job-apply-note`, `-submit`, `creator-job-snackbar`, `creator-job-applied-notice` and
`creator-job-detail-applicants`. **Every one of those `data-testid`s still exists and still resolves
to the same element** — the card wrap moved them in the DOM without renaming or removing any, and
the locators are attribute-based, not structural. So the leg is expected to pass.

Expected, not verified — and I did not run it for one reason worth stating rather than hiding:
`playwright.config.ts` sets `reuseExistingServer: false` and `global-setup.ts` runs an unconditional
`migrate:fresh`, so the suite requires the dev stack **down**. Pedram is mid-eyes-on with the stack
up and reachable from his phone; killing it silently to run a suite I expect to pass would interrupt
active manual testing. **This is the one gate the report leaves open.** It is ~4 minutes plus a
restart and health-check, and I'll run it on your word — before any commit, not after.

Fixes 1 and 3 have **no** E2E exposure: the bottom bar does not render at Playwright's desktop
width, and no spec traverses the campaigns table.

---

## 4. Docs-shape recommendation

**Recommend: a standalone `AH-057` change-log entry, plus a short dated addendum on the closed
review. Not a fixes-note buried under AH-056.**

Reasoning, and the case against each alternative:

**Why not a post-close addendum alone.** An addendum is right for _corrections to the reviewed
work_ — AH-051's addendum is the precedent, and it carried fixes to behaviour the review had
described. Two of these three are not that. Fix 3 is a **new agency-facing read surface** that
neither chunk 2 nor chunk 3 scoped, and fix 1 changes the creator shell's **navigation
architecture** — a ceiling on the bottom bar that binds every future section. Burying an
architectural decision inside another chunk's addendum is how the next person fails to find it when
chunk 4 adds a nav item.

**Why not fold it into AH-056.** AH-056 is pushed and its review is Closed — approved. The house
rule that older entries are "deliberately not retro-edited" exists precisely so a closed record
can't drift. Reopening it to absorb three post-close UI changes would blur what was reviewed from
what came after.

**Why AH-057 fits the house pattern exactly.** All three are pure-UI, `apps/main`-only, no schema,
no API, no contract change — which is the **AH-007 pattern** (build it, log it, done; no full loop),
and the AH-055 entry three days ago is the same shape: a UI-only fix found by Pedram in eyes-on
minutes after a push, logged as its own entry rather than amended into the chunk it corrected.
AH-055 is the direct precedent, and it points at a separate entry.

**Proposed shape, for your ruling:**

1. **`AH-057` in `adhoc-changes-log.md`** — one entry, three fixes, stating plainly that fix 1 is a
   regression AH-056 introduced and that the bar had been knowingly left unpinned; that fix 2 is a
   house-pattern miss with no test that could have caught it; and that fix 3 is a scope gap in the
   arc's agency half, deliberately shipped boolean-only.
2. **A dated addendum on `jobs-board-c3-review.md`**, original text untouched, ~6 lines: the bottom
   bar regression and its pin, and a pointer to AH-057. The review's Verdict and Production posture
   stay exactly as approved — nothing in them became false.
3. **`tech-debt.md`** — the mobile-viewport E2E gap, unless you rule the Playwright mobile project
   in as part of AH-057 (my lean), in which case there is nothing to log.
4. **No `RESUMPTION-TEMPLATE.md` deploy-note change.** These carry no migration, no flag, no
   one-shot command, and no queue dependency. The Part 2 push-state block needs one line moved from
   "nothing is held" once AH-057 is committed and before it is pushed.
5. **Commit shape:** one `fix(creators)`-style commit for fixes 1+2 (creator shell + creator page),
   one `feat(campaigns)` for fix 3 (it adds a column, not fixes one), then the docs commit. Three
   commits, because "fix" and "feat" should not share a subject line.

**Not done, awaiting the ruling:** no docs file edited, nothing staged, nothing committed, nothing
pushed. This report is the only new file.

---

## Closing note — 2026-07-27, after the ruling

_Appended after the fact so this file is not read as current. The report above is the state at the
time of the ruling; two of its statements were superseded by what came next._

**§3's "Playwright — NOT re-run" no longer holds.** The full suite was taken down, run, and
health-checked after: **25/25 in 4.2m**, 24 desktop plus the new mobile leg.

**§2's "one hole left open" is closed, by the first option.** The `devices['iPhone 13']` project was
ruled in as part of AH-057, scoped to one spec. It runs on the Chromium **engine** — Playwright's
WebKit build for macOS 14 is frozen and bus-errors on launch on this host — so it carries the phone
profile, not the phone browser, which is what a layout-geometry assertion needs.

**And fix 1 was not actually complete when this report called it complete.** The E2E leg's first run
found a **residual 5px overhang**: Vuetify's `min-width: 80px` lives at
`.v-bottom-navigation .v-bottom-navigation__content > .v-btn`, and the override was written at a
shorter selector depth, which ties on specificity and loses on order. Five 80px floors make a 400px
row inside a 390px viewport, so the bar still overhung 5px each side and scrolled. No structural
assertion could see it (the labels were inside the frame) and neither could the eye. That is the
strongest available argument for the project this report only recommended: it found, on its first run,
a defect in the fix that opened the session.

**§2's second owed item stands as recorded** — `CampaignResource` still does not emit `listed_at`,
now ruled a deliberate boolean-only choice rather than a deferral, with no tech-debt entry.

The AH-057 entry in [`adhoc-changes-log.md`](adhoc-changes-log.md) and the dated addendum at the foot
of [`jobs-board-c3-review.md`](jobs-board-c3-review.md) are the current record.
