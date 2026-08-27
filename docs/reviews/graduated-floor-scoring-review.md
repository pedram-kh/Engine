# AH-089 — Graduated floor scoring

- **Status: Closed — approved, pushed.** Full loop: kickoff with locked decisions (D1–D5) →
  plan-pause (the D3 extraction proposal + the return-type sub-decision, both ratified) → build
  (S1–S10) → gate board → **pushed**.
- **Date:** 2026-08-27. **Risk:** PROD-DATA LOW, as re-derived at plan-pause and confirmed in §4.
- **Provenance:** kickoff decisions D1–D5 locked by Pedram; the plan-pause's D3 extraction proposal
  (parallel enumeration) and the `profileEarned(): float` return-type sub-decision both ratified by
  Pedram before any code was written; built by Cursor. **Closed by Pedram**, verdict: D1–D5 verified
  as ruled; the parallel-enumeration resolution accepted with its honest
  structurally-impossible-to-diverge sentence (§3.2) as the permanent record; both fixed points
  (7/7 → exactly 13, 0/7 → exactly 0) pinned at today's exact values; the one-source pin proven
  biting by break-revert (§5.1); the monotonicity + per-field dataset tests credited with catching
  the factory fixture-contamination bug pre-ship, before it could have shipped as a silently-wrong
  test; the `CreatorWizardFlagOffTest` ripple (§5.3) correctly classified as genuine graduated
  credit, not a regression; the FE zero-diff (D5) proven by a full, unmodified suite run rather than
  by inspection; the three-document sync — this review, the AH-088 addendum, and the deploy-log
  blast-radius rewrite — confirmed complete.
