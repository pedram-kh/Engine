# AH-088 — Creator date of birth → age everywhere

- **Status: Closed — approved, pushed.** Full loop: read-only inventory
  ([`creator-dob-inventory.md`](creator-dob-inventory.md), commit `04a2ecec`) → kickoff with locked
  decisions (D1–D8) → plan-pause (five questions, O1–O8 ratified) → build (S1–S10) → gate board →
  **pushed**.
- **Date:** 2026-08-27. **Risk:** PROD-DATA LOW, as re-derived at plan-pause and confirmed in §5.
- **Provenance:** kickoff decisions D1–D8 locked by Pedram; the plan-pause's five questions (Q1–Q5,
  each an O-numbered overturn/extension) answered and ratified by Pedram before any code was
  written; built by Cursor. **Closed by Pedram**, verdict: D1–D8 + O1–O8 verified as ruled; the
  [§2](#2-the-eight-surfaces-and-what-each-one-gets) eight-surface emission table accepted as the
  controlled-emission record; the D5 companion spec confirmed **stricter than the existing
  AH-005 contact-withholding spine** — proven, not assumed, by the [§7.1](#71-the-break-revert-priority-1)
  break-revert, which the contact spec's own suite did not catch; the [§6](#6-the-projection-trap-o3--the-one-that-would-have-shipped-silently)
  projection trap closed with an endpoint-level assertion per list rather than a resource-direct one;
  the [§5](#5-production-posture--what-happens-to-the-279-live-creators) cohort posture accepted as
  stated — the ~20-point score consequence, the unknown-age exclusion, and the rollback note are all
  plainly recorded rather than softened; the `CreatorReopenTest` catch ([§7.3](#73-gate-board--every-ci-gate-run-locally-at-tip))
  credited to running the full board, not the targeted one; the Postgres re-verification
  ([§7.3b](#73b-the-postgres-cross-check-8s-divergence-actually-verified)) accepted as the standard
  for any future cross-driver claim in this codebase, not a one-off for this chunk.
- **Evidence base:** the inventory file above (the pre-build read pass this was built from); the
  gate board in [§7.3](#73-gate-board--every-ci-gate-run-locally-at-tip)–[§7.3b](#73b-the-postgres-cross-check-8s-divergence-actually-verified)
  (full local suite, both SPAs, Playwright, and a second full pass of the AH-088 specs against real
  Postgres); the break-revert in [§7.1](#71-the-break-revert-priority-1); the two unpredicted
  findings in [§8](#8-two-things-the-plan-did-not-predict); the [AH-088 adhoc-log entry](adhoc-changes-log.md)
  and the [deploy-log PENDING entry](../runbooks/deploy-log.md) this review feeds.

---

## 1. What this is, in one paragraph

Creators can now enter a date of birth. Nobody else can — not an admin, not an agency — and nobody
else ever sees it. What everyone else sees is a **derived age**, emitted explicitly on eight
surfaces. The birthday joined the profile-completeness **floor**, and an inclusive age-range filter
joined the shared creator-column filter trait, giving the agency roster an age filter and a matching
age column.

The chunk's whole point is **controlled emission**: one nullable column whose raw value has exactly
one legitimate reader (its owner), and one derived value that travels freely. Most of the decisions
below are about keeping that line sharp under future edits, not about the column itself.

---

## 2. The eight surfaces, and what each one gets

| #   | Surface                                                   | `age` | `date_of_birth`      |
| --- | --------------------------------------------------------- | ----- | -------------------- |
| 1   | `CreatorResource` — **owner** (`GET /creators/me`)        | yes   | **yes** (only place) |
| 2   | `CreatorResource` — **admin** drill-in                    | yes   | no                   |
| 3   | Admin creator **index** card                              | no    | no                   |
| 4   | `AgencyCreatorDetailResource` — roster detail             | yes   | no                   |
| 5   | Roster **list** row (`AgencyCreatorController`)           | yes   | no                   |
| 6   | `CreatorPublicProfileResource` — discover detail          | yes   | no                   |
| 7   | `CreatorDiscoveryResource` — discover card                | yes   | no                   |
| 8   | `TalentPoolMemberResource` / `CampaignAssignmentResource` | no    | no                   |

Row 4 carries the deliberate asymmetry with AH-005: a rostered, non-blacklisted agency sees the
creator's **phone number** and still never sees their birthday. Contact details have an
agency-facing mode; the birthday has none, on any surface, in any mode. Anyone later "aligning" the
DOB withholding spec with the contact spec's exemptions would be removing the guarantee this chunk
exists to provide, which is why that sentence is in the spec file itself and not only here.

---

## 3. Decisions

| ID     | Decision                                                               | Why not the alternative                                                                                                                                                                                                                                                                                     |
| ------ | ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1** | One resource, two audiences, **one conditional key** (`! isAdminView`) | The alternative was a second owner-only resource plus a second hydration path — more structure to withhold one field. `CreatorResource`'s docblock forbade per-audience fields; it is now amended **in place** to record this single named exception and to hold any future one to the same bar.            |
| **D2** | `age` is a plain `Creator::age(): ?int`                                | An Eloquent accessor with `$appends` would push age into every implicit `toArray()` — including surfaces this chunk never audited. There are also zero accessors in the codebase; derived values are methods. D2's original word "accessor" is recorded as meaning _derivation_, not the Laravel mechanism. |
| **D3** | A native `type="date"` input                                           | The `VDatePicker` precedent is availability-specific (scanning a calendar for free days). A birthday is _typed_, and native keyboard year entry beats paging a calendar back three decades. Six other sites already use native date inputs.                                                                 |
| **D4** | Age renders on five profile surfaces + the roster list                 | See §2. The roster list was added during the plan-pause (Q3) — filtering a list by a column it does not display is a support ticket.                                                                                                                                                                        |
| **D5** | Withholding is **pinned**, not assumed                                 | A new companion spec (`DateOfBirthWithholdingTest`) covers 8 wire surfaces + the message-thread value check + the audit-allowlist absence + the D1 conditional in **both** directions. §7 records the break-revert that proves it bites.                                                                    |
| **D6** | `date_of_birth` joins the completeness **floor** (six fields → seven)  | The floor is what makes the field actually get filled. The cost is real and stated in §5: every live creator is floor-incomplete on arrival. The alternative (optional-field credit only) leaves the platform with a field nobody fills and an age nobody can filter on.                                    |
| **D7** | The roster gets the age filter UI; discover does not                   | Discover's filter row is already dense and its card is single-line by design. But see Q1 — the _API_ accepts the parameters on both endpoints, and that is tested rather than accidental.                                                                                                                   |
| **D8** | **No minimum-age rule anywhere**                                       | Offered and declined. The request rules carry sanity bounds only (`before_or_equal:today`, a 1900 floor to catch a mistyped year). A young date validates and saves. If a minimum-age policy is ever wanted, it is a separate decision with its own enforcement surface, not a side effect of a data field. |
| **Q1** | The age filter lives in the **shared trait**                           | The trait's own extraction criterion decides it: an age predicate is a pure `creators`-column predicate. Putting it on the roster controller reopens exactly the drift the trait exists to prevent. Consequence: discover accepts `age_min`/`age_max` with no UI — D7's fence is **UI-only**.               |
| **Q5** | Permissive read, **clamped** in code                                   | Identical behaviour on both endpoints; junk ignored, out-of-range clamped, no new FormRequest for discover. The asymmetric option (validate on roster, ignore on discover) would make the same parameter behave two ways — worse than either uniform choice.                                                |

---

## 4. Mechanism — the parts worth reading before editing

**The age rule, stated once.** Whole years elapsed, and a birthday **today** has already counted.
That rule appears in three places and they must agree:

1. `Creator::age()` — `date_of_birth->startOfDay()->diffInYears(today())`.
2. `FiltersCreatorColumns::applyAgeFilters()` — the SQL bounds (below).
3. `ProfileBasicsForm.vue`'s `previewAge` — the creator-facing "You'll appear as N".

The filter's algebra, so the next reader does not have to re-derive it:

```
age >= min  ⟺  date_of_birth <= today - min years
age <= max  ⟺  age < max + 1  ⟺  date_of_birth > today - (max + 1) years
```

Both boundaries are pinned at the exact edge in `AgencyCreatorRosterTest` — a creator shown as 25
must be returned by `age_min=25` **and** by `age_max=25`, and a creator who turns 25 tomorrow must
not be returned by `age_min=25`.

**`whereDate`, not `where` — and this is load-bearing.** The two drivers store this column
differently: Postgres holds a real `date` (`2001-08-27`), while SQLite receives the model's default
datetime format (`2001-08-27 00:00:00`). A raw string comparison against `'2001-08-27'` is therefore
**true on Postgres and false on SQLite at exactly the boundary**. This was found by the boundary test
failing, not by reading the code — see §8.

**Timezone.** The comparison anchor is `today()` under `APP_TIMEZONE=UTC`, and the frontend preview
anchors to UTC too so the two cannot disagree. A creator far east of UTC can see their age tick over
up to a day late. Accepted: a per-creator timezone is not data we hold.

**The audit trail is a leak path, and the exclusion is now a decision.** Audit snapshots are
admin-readable, so allowlisting `date_of_birth` would hand it to the one audience D1 withholds it
from. It was already excluded by default (the allowlist is opt-in), which is exactly the kind of
protection that evaporates the day someone adds a new column "for completeness". It is now excluded
**by pinned decision**: a documented block in `auditableAllowlist()`'s docblock, and a
source-inspection assertion that the block is still there — the `content_companions` precedent.

---

## 5. Production posture — what happens to the ~279 live creators

**One additive-nullable column. No backfill, no default, no index. Zero rows read or written by the
migration.** Every existing row reads NULL the moment it finishes.

**But the floor changed, and that has a cohort-wide consequence. Stated plainly:**

- Nobody has a birthday on the day this ships, so **every live creator is floor-incomplete at rest.**
- The floor is a single all-or-nothing block worth `PROFILE_FLOOR_WEIGHT = 13` of the profile unit's 25. Renormalized against the flags-OFF denominator (65), that is **≈20 points off the public
  completeness score of every creator who previously met the floor**, once
  `creators:recompute-completeness` runs at deploy.
- **Nothing is hidden, emptied, or locked.** Profiles render in full. What changes is that the next
  profile **SAVE** is gated until a birthday is entered — hard-block for pending/rejected, soft-warn
  for approved. That is the AH-026 D3 backfill-on-next-edit posture, at cohort scale, and it
  self-heals one creator at a time.

**Three things that could have made this dangerous, checked before shipping:**

| Fear                                                     | Reality                                                                                                                                   |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| A mass "your profile is incomplete" email to ~279 people | **No.** `IncompleteCreatorNudgeEligibility` keys off `application_status`, not the completeness score. No nudge cohort is created.        |
| Approved creators bounced back into the wizard           | **No.** The onboarding route guard keys off `application_status`, not `next_step`. An approved creator stays on their dashboard.          |
| An agency's roster looking broken                        | **Partly true, and named.** Any age bound excludes unknown-DOB creators — at deploy, all of them. See the trade below and `tech-debt.md`. |

**The unknown-age exclusion, said out loud.** SQL comparisons against NULL are never true, so ANY
age bound drops every creator without a birthday. The semantics are correct — an unknown age cannot
honestly satisfy "21–35" — and they are pinned by a test. But for the window before creators
backfill, an agency who touches either age input sees an empty roster with no explanation. An
"include unknown age" toggle is the **named follow-up, not built**: it is a third filter state on a
surface already carrying seven filters, and its value decays to zero as the backfill completes. Both
this and the missing index are `tech-debt.md` entries with explicit triggers.

**The rollback note that matters.** `down()` is an honest inverse today (the column is net-new and
holds no pre-existing data). After creators start entering birthdays, rolling back **destroys them**,
and the recomputed scores become stale in the opposite direction — a second recompute is required
after any rollback.

---

> **Addendum — 2026-08-27, AH-089 (post-close, AH-046 pattern: nothing above is edited, this stands
> beside it).** The **≈20-point** figure above assumed the floor's score block stays all-or-nothing
> once it reaches seven fields — true when this section was written, no longer true. AH-089 makes
> `PROFILE_FLOOR_WEIGHT` (13) graduated: earned as `13 × (present floor fields ÷ 7)`, not 13-or-0.
> Riding the **same** pending deploy and the **same** one required
> `creators:recompute-completeness` run, the realized outcome for this cohort is **≈2.9 visible
> points per missing floor field, not ≈20** — a creator previously at 100 lands at **≈97** (missing
> DOB only), not ≈80. The gate this section also describes (`isProfileComplete()`, the hard-block /
> soft-warn save behaviour, the self-heal-on-next-edit posture) is **unchanged** by AH-089 — only the
> score's arithmetic softened. Full mechanism, decisions, and evidence:
> [`graduated-floor-scoring-review.md`](graduated-floor-scoring-review.md).

---

> **Addendum — 2026-08-30, AH-096 (post-close, AH-046 pattern: nothing above is edited, this
> stands beside it).** Eyes-on on the agency roster page (`/roster`) found the D7 age-range
> filter a **dead affordance**: setting `age_min=4`/`age_max=7` still showed an 8-year-old
> creator. Kin to the [§6 projection trap](#6-the-projection-trap-o3--the-one-that-would-have-shipped-silently)
> in shape (a real filter that silently never reached the query) but a different layer entirely —
> not a missing column, a missing **wire**. `CreatorRosterPage.vue` set `params.age_min`/`params.age_max` on the object it passed to
> `roster.api.ts::list()` (correctly, since the page was built against this section's own D7),
> but the wrapper's `URLSearchParams` builder never read either key — the two filter values were
> silently dropped between the page and the HTTP request, so `AgencyCreatorController` /
> `FiltersCreatorColumns::applyAgeFilters()` (both exactly as designed at §Q1/§Q5 above) never
> received them and the endpoint ran unfiltered. The backend this section documents was never
> wrong; nothing reached it.
>
> **Why nothing here caught it:** `CreatorRosterPage.spec.ts` mocks `roster.api.ts` wholesale and
> asserted the PAGE called `rosterApi.list()` with `age_min`/`age_max` in the params object — true,
> and provided zero information about what actually left the browser. The backend suite
> (`AgencyCreatorRosterTest`, cited at §2/§7 above) is airtight on the endpoint's own filtering
> logic but is never exercised by the frontend's own request-building code, so a wrapper that
> drops a param before the request is sent is invisible to it by construction. This is the
> **mock-boundary lesson**: a page spec that asserts params into a mocked collaborator proves the
> page's own logic, never the wire.
>
> **Fix + pin:** `roster.api.ts::list()` now threads both bounds into the query string
> (independently — `age_min` alone is deliberately not fenced together with `age_max`, per this
> section's own D7 semantics). Pinned three ways: a wrapper-level Vitest spec asserting the REAL
> `URLSearchParams`-level query string (not a mocked call argument); a new source-inspection
> architecture test in both SPAs (`api-wrapper-params-serialized.spec.ts`) that harvests every
> `*Params` interface + its consuming wrapper method and fails if any declared field is never
> referenced in the method body — the class-killer, verified to catch this exact bug by reverting
> the fix and confirming it reds with the precise two missing fields; and a new Playwright leg on
> `roster-search-and-affordances.spec.ts` seeding two real creators at different ages and asserting
> the age-filtered table narrows for real — the roster age filter's first live-browser exercise; it
> had none before. (This section's own [§9 follow-ups](#9-follow-ups-named-not-built) item 3, discover's still-missing
> filter UI, is untouched by this fix — that gap is unchanged and remains intentional, not a bug.)
>
> **Class sweep:** every other `*Params`-typed list wrapper in both SPAs (roster, discover, files,
> campaigns × 3, availability × 2, invitations, notifications, talent pools × 2, members, jobs,
> brands, and every admin-SPA equivalent — audit, creators × 3, impersonation, agencies × 2) was
> audited by hand and is now guarded by the same structural test; **no second instance of this
> class was found**. Full table + fix + pins: [`adhoc-changes-log.md`](adhoc-changes-log.md)'s
> AH-096 entry.

---

## 6. The projection trap (O3) — the one that would have shipped silently

The roster list and the discover list both `SELECT` a **slim column set**, not `creators.*`. `age`
derives from `date_of_birth`, so without that column in the projection the model has no attribute to
derive from and **age reads null in production while every resource-direct test stays green** — a
resource constructed in a test hydrates a full model, so the projection is invisible to it.

Both projections gained the column, and — because the comment alone would not have caught a
regression — **each list now asserts age through the ENDPOINT**:

- `AgencyCreatorRosterTest` — the row-shape test seeds a birthday and asserts `age` is 29 in the
  response.
- `AgencyCreatorDiscoveryTest` — a dedicated test asserting `age` is 31 through `GET .../discover`.

Delete either projection line and those two red. Nothing else in the suite would notice.

---

## 7. Evidence

### 7.1 The break-revert (priority 1)

**Probe.** Emit `'date_of_birth' => $creator->date_of_birth?->toDateString()` from
`AgencyCreatorDetailResource::mapCreator()` — the single most plausible accidental leak, since that
resource already has a gated contact block and reads like the natural home for "more creator data".

**Result: 5 cases red**, including `the roster detail withholds the birthday even in the
contact-included full mode` and all four non-roster statuses. **Reverted.**

**The part worth noting:** `ContactDetailsWithholdingTest` stayed **green** throughout. The AH-005
spine does not and cannot catch a DOB leak — which is the proof that the companion spec earns its
place rather than duplicating an existing guard.

### 7.2 The exact-keyset red (O6), let to happen deliberately

`AgencyCreatorDiscoveryTest`'s canonicalized card-keyset assertion is the one pin in the suite that
reds on **any** new card key. Adding `age` to the discovery resource made it red immediately — the
free proof that the key actually arrived on the wire. It was then updated deliberately, with a
comment recording that this same pin is what would catch a raw birthday arriving on that card.

### 7.3 Gate board — every CI gate, run locally at tip

| Gate                                    | Result                                                             |
| --------------------------------------- | ------------------------------------------------------------------ |
| Pint (backend lint)                     | pass — see the note below                                          |
| Larastan level 8                        | **OK, no errors** (947 files)                                      |
| **Pest — FULL suite**                   | **2721 passed, 0 failed** (10,381 assertions, 129s)                |
| `lint:frontend` (ESLint ×2)             | 0 errors (2 pre-existing `v-html` warnings, neither on a new line) |
| `typecheck:frontend` (vue-tsc/tsc ×5)   | Done — all five packages                                           |
| Vitest — `apps/main`                    | **1654 passed** (167 files)                                        |
| Vitest — `apps/admin`                   | **469 passed** (55 files)                                          |
| Vitest — `packages/ui`                  | 43 passed                                                          |
| **Playwright `e2e-main`** (wizard spec) | **1 passed** — real Chromium, real Postgres                        |
| Prettier                                | clean on every file this chunk touched                             |
| i18n parity ×24 (both SPAs)             | green (within the `apps/main`/`apps/admin` runs)                   |
| floor-mirror parity (source pin)        | green at seven fields                                              |

Two notes, because neither was free:

- **Pint reformatted the migration** (anonymous-class brace position, and it hoisted the `{@see}`
  target into a real `use` import). Accepted as-is — that is the house formatter's call, not a
  judgement of mine.
- **`CreatorReopenTest` was the one fixture the sweep missed.** Its floor-complete creator had no
  birthday, so `POST /wizard/submit` began returning `409 creator.wizard.incomplete` — the new floor
  doing exactly its job, in a test that never mentions the profile step. It was found by the **full**
  suite, not by the affected-module runs, which is the argument for running the whole board rather
  than the modules touched. Fixed by seeding the date; a follow-up grep for any other
  `avatar_path`-bearing fixture without a DOB found only `ContentCompanionsTest`, which compares two
  creators' scores to each other and is therefore immune.

### 7.3b The Postgres cross-check (§8's divergence, actually verified)

The whole suite runs on SQLite, and §8's finding is precisely that this column behaves differently on
the two drivers — so a SQLite-green boundary test is not by itself evidence. The AH-088 specs were
therefore re-run against **real Postgres 17** (the Docker instance, a scratch database, since dropped):

| Run on Postgres                                                         | Result                      |
| ----------------------------------------------------------------------- | --------------------------- |
| `AgencyCreatorRosterTest` (all 48, incl. **both exact age boundaries**) | **48 passed**               |
| Withholding + discovery + `age()` boundaries + completeness             | **76 passed**               |
| The migration itself                                                    | ran in 10.42ms, no warnings |

That makes the `whereDate` fix a **verified** cross-driver equivalence rather than a reasoned one —
and it is the check that would have caught the bug in §8 pointing the other way, where CI is green
and production is wrong.

### 7.4 New tests

| File                                                 | What it pins                                                                                                       |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `DateOfBirthWithholdingTest.php` (**new**, 16 cases) | 8 wire surfaces, the roster full mode, the admin index card, the message thread, the audit allowlist, D1 both ways |
| `CreatorModelTest.php` (+5)                          | `age()` boundaries: null, whole years, birthday today, birthday tomorrow, a time component                         |
| `CompletenessScoreCalculatorTest.php` (+2)           | the DOB floor pair, mirroring the region pair                                                                      |
| `AgencyCreatorRosterTest.php` (+8)                   | the range, both exact boundaries, unknown-DOB exclusion, junk, clamping, reversed range, endpoint age              |
| `AgencyCreatorDiscoveryTest.php` (+2)                | discover accepts the params with no UI; age derives through the slim projection                                    |
| `CreatorProfilePage.spec.ts` (+1)                    | **the deploy posture**: a `date_of_birth: null` creator renders fully, blocks on save, self-heals                  |
| `Step2ProfileBasicsPage.spec.ts` (+5)                | the floor gate both ways, hydration, the age preview, the save, and the blocked-clear                              |
| `CreatorRosterPage.spec.ts` (+3)                     | independent bounds, URL resume + clamp, the age column                                                             |
| `creator-wizard-happy-path.spec.ts`                  | the DOB fill (now gating) + the live age preview in a real browser                                                 |

---

## 8. Two things the plan did not predict

**1. The cross-driver date comparison.** The plan assumed a plain `where('date_of_birth', '<=', …)`.
The exact-lower-bound test failed, and the cause was the storage-format divergence in §4: SQLite
keeps `'2001-08-27 00:00:00'` where Postgres keeps `2001-08-27`. Had the boundary not been tested at
the exact edge, this would have shipped as a green suite and an off-by-one-day filter in production
— or the reverse, which is worse, because CI would have been the thing that was wrong. Fixed with
`whereDate` on both bounds, the reason recorded in the trait rather than only here, and the fix then
**verified on both drivers** rather than trusted (§7.3b). The
follow-on note is in `tech-debt.md`: `whereDate` wraps the column, which is free on a real `date`
but would need an expression index if the column type were ever widened to `timestamp`.

**2. Clearing the birthday is unreachable through either host UI.** The backend rule is `nullable`
and the form maps empty → explicit `null` (not an omitted key — omission would silently preserve the
old value, since the rule is `sometimes`). But the floor gate fires first: an empty date closes the
step-2 forward gate and trips the profile page's hard floor, so the save never leaves the page. The
planned test asserting "sends null on clear" was therefore asserting a fiction; it now asserts what
actually happens (**the save is blocked**), and the form's comment says the null branch is payload
correctness rather than a live path. A creator correcting a wrong birthday replaces it; they cannot
blank it from the UI.

---

## 9. Follow-ups (named, not built)

1. **"Include unknown age" affordance** on the roster age filter, or an empty-state message naming
   the cause. `tech-debt.md`, trigger: a stalled backfill or agency support traffic.
2. **An index on `creators.date_of_birth`.** `tech-debt.md`, volume trigger, deliberately deferred
   until real birthdays exist so the planner has a distribution to size against.
3. **Discover's age filter UI.** The API already accepts the parameters and is tested; only the
   control is missing. Whenever discover's filter row is next reworked.

---

## 10. Close-out

- **Two commits, pushed:** `0fba5a4c` — `feat(creators): date of birth with derived age everywhere
(AH-088)` (code) → this docs commit — `docs(reviews): AH-088 creator DOB → age — review, AH
entry, data model, tech debt, deploy` (this file, the AH-088 log entry, the data-model row, two
  tech-debt entries, the deploy-log PENDING entry, and the inventory's forward-pointer) — both on
  top of `04a2ecec` (the AH-088 read-only inventory).
- **Push:** done. **CI at the pushed tip (§5.41):** the first run,
  [`33044099156`](https://github.com/pedram-kh/Engine/actions/runs/33044099156) at `b6e03aea`,
  **failed** — not on anything this chunk touched, but §5.41 draws no exception for that: a red CI
  reopens the chunk regardless of how unrelated the failure looks. The cause was
  `EmailVerificationTokenTest`'s tamper helper comparing a whole multi-character base64url segment
  to the single-character string `'a'` (a comparison that is never true) instead of that segment's
  first character — so on the roughly-1-in-64 run where the real first character actually was `'a'`,
  the "tampered" segment was byte-for-byte identical to the original and `->toBeFalse()` failed on a
  true assertion. Fixed at both sites, verified with 60 consecutive local runs (0 failures) before
  repushing, in `ad696308` — its own run,
  [`33045135681`](https://github.com/pedram-kh/Engine/actions/runs/33045135681), is **green: all
  four jobs (Backend, Frontend, and both Playwright E2E suites)**. **Tip:** `ad696308`.
- **Deploy obligations:** one migration **plus a required post-deploy
  `php artisan creators:recompute-completeness`** that writes to ~279 rows. Full checklist, including
  the pre/post reads that make the score drop verifiable, is the AH-088 entry in
  [`../runbooks/deploy-log.md`](../runbooks/deploy-log.md) — **which stacks on the still-PENDING
  AH-086/087 entry below it.**
