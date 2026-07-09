# Onboarding floor + completeness reweight + wizard % display — Build Review

**Status: Closed**
**Date:** 2026-07-09
**Scope:** Single chunk (AH-026). Region joins the profile floor on both sides of the AH-009 mirror; the profile unit's 25 points split into a floor block + per-optional-field credit; both wizard chromes surface the completeness %; floor fields gain required indicators. A one-shot cohort recompute command ships as a post-deploy step.

> **Process note (read first).** This chunk was built and committed WITHOUT a plan-pause audit against the locked decisions, and eyes-on display fixes were made afterward. This review is the retroactive equivalent, so it carries more evidence than usual: every locked decision is quoted from the shipped source, and every review-priority gate (incl. the break-reverts) was executed at current HEAD — i.e. AFTER the eyes-on fixes — so nothing here predates the final state. See §"Eyes-on fixes inventory" for the ordering guarantee.

---

## 1. Commit list

`git log --oneline 887c601..HEAD` (887c601 = `origin/main`, the last pushed commit; AH-025 docs pushed earlier at `ebd3445`):

```
ffe4ab9 feat(discover): show the creator's profile completeness on the discover detail
9929db8 docs: log AH-026 onboarding floor + score reweight + wizard % display
a23623a feat(onboarding): add region to floor, reweight completeness, surface score in wizard
```

`git status --short` → clean except three untracked Vitest temp artifacts (deliberately uncommitted, not source):

```
?? apps/admin/vitest.config.ts.timestamp-1780888665937-ee98bd49f870a8.mjs
?? apps/main/vitest.config.ts.timestamp-1780888665937-054ff1140a7518.mjs
?? packages/ui/vitest.config.ts.timestamp-1780888665919-539a5a314a572.mjs
```

**Commit attribution:**