- **Evidence base:** the AH-026 reweight review + [`creator-dob-age-review.md`](creator-dob-age-review.md)
  §5's numbers (the ~20-point cliff this chunk exists to soften); the gate board in
  [§5](#5-evidence) (full local suite, both SPAs, Larastan, Pint, lint, typecheck); the break-revert
  in [§5.1](#51-the-break-revert-priority-3--the-one-source-pin-actually-bites); the ripple found by
  running the full board rather than the targeted file, in [§5.3](#53-the-ripple-found-by-running-the-full-board-not-the-targeted-one);
  the [AH-089 adhoc-log entry](adhoc-changes-log.md) and the
  [deploy-log rewrite](../runbooks/deploy-log.md) this review feeds.

---

## 1. What this is, in one paragraph

AH-088 made the profile-completeness floor a seven-field, all-or-nothing block: miss any one field
and a creator loses the entire 13-point floor credit — a ~20-point visible cliff, unrelated to how
many fields they are actually missing. This chunk makes the floor's **score** graduated —
`PROFILE_FLOOR_WEIGHT` (13) is now earned as `13 × (present floor fields ÷ 7)` — while leaving the
floor's **gate** (`isProfileComplete()`, the boolean that blocks a save and advances the wizard)
byte-identical. The gate still asks "may this creator proceed"; the score now separately asks "how
much of the floor have they earned." Missing exactly one field costs ≈2.9 visible points instead of
≈20; missing all seven still costs the full 13 (0), and having all seven still earns the full 13 —
both endpoints are unchanged, fixed points of the new formula.

---

## 2. Decisions

| ID     | Decision                                                                           | Why not the alternative                                                                                                                                                                                                                                                                     |
| ------ | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1** | The gate is untouched — said first, pinned first                                   | `isProfileComplete()`'s boolean, the FE `floorMet` mirror, save-blocking behaviour, the wizard step gate, and `floor-mirror-parity.spec.ts` are all byte-identical. This chunk changes arithmetic, never gating. Proven by re-running those exact specs unmodified — see §5.2.              |
| **D2** | The floor's score block becomes per-field, **equal shares**                        | `PROFILE_FLOOR_WEIGHT` (13) is earned as `13 × (present ÷ 7)`. Equal shares, not a curated per-field weights registry — proportionality is the point, curation isn't. If a future product call wants "avatar matters more," that is the registry follow-up, named in §6, not built here.    |
| **D3** | Presence semantics: a **parallel enumeration**, pinned against the gate            | See §3.2 for the full reasoning. `floorFieldPresence()` restates `isProfileComplete()`'s own per-field expressions (not `isFilled()` — trim semantics could diverge on a whitespace edge), and a source-inspection test pins the two enumerations' field-token sets as identical.           |
| **D4** | Deploy semantics: rides the pending AH-088 deploy, one recompute, no new migration | No schema change; the write surface is `creators:recompute-completeness`, already a required post-deploy step for AH-088. This chunk only changes what that command computes. The deploy-log's blast-radius paragraph is rewritten (not appended) per the AH-046 addendum pattern — see §4. |
| **D5** | FE surfaces render whatever the API computes — audited, not assumed                | The completion ring, percentage, hints, and wizard progress copy were audited for hardcoded all-or-nothing assumptions. None found — see §6. Zero FE diff, confirmed by re-running the full FE suite unmodified.                                                                            |

Two sub-decisions, ratified at plan-pause, worth their own record:

- **`profileEarned(): float`, not `int`.** Fractional scoring naturally produces non-integer values
  (`13 × 6/7 ≈ 11.14`). Widening the return type is the mathematically sound choice — rounding stays
  monotonic and single-point (only inside `score()`, at the very end, unchanged from AH-026's
  design) — rather than rounding inside `profileEarned()` itself, which risked double-rounding drift
  against `score()`'s existing round-once-at-the-end behaviour. The widening is `strict_types`-safe:
  PHP permits an `int` return from a `float`-typed function (implicit widening), so the two fixed
  points (`0` and `13`) still compute as exact integers internally and are only widened to `0.0` /
  `13.0` on return — never approximated.
- **Equal shares, not a weights registry (D2, restated).** Recorded explicitly because it is the
  kind of choice a future editor might "improve" without noticing it was deliberate: proportionality
  is what this chunk was asked to build; per-field curation is a different product decision with its
  own name, not a natural extension of this one.

---

## 3. Mechanism

### 3.1 The formula

```
profileEarned() = PROFILE_FLOOR_WEIGHT × (|present floor fields| ÷ 7) + Σ (filled optional weights)
```

Both endpoints are fixed points, pinned by test:

- 7/7 present → `13 × 7/7 = 13` — **today's number, exactly.**
- 0/7 present → `13 × 0/7 = 0` — **today's number, exactly.**
- 6/7 present (any one field missing) → `13 × 6/7 ≈ 11.14` — the new, graduated middle.

`score()` itself is untouched beyond this one numerator change: it still sums each unit's earned
credit, renormalises over the applicable steps, and rounds exactly once, at the very end.

### 3.2 D3 — presence semantics, and the tension that does not fully resolve

The kickoff asked for **one source**: the same field-check list feeding both the gate boolean and
the score fraction, so the two counts cannot silently diverge. But D1 requires
`isProfileComplete()` to stay **byte-identical**, because two existing specs regex-extract its
literal source text for its `$creator->field` tokens: `floor-mirror-parity.spec.ts` (the FE↔BE
mirror) and `ContentCompanionsTest.php`'s AH-050 source pin. Refactoring `isProfileComplete()` to
call a shared helper would change its literal body and break both.

**The honest sentence, as promised at plan-pause, verbatim:** a structurally-impossible-to-diverge
version — one function backing both the boolean and the fraction — does not exist while D1 holds.

The resolution actually built is a **parallel enumeration**: `floorFieldPresence()` is a new,
separate private method that restates `isProfileComplete()`'s seven checks verbatim (the floor
predicate's own raw `!== null` / categories count-check expressions — not `isFilled()`, whose
trimmed-non-empty rule could diverge from a raw-presence check on a hypothetical whitespace-only
value, and priority #3 demands the two counts agree **exactly**, not approximately). The agreement
between the two enumerations is then **pinned mechanically**, in the same regex-extraction idiom
`ContentCompanionsTest.php` already uses against `isProfileComplete()` alone: a new test extracts
both function bodies, collects each one's `$creator->field` tokens, and asserts the two sets are
identical. This is the belt-and-suspenders the plan-pause promised — the belt is the source-inspection
agreement test (which reds if either enumeration adds, drops, or renames a field independently of
the other); the suspenders is a per-field `13 × 6/7` dataset test that behaviourally proves the
fraction actually reflects presence, one field at a time.

**Break-revert proof that the pin bites:** dropping `avatar_path` from `floorFieldPresence()` alone
(leaving `isProfileComplete()` untouched) reds the one-source pin test immediately, with a clear
diff showing the two field sets diverging — see §5.1.

---

## 4. Production posture — the deploy-log rewrite (D4)

This chunk rides the pending AH-088 deploy and its one required
`php artisan creators:recompute-completeness` run — no new migration, no new command, no new infra
obligation. What changes is the **outcome** of that same command, which is why D4 required the
deploy-log's blast-radius paragraph to be **rewritten**, not appended to, following the AH-046
addendum pattern (nothing deleted; the correction stands beside what it supersedes).

**Before AH-089** (AH-088 alone): every one of the ~279 live creators — none of whom has a birthday
yet — was projected to lose the **entire** 13-point floor block, ≈20 visible points against the
denominator of 65 (the build-time-hidden `kyc`/`tax`/`payout` steps are always excluded from that
denominator, regardless of any Pennant flag — see `WizardStep::WIZARD_HIDDEN_STEPS`).

**After AH-089** (this chunk, riding the same deploy): missing exactly one floor field (DOB, the
only gap for this cohort) costs `13 × 1/7 ≈ 1.86` raw points — **≈2.9 visible points, not ≈20.** A
creator previously at 100 lands at **≈97**, not ≈80. The general rule, for any creator missing more
than one floor field: **delta = their missing-floor-field count × ~2.9 visible points.** Pinned as
an exact regression test — see §5's new-tests table — re-deriving 97 from first principles rather
than trusting the arithmetic by inspection.

Full checklist, the rewritten paragraph itself, and the updated pre-deploy read expectation are in
the [AH-088 + AH-089 deploy-log entry](../runbooks/deploy-log.md).

---

## 5. Evidence

### 5.1 The break-revert (priority 3 — the one-source pin actually bites)

**Probe.** Drop `avatar_path` from `floorFieldPresence()` only, leaving `isProfileComplete()`
untouched.

**Result: 1 case red** — the one-source pin test, with a clear diff:

```
- 0 => 'avatar_path',
  1 => 'categories',
  ...
```

showing the gate's seven-field set and the fraction's now-six-field set diverging. **Reverted.**

### 5.2 D1 gate-untouched proof (priority 2)

`isProfileComplete()` was not edited — confirmed by re-diffing the file: the method's source is
byte-for-byte what it was before this chunk. The specs that regex-pin its literal body were run
**unmodified**, at tip, and stayed green with **zero fixture changes**:

| Spec                                                          | Result                   |
| ------------------------------------------------------------- | ------------------------ |
| `floor-mirror-parity.spec.ts` (FE↔BE mirror, source pin)      | green, 0 diff            |
| `ContentCompanionsTest.php` (AH-050 source pin + its D6 case) | green, 0 diff (14 tests) |
| `Step2ProfileBasicsPage.spec.ts` (wizard forward-gate)        | green, 0 diff (21 tests) |
| `CreatorProfilePage.spec.ts` (hard-block / soft-warn floor)   | green, 0 diff (10 tests) |
| `RecomputeCreatorCompletenessTest.php` (idempotency, dry-run) | green, 0 diff (3 tests)  |

### 5.3 The ripple found by running the full board, not the targeted one

Running only `CompletenessScoreCalculatorTest.php` stayed green throughout the build. Running the
**full** backend suite found one real ripple outside it: `CreatorWizardFlagOffTest.php`'s
`makeFlagOffCreator()` helper seeds `avatar_path` (for unrelated reasons — several tests in that
file exercise the click-through/submit flow, which needs _some_ profile signal). One test,
`completeness score = 0 for a fresh creator with all three vendor flags OFF`, asserted exactly `0` —
true before AH-089 (the lone avatar field earned nothing under the all-or-nothing floor), false
after (one of seven present is now genuine, non-phantom credit: `13 × 1/7 ≈ 1.86` → `round(1.86 /
65 × 100) = 3`). Fixed by updating that one test's title and expectation to `3`, with a comment
explaining why, rather than touching the shared helper — which every other test in that file also
depends on for unrelated reasons and must not be perturbed. This is the same house lesson AH-088's
own review recorded (`CreatorReopenTest`, found the same way) recurring one chunk later.

### 5.4 Gate board — every gate, run locally at tip

| Gate                                  | Result                                                                                                                                                                                      |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pint (backend lint)                   | pass                                                                                                                                                                                        |
| Larastan level 8                      | **OK, no errors** (947 files)                                                                                                                                                               |
| **Pest — FULL backend suite**         | **2736 passed, 0 failed** (10,405 assertions, 120s)                                                                                                                                         |
| `lint:frontend` (ESLint ×2)           | 0 errors (2 pre-existing `v-html` warnings, neither on a touched line, identical to AH-088's own baseline)                                                                                  |
| `typecheck:frontend` (vue-tsc/tsc ×5) | clean — all five packages                                                                                                                                                                   |
| Vitest — `apps/main`                  | **1654 passed** (167 files) — identical to AH-088's own recorded baseline                                                                                                                   |
| Vitest — `apps/admin`                 | **469 passed** (55 files) — identical to AH-088's own recorded baseline                                                                                                                     |
| floor-mirror parity (source pin)      | green at seven fields, 0 diff                                                                                                                                                               |
| Playwright `e2e-main`                 | **not re-run** — zero FE diff and no wizard-flow field changed (unlike AH-088, which added a new form field); re-run is proportionate the next time this file's FE surfaces actually change |

### 5.5 New tests

| File                                                    | What it pins                                                                                                                                                                                                                                                                                               |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CompletenessScoreCalculatorTest.php` (+10, 4 reshaped) | the D3 one-source pin (§3.2); the per-field `13×6/7` dataset (7 cases); the monotonicity dataset (7 cases, priority #4); the production-parity `≈97` regression pin (re-derives the deploy-log's own number); four existing `profileEarned()` pins reshaped from `int` to float-aware, endpoints unchanged |
| `CreatorWizardFlagOffTest.php` (1 reshaped)             | the ripple in §5.3 — avatar-only now earns graduated credit (3), not 0                                                                                                                                                                                                                                     |

---

## 6. FE surfaces (D5) — audited, zero diff

The completion ring, percentage, hints, and wizard progress copy across `CreatorProfilePage.vue`,
`Step9ReviewPage.vue`, `CreatorDashboardPage.vue`, `DiscoverProfilePage.vue`,
`OnboardingProgress.vue`, both `AnimatedWizardChrome(Mobile).vue` variants, and
`WelcomeBackPage.vue` were read end-to-end. None hardcodes an all-or-nothing assumption about the
profile step's score — every one renders `store.completenessScore` (or an equivalent bootstrap
field) as a number, with only cosmetic threshold checks (e.g. `score >= 100 ? 'success' :
'primary'`) that are agnostic to how the number underneath was computed. **No new UI.** Confirmed
by re-running the full `apps/main` suite unmodified (§5.4) rather than by inspection alone.

---

## 7. Follow-ups (named, not built)

1. **A per-field weights registry**, if a future product call wants some floor fields to matter
   more than others (e.g. "avatar matters more"). D2 deliberately does not build this — equal
   shares is the point of this chunk. Named here so a future editor finds the decision, not a gap.
2. **The floor-mirror parity spec's regex idiom** is now load-bearing for two independent
   enumerations (`isProfileComplete()` and `floorFieldPresence()`), pinned only by the new
   one-source test. If a third floor-adjacent enumeration is ever added, it should join the same
   pin rather than starting a fourth hand-list with no cross-check.

---

## 8. Close-out

- **Two commits, pushed:** `4d56d312` — `feat(creators): graduated per-field floor scoring (AH-089)`
  (code — `CompletenessScoreCalculator`, the calculator test reshape, the `CreatorWizardFlagOffTest`
  ripple fix) → `ca9019e8` — `docs(reviews): AH-089 graduated floor scoring — review, AH entry,
deploy-log rewrite` (this file, the AH-089 log entry, the AH-088 addendum, the deploy-log
  rewrite) — both on top of `860957d7` (AH-088's own close-out tip).
- **Push:** done. **CI at the pushed tip:** see the run cited below.
- **Deploy obligations:** none new. Rides the pending AH-088 + AH-089 entry in
  [`../runbooks/deploy-log.md`](../runbooks/deploy-log.md) — no new migration, the same one required
  `creators:recompute-completeness` run, with its expected outcome rewritten there per D4.
- **Carried record:** the `ad696308` `EmailVerificationTokenTest` flake fix from AH-088's push (a
  1-in-64 tamper-comparison bug, proven fixed with 60 consecutive local runs) is recorded in the
  [AH-089 adhoc-log entry](adhoc-changes-log.md) — it previously existed only in
  [`creator-dob-age-review.md`](creator-dob-age-review.md) §10 and in chat.