- **`a23623a` — the chunk build (incl. Pedram's eyes-on display fixes).** The build (SS1–SS8) and the post-build eyes-on fixes to the wizard chrome (desktop label repositioned above the frame; mobile pill re-sized to the step-number box height + even distribution of upcoming steps up to the fixed % pill) landed in a single commit — they were not separated, so the eyes-on fixes are not isolable by SHA. 44 files: `CompletenessScoreCalculator.php`, `RecomputeCreatorCompleteness.php`, the four onboarding component/layout files, three onboarding pages, `CreatorProfilePage.vue`, `floor-mirror-parity.spec.ts`, the touched Pest/Vitest specs, and 24 `creator.json` locale files.
- **`9929db8` — docs.** Ad-hoc log AH-026 entry, tech-debt entries, resumption-template refresh. (The full §5.39 docs pass — the _remaining_ AH-entry polish/tech-debt is on HOLD per the kickoff; this commit is what was written during the build.)
- **`ffe4ab9` — a SEPARATE follow-on feature, not part of this chunk.** Surfacing the (already-on-the-wire) `profile_completeness_score` on the agency discover-detail page. Reported here only for completeness of the commit range; it is not an AH-026 deliverable and has its own tests + 24 `app.json` locale strings.

---

## 2. Chat completion summary (§3 Step 6)

### What was built, per locked decision

- **D1 — region joined the floor (both sides).** `isProfileComplete()` and the FE `floorMet` now both name the six fields incl. `region`. Pinned 1:1 by a new source-scan parity spec.
- **D2 — step-2 forward gate aligns to the full floor.** `Step2ProfileBasicsPage.canContinue` is now `readiness.floorMet` (was avatar+category only).
- **D3 — backfill-on-next-edit for region-less pending/rejected creators.** Hard-block on next profile edit until region filled; approved stays soft-warn. Posture recorded in the ad-hoc log + the `CreatorProfilePage` docblock.
- **D4 — optional-field credit inside the profile unit.** 25 = floor 13 + bio 4 + accent 2 + phone 2 + whatsapp 2 + street 1 + postal 1. Gate boolean stays floor-only; the numerator is partial via `profileEarned()`. Denominator/normalisation untouched.
- **D5 — one-shot cohort recompute.** `creators:recompute-completeness` (idempotent, `--dry-run`), a documented post-deploy step, not a migration side effect.
- **D6 — wizard chromes show the %.** Desktop `AnimatedWizardChrome`, mobile `AnimatedWizardChromeMobile`, and `OnboardingProgress` all render `store.completenessScore` alongside "Step X of N", threaded as a static prop from `OnboardingLayout`.
- **D7 — no submit-gate unit changes.** Confirmed by diff (§D7/D8 below).
- **D8 — no approval-gate change.** Confirmed by diff.
- **D9 — mandatory fields visibly marked + two-signal review copy.** Floor fields carry an asterisk via `requiredLabel()`; the review step states the two-signal model explicitly.

### Unilateral decisions made in the absence of a plan-pause (audit these now)

1. **Required-indicator marker is a locale-neutral literal `*`, not a translation key.** `requiredLabel(key)` returns `` `${t(key)} *` ``. Reasoning: an asterisk is locale-neutral punctuation; keying it would add 24× a one-char string with no translatable content and risk parity churn. **Audit angle:** if the house standard requires every rendered glyph to be key-backed, this is a deviation to convert. It touches no keyset (parity stayed green with zero new asterisk keys).
2. **D9 review copy re-keyed the bar COLOUR (not just the text) to submit-readiness (Q3 "your call" taken).** `submitReady = incompleteSteps.length === 0`; `completenessColor = submitReady ? 'success' : 'primary'`. Reasoning: a submit-ready creator at 82% seeing a non-success bar next to "everything required is done" is a mixed signal. The score value is still shown; only the colour tracks submit-readiness. **Audit angle:** this is the Q3 option I was told was discretionary — flagging it explicitly as taken.
3. **Eyes-on display geometry (mobile pill) uses a live-measured even-distribution layout.** Upcoming step numbers distribute evenly between the active chip and the fixed % pill (measured at runtime) rather than fixed slots. Reasoning: Pedram's explicit design request. **Audit angle:** the only place the chunk added runtime DOM measurement; it is display-only (no gate/score/i18n impact) and is covered by the chrome specs + typecheck.
4. **Discover-detail follow-on (`ffe4ab9`) was built + committed in the same session** after an explicit go-ahead, but it is NOT an AH-026 decision. **Audit angle:** confirm it's acceptable that it rode the same session; it is independently tested and does not touch any floor/gate/score code (read-only display of an existing field).

### Deviations from a locked decision (§5.32)

- **None believed.** D4's point split shipped EXACTLY as locked (13/4/2/2/2/1/1) — the kickoff allowed adjustment "if the code shape argues for it"; it did not, so no adjustment was taken. Intent preserved on all of D1–D9. The three items above are discretionary/unilateral calls, not deviations from a locked mechanism.

### What surprised me mid-build

- **The "fully-complete creator scores 100" fixture had to gain every optional field.** Under D4 the profile unit only reaches 25 when the floor AND all six optionals are filled; the pre-existing 100% fixture set only the floor, so it would have landed at 13 and totalled < 100. The weights-pin test now documents this trap inline (`CompletenessScoreCalculatorTest` lines 109–113).
- **The full backend Pest run OOMs at the default 128 MB** loading the Horizon dashboard JS (`Horizon.php:206`) — unrelated to this chunk, but it means the green run required `-d memory_limit=1G`. Flagged as environmental (see §6).

---

## 3. Per-decision evidence

### D1 / D2 — the mirror, side by side, + the step-2 gate

**Backend `isProfileComplete()`** (`CompletenessScoreCalculator.php:344-358`):

```344:358:apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php
    private function isProfileComplete(Creator $creator): bool
    {
        // Categories is the discriminating field — a creator can fill
        // every other text field but if they haven't picked at least one
        // category we can't surface them on agency search. Avatar is
        // included to incentivise upload. Region joined the floor (AH-026 D1):
        // the six-field floor is mirrored 1:1 by the FE `floorMet` in
        // ProfileBasicsForm.vue, pinned by the floor-mirror parity spec.
        return $creator->display_name !== null
            && $creator->country_code !== null
            && $creator->region !== null
            && $creator->primary_language !== null
            && is_array($creator->categories) && count($creator->categories) > 0
            && $creator->avatar_path !== null;
    }
```

**Frontend `floorMet`** (`ProfileBasicsForm.vue:218-226`):

```218:226:apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue
const floorMet = computed(
  () =>
    displayName.value.trim() !== '' &&
    countryCode.value !== null &&
    (region.value?.trim() ?? '') !== '' &&
    primaryLanguage.value !== null &&
    categories.value.length > 0 &&
    hasAvatar.value,
)
```

The field SET is identical (display_name, country, region, primary_language, categories, avatar); operators differ deliberately (BE `!== null`, FE trimmed-non-empty), which is the documented invariant — set parity, not operator parity.

**D2 — step-2 `canContinue`** (`Step2ProfileBasicsPage.vue:53`), now the full floor via the readiness emit (was avatar+category only):

```53:53:apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue
const canContinue = computed(() => readiness.value.floorMet)
```

### D3 — backfill posture recorded; approved stays soft-warn

Recorded in `CreatorProfilePage.vue:34-38` (docblock) and `adhoc-changes-log.md:98-101`. Page logic:

```101:109:apps/main/src/modules/creators/pages/CreatorProfilePage.vue
const floorMet = computed(() => readiness.value.floorMet)

// cleared — incl. the avatar-delete path (floorMet folds avatar presence in).
const saveBlocked = computed(() => isHardBlockState.value && !floorMet.value)

// Soft warn (approved only): allow the save, but flag that it lowers the
// agency-visible completeness signal. Never blocks.
const showApprovedWarning = computed(() => isApproved.value && !floorMet.value)
```

`saveBlocked` keys off `isHardBlockState` (pending/rejected); `showApprovedWarning` is approved-only and never blocks. Confirmed: approved = soft-warn, unchanged.

### D4 — the point split as shipped, the gate boolean, the denominator

Split shipped EXACTLY as locked — `PROFILE_FLOOR_WEIGHT = 13` and `PROFILE_OPTIONAL_WEIGHTS` (`CompletenessScoreCalculator.php:62-80`):

```73:80:apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php
    public const array PROFILE_OPTIONAL_WEIGHTS = [
        'bio' => 4,
        'accent' => 2,
        'phone' => 2,
        'whatsapp' => 2,
        'address_street' => 1,
        'address_postal_code' => 1,
    ];
```

**Gate boolean untouched by optionals** — `stepCompletion['profile']` is `isProfileComplete()` (floor only, line 203); the optional credit lives only in `profileEarned()` (lines 258-269), which `score()` calls for the profile unit's numerator (line 141). **Denominator/normalisation unchanged** — profile still contributes `weights()[profile] = 25` to `$total` (line 132), and `min((int) round($earned / $total * 100), 100)` (line 154) is unchanged. Invariant pinned: floor(13) + Σoptionals(12) = 25.

### D5 — the recompute command

Name `creators:recompute-completeness` (`RecomputeCreatorCompleteness.php:31`). Idempotent: writes only when `$recomputed !== $current` (lines 52-61). `--dry-run` reports without writing. Documented post-deploy step (docblock lines 20-27) — "deliberately NOT a migration side effect." Tests in `RecomputeCreatorCompletenessTest.php`: stale→current recompute (`1 score(s) updated`), idempotency (2nd run `0 score(s) updated`), `--dry-run` leaves the row untouched. See §P3 for the run.

### D6 / D9 — % render, required indicators, review copy, locale scope

**% render points (all three surfaces):**

- Desktop `AnimatedWizardChrome.vue:537` — `<p v-if="completenessLabel" class="wizchrome__completeness" data-test="wizard-completeness">`.
- Mobile `AnimatedWizardChromeMobile.vue:654` — `<span … class="wizm__completeness" data-test="wizard-completeness">` (fixed at the rail's right end; eyes-on fix sized it to the step-number box height with the aurora-gradient border).
- `OnboardingProgress.vue:152` — `data-test="onboarding-completeness"`.
- Threaded from `OnboardingLayout.vue`: long label (`percent_complete`) to desktop + progress; short (`percent_only`) to mobile (lines 168-179, 292/305). All read the same `store.completenessScore`.

**Required indicators on BOTH ProfileBasicsForm hosts.** `ProfileBasicsForm` is the single shared body backing both wizard step 2 and `/creator/profile`, so the marker is defined once and renders on both. `requiredLabel()` (`ProfileBasicsForm.vue:75-77`) appends `*`; applied to display_name (345), country (381), region (388), primary_language (478), categories (495); avatar carries its own "required to finish this step" line (340).

**Review-step copy as shipped** (en): `two_signal_ready` = _"Your profile is {percent}% complete — everything required for submission is done. You can add more to strengthen your profile."_ (`Step9ReviewPage.vue:168`). The bar colour tracks submit-readiness, not the % (Q3, unilateral — see §2).

**Locale regen scope:** 24 `creator.json` files (one per UI locale). New/changed keys: `progress.percent_complete`, `progress.percent_only`, `steps.review.two_signal_ready`, `fields.optional_prefix` (new) + `fields.step_requirements_hint` (reworded to "Complete every required field (marked _) to continue."). Parity gate green (§P5). _(The discover follow-on `ffe4ab9` separately added `app.discover.detail.completeness` across 24 `app.json` — not part of this chunk.)\*

### D7 / D8 — nothing moved (diff evidence)

`git show --stat a23623a` filtered for the submit-gate + admin-approve surfaces returns **NONE** — the commit touches neither the submit-gate unit membership (`WizardCompletionService` / `CreatorWizardService`) nor the admin approve path (`AdminCreatorController`):

```
=== does the commit touch submit-gate or admin-approve? ===
NONE — submit-gate + admin-approve untouched
```

The only backend behavioural file in the commit is `CompletenessScoreCalculator.php` (score numerator + the floor boolean), plus the new recompute command. `stepCompletion()`'s unit membership (social ≥1, portfolio ≥1, contract) is byte-identical apart from the profile boolean now including region (D1's intended effect).

---

## 4. Review-priority evidence (verbatim)

### P1 — floor-mirror break-revert, both directions

Baseline: `floor-mirror-parity.spec.ts` → **2 passed**.

**BE-side removal** (dropped `region` from `isProfileComplete`) → the backend assertion fails:

```
 ❯ tests/unit/architecture/floor-mirror-parity.spec.ts (2 tests | 1 failed)
   × backend isProfileComplete() reads EXACTLY the six floor fields
     → expected [ …(5) ] to deeply equal [ …(6) ]
-   "region",
```

Restore + status check:

```
$ git checkout -- apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php
=== git status after restore === (no line above = clean)
```

**FE-side removal** (dropped `region.value` from `floorMet`) → the frontend assertion fails:

```
   × frontend floorMet reads EXACTLY the six mirror fields
     → expected [ …(5) ] to deeply equal [ …(6) ]
-   "region",
```

Restore + status check + re-green:

```
$ git checkout -- apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue
=== status after restore === (clean if empty)
=== re-confirm baseline green ===  Test Files 1 passed (1)  Tests 2 passed (2)
```

### P2 — weights-pin + 100%-for-fully-complete

`CompletenessScoreCalculatorTest` — 15 tests pass, incl. `weights sum to exactly 100`, `the D4 profile sub-split … sums to the profile unit weight` (asserts `PROFILE_FLOOR_WEIGHT === 13` and the exact optional map), and `a fully-completed creator scores 100`.

### P3 — recompute idempotency + formula-migration

`RecomputeCreatorCompletenessTest` — 3 tests pass:

```
✓ it recomputes a stale stored score to the current-formula value (D5)
✓ it is idempotent — a second run updates nothing
✓ it --dry-run reports the change but leaves the stored score untouched
```

(Combined P2+P3 Pest run: **18 passed, 32 assertions**.)

### P4 — §5.34 negative case + its break-revert

Baseline: `an empty optional drops the score but does NOT flip the profile gate (§5.34)` passes (clearing bio drops `profileEarned` 17→13; gate stays true).

Break (made the gate depend on the `bio` optional) → the negative-case test fails exactly at the gate assertion:

```
⨯ it an empty optional drops the score but does NOT flip the profile…
  Failed asserting that false is true.
  at tests/Feature/Modules/Creators/CompletenessScoreCalculatorTest.php:283
  ➜ 283  expect($calc->stepCompletion($creator)[WizardStep::Profile->value])->toBeTrue();
```

Restore + re-green:

```
$ git checkout -- apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php
=== status after restore (clean if empty) ===
=== re-confirm negative case green ===  PASS  Tests: 1 passed (4 assertions)
```

### P5 — locale parity post-regen

`i18n-locale-parity.spec.ts` → **Test Files 1 passed, Tests 4 passed** (keyset parity, `{named}` placeholder integrity, plural form-count — across all 24 rendered locales).

### P6 — sub-100 assumption sweep

Searched BE + both SPAs + Playwright for numeric comparisons against the completeness score (`>= 100`, `<= 100`, `=== 100`, `== 100`, `< 100`, `> 100`, and `100 <op>`). **Every hit + disposition:**

| Hit                                                      | File:line                                    | Disposition                                                                  |
| -------------------------------------------------------- | -------------------------------------------- | ---------------------------------------------------------------------------- |
| `completenessScore.value >= 100 ? 'success' : 'primary'` | `DiscoverProfilePage.vue:83`                 | **Cleared** — cosmetic bar colour (discover follow-on, not a gate).          |
| `score >= 100 ? 'success' : 'primary'`                   | `CreatorDashboardPage.vue:271`               | **Cleared** — cosmetic bar colour, pre-existing.                             |
| `$stale = $expected === 100 ? 50 : 100;` (×2)            | `RecomputeCreatorCompletenessTest.php:36,63` | **Cleared** — test fixture picking a value that differs from the real score. |
| `… total is < 100.`                                      | `CompletenessScoreCalculatorTest.php:113`    | **Cleared** — comment.                                                       |

The review-step, which previously could have implied "100% to submit", was re-keyed to `submitReady = incompleteSteps.length === 0` (`Step9ReviewPage.vue:121`) — it does NOT compare the score. **No submit gate anywhere reads `profile_completeness_score`.** Nothing fixed (nothing broken); all hits cleared with reasoning.

---

## 5. Eyes-on fixes inventory

Pedram's post-build fixes were wizard-chrome **display geometry only**, driven by on-device screenshots:

| Fix                       | What changed                                                                                                                                                          | Files                                                                  | Touched floor/weights/gate/recompute/i18n?                                                          |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Desktop % label placement | Moved the caption above the framed panel's top-left corner, larger font                                                                                               | `AnimatedWizardChrome.vue` (+ CSS)                                     | No. Display only.                                                                                   |
| Mobile % pill             | Number-only pill fixed at the rail's right end, aurora-gradient border, **sized to the step-number box height**, upcoming steps **distributed evenly** up to the pill | `AnimatedWizardChromeMobile.vue`, `OnboardingLayout.vue` (short label) | **i18n: yes** — added `progress.percent_only` (24 locales). No floor/weights/gate/recompute impact. |

**Ordering guarantee.** All P1–P6 evidence above was gathered at **current HEAD**, i.e. AFTER every eyes-on fix (they are committed in `a23623a`). The one gate the eyes-on fixes could have disturbed — i18n keyset parity (P5), because the mobile fix added `percent_only` — was re-run at HEAD and is green. The floor/weights/gate/recompute gates are untouched by display code, and were also run at HEAD. Nothing needs a re-run.

---

## 6. Gate totals (current HEAD)

| Gate                     | Result                                                                                                                                                                                                                                                                                                                                                                                          |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend Pest (full)      | **1800 passed, 1 skipped** (6297 assertions) — requires `-d memory_limit=1G`; default 128 MB OOMs in `Horizon.php:206` loading the dashboard JS (pre-existing, unrelated).                                                                                                                                                                                                                      |
| main Vitest (full)       | **1149 passed** (129 files)                                                                                                                                                                                                                                                                                                                                                                     |
| admin Vitest (full)      | **425 passed** (51 files)                                                                                                                                                                                                                                                                                                                                                                       |
| api-client Vitest (full) | **196 passed** (8 files)                                                                                                                                                                                                                                                                                                                                                                        |
| Locale parity (explicit) | **4 passed** (keyset + placeholder + plural, 24 locales)                                                                                                                                                                                                                                                                                                                                        |
| `vue-tsc` main / admin   | **clean / clean** (exit 0)                                                                                                                                                                                                                                                                                                                                                                      |
| ESLint main / admin      | **0 errors** (main: 2 pre-existing `v-html` warnings on `ClickThroughAccept.vue` + `ProfileBasicsForm.vue` bio preview) / **0 errors**                                                                                                                                                                                                                                                          |
| Pint                     | **passed**                                                                                                                                                                                                                                                                                                                                                                                      |
| PHPStan                  | **No errors** (requires `--memory-limit=2G`; default OOMs in a parallel worker — pre-existing)                                                                                                                                                                                                                                                                                                  |
| Playwright happy-path    | **1 passed (27.1s)** — run at close (Part A) with the dev stack stopped so Playwright's `webServer` owned port 8000 against its isolated test DB (`migrate:fresh` on the E2E database, `887c601` isolation intact). The region-fill ripple edit added during the build was already in the spec, so **the test passed on the first run with no further spec change**. Dev stack restarted after. |

---

## 7. Reviewer checklist

- [x] D4 split (13/4/2/2/2/1/1) is the intended motivation shape.
- [x] The three unilateral calls in §2 are acceptable (asterisk-as-literal, review-bar colour re-key, discover follow-on riding the session).
- [x] Playwright happy-path re-run with the dev stack down (the one outstanding gate) — **1 passed (27.1s)**, no spec change beyond the build's region fill.
- [x] HOLD acknowledged: the §5.39 docs pass (AH-027 log entry, review close, Live-Status prune, resumption-template refresh) landed in the docs commit this review closes with; push still HELD.

---

## 8. Independent review — verdict (appended to Cursor's draft)

**Approved with items — closed.** All D1–D9 evidence cleared against the shipped source; the D4 point split (13/4/2/2/2/1/1) is the intended motivation shape and shipped without deviation; the gate/score separation (floor-only boolean, partial numerator via `profileEarned()`, sum-to-25/sum-to-100 pins) holds. The three unilateral calls in §2 are **accepted**: (1) the required-indicator `*` as a locale-neutral literal (no keyset churn), (2) the `Step9ReviewPage` bar-colour re-key to submit-readiness (the discretionary Q3 option, taken), and (3) the mobile pill's live-measured even-distribution geometry (Pedram's explicit design request, display-only). The sub-100 sweep is satisfyingly negative — no gate anywhere reads the completeness score. The one outstanding gate, the Playwright happy-path, was run at close with the dev stack down: **1 passed (27.1s)**, and the build's in-scope region-fill ripple was already in the spec, so no further spec change was required.

_Provenance (§4): drafted by Cursor (AH-026 retroactive build review — the chunk was built and committed without a plan-pause, so this file is the retroactive equivalent with per-decision quoted evidence and HEAD-gathered break-reverts). Merged/closed by Claude via independent review (approved with items; three unilateral calls accepted; Playwright happy-path green from Part A). Closed in the same docs commit that logs AH-027, prunes the Live Status graduates, and refreshes the resumption template — push HELD per the kickoff._
