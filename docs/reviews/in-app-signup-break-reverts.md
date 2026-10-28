# AH-104d — break-revert log

Harness output for every AH-104d break-revert. Each mutation is applied to the working tree, the named specs are run (Pest for the API, Vitest for the web), the harness reads the run's **exit
code** (non-zero means the mutation was CAUGHT) and restores the file. The harnesses and mutation lists are throwaway scripts under `/tmp/br`; **they are not in the repository, and this file is the
record.** A mutation whose old string did not occur is reported `SKIPPED` and never counted as caught. Where a block prints `identical after: True` the harness also compared an MD5 of every mutated file
before and after the block; S1 to S3 restore each file from the source held in memory and were checked by a clean re-run (`restored exit= 0`) rather than a hash.

Counts: **S1 9, S2 5, S3 6 (one redone), S4 15 + 2 CSS, S5 13, S6 12, S7 7: 69 distinct mutations. Not caught at the end: 0.** The output below is from the **final re-run on the finished tree**
(2026-10-26), not from the first time each block was run.

## What the first runs found (and what was done about it)

| Item                                                                | What happened                                                                                                                                                                                                                                                                                  | Closed by                                                                                                                                                                              |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S3 "consent link is a router-link"                                  | The first version of the mutation replaced the opening tag only, left `</a>`, and **did not compile**: exit 1 with **0 failing specs**. A compile error is not a caught mutation.                                                                                                              | Redone as a well-formed swap of the whole element (S3b below): 2 specs fail by name.                                                                                                   |
| S5 "signed-in branch removed" (first run)                           | With the first guard in `verifiedSignIn()` removed, nothing failed: `refreshSession()` makes no request without a session, so the removal was behaviourally equivalent.                                                                                                                        | The redundant guard was **deleted** from the code and its doc comment now says why; the "signed out calls no API" spec pins it. The mutation became "re-read removed" (S5 first line). |
| S5 "prefill not trimmed" and "prefill trusts an array" survived     | jsdom strips whitespace from an `<input type="email">` itself, so the field's displayed value cannot show the trim; and the array case was tested with a query string the router did not parse as an array.                                                                                    | The trim spec now asserts the **submitted payload**; the array spec passes a real array in `query`. Both now fail the mutation.                                                        |
| S5 "email dropped from the hand-over" printed exit 1 with 0 failing | The mutation (an emptied spread) did not compile.                                                                                                                                                                                                                                              | Changed to `...{}`; fails 2 specs.                                                                                                                                                     |
| S5 first full run printed `restored exit= 1`                        | A mutation script left the pending page without its `v-if="!isNative"` on the back link (cause not found; the next run's "back link kept on native" was `SKIPPED` because the pattern was already gone). The spec "offers the button in place of the back link" was red on the unmutated tree. | I restored the line by hand and added the MD5 before/after check to the harness; the final run prints `files identical after: True`.                                                   |

## S1 — the limiter (Pest, `SignUpRateLimitTest`)

```
route middleware removed: exit=1 failing_specs=8
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it lets five…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it creates n…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it keys on t…
raw (un-normalised) email: exit=1 failing_specs=4
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it treats ca…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it answers a…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it never sha…
keyed on IP instead of email: exit=1 failing_specs=5
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it does not…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it keys on t…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it answers a…
empty-email fallback dropped: exit=1 failing_specs=1
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it never sha…
5 -> 6 per hour: exit=1 failing_specs=8
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it lets five…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it is 5 atte…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it creates n…
5 -> 4 per hour: exit=1 failing_specs=8
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it lets five…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it is 5 atte…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it creates n…
per-minute for per-hour: exit=1 failing_specs=1
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it is 5 atte…
403 instead of 429 body: exit=1 failing_specs=7
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it lets five…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it creates n…
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it keys on t…
limiter not in neutralizer allow-list: exit=1 failing_specs=1
     FAILED  Tests\Feature\Modules\Identity\SignUpRateLimitTest > it registers…
restored run exit= 0
```

## S2 — the flaky-ten strings (`i18n-signup-flow-strings.spec.ts`)

```
English back in hu rate_limit.exceeded: exit=1 ['× the sign-up flow strings in the flaky ten > hu > is not English, not an English hybrid, keeps placeholders a', 'FAIL  tests/unit/architecture/i18n-signup-flow-strings.spec.ts > the sign-up flow strings in the flaky ten > h']
hybrid in bg errors.unknown: exit=1 ['× the sign-up flow strings in the flaky ten > bg > is not English, not an English hybrid, keeps placeholders a', 'FAIL  tests/unit/architecture/i18n-signup-flow-strings.spec.ts > the sign-up flow strings in the flaky ten > b']
ing-mangle in fi loading.sending: exit=1 ['× the sign-up flow strings in the flaky ten > fi > is not English, not an English hybrid, keeps placeholders a', 'FAIL  tests/unit/architecture/i18n-signup-flow-strings.spec.ts > the sign-up flow strings in the flaky ten > f']
placeholder dropped in et too_short: exit=1 ['× the sign-up flow strings in the flaky ten > et > is not English, not an English hybrid, keeps placeholders a', 'FAIL  tests/unit/architecture/i18n-signup-flow-strings.spec.ts > the sign-up flow strings in the flaky ten > e']
Romanian Confirmațiați restored: exit=1 ['× a native-language mangle no detector can see > Romanian "Confirm password" is the imperative "Confirmați par', 'FAIL  tests/unit/architecture/i18n-signup-flow-strings.spec.ts > a native-language mangle no detector can see ']
restored exit= 0
```

## S3 — the consent line and the confirm line

```
rel dropped from the consent link: exit=1 failing=2
     × footerLinkAttrs > opens a real destination in a new tab with the anti-tabnabbing rel 12ms
     × SignUpPage > consent line > renders the sentence with the privacy policy as an external link 96ms
consent link is a router-link: exit=1 failing=0
consent link points at the wrong URL: exit=1 failing=1
     × SignUpPage > consent line > renders the sentence with the privacy policy as an external link 57ms
slot left unfilled (no #privacy template): exit=1 failing=3
     × SignUpPage > consent line > renders the sentence with the privacy policy as an external link 99ms
     × SignUpPage > consent line > is a plain anchor outside the router (never a router-link that would stay in the app) 68ms
app hint shown on every state: exit=1 failing=3
     × EmailVerificationConfirmPage > app hint > is absent when the token is missing 570ms
     × EmailVerificationConfirmPage > app hint > is absent when verification fails (expired link) 29ms
consent line moved above the form: exit=1 failing=4
     × SignUpPage > consent line > renders the sentence with the privacy policy as an external link 93ms
     × SignUpPage > consent line > is a plain anchor outside the router (never a router-link that would stay in the app) 110m
restored exit= 0
```

The router-link case above is the broken first attempt (`failing=0`); the well-formed one:

```
consent link is a router-link (well-formed): exit=1 failing=2
     × SignUpPage > consent line > renders the sentence with the privacy policy as an external link 341ms
     × SignUpPage > consent line > is a plain anchor outside the router (never a router-link that would stay in the
identical after: True
```

## S4 — the native sheet and the sign-in entry

```
sign-up not a sheet route: exit=1 failing=3
     × AuthLayout — auth.sign-up as a native sheet (AH-104d) > is arranged as the sheet, but is NOT the hero (no he
     × isSheetRoute > auth.sign-up is a sheet route 15ms
pending page not a sheet route: exit=1 failing=3
     × AuthLayout — auth.verify-email.pending as a native sheet (AH-104d) > is arranged as the sheet, but is NOT th
     × isSheetRoute > auth.verify-email.pending is a sheet route 7ms
forgot-password wrongly a sheet route: exit=1 failing=3
     × AuthLayout — the creator app sign-in (native) > other auth pages (centred card, no hero) are unaffected 87ms
     × isSheetRoute > auth.forgot-password is not a sheet route 7ms
layout ignores sheet routes (hero only): exit=1 failing=2
     × AuthLayout — auth.sign-up as a native sheet (AH-104d) > is arranged as the sheet, but is NOT the hero (no he
     × AuthLayout — auth.verify-email.pending as a native sheet (AH-104d) > is arranged as the sheet, but is NOT th
native label never drawn: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > puts a label above each of the five fields, linked by for/id, with
native icon dropped: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > puts a label above each of the five fields, linked by for/id, with
floating label kept on native: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > puts a label above each of the five fields, linked by for/id, with
autocomplete on password lost: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > keeps every autocomplete hint and input type, so the keychain and
submit not large on native: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > submit is the large primary button 158ms
native error banner not drawn: exit=1 failing=1
     × SignUpPage — creator app (native sheet) > a coded error (429) draws the banner: on-class, alert icon, transl
web shows the native mark: exit=1 failing=1
     × SignUpPage — web polarity of the native branches (AH-104d) > keeps the web form: floating labels, no mark, n
web gets the sheet root class: exit=1 failing=1
     × SignUpPage — web polarity of the native branches (AH-104d) > keeps the web form: floating labels, no mark, n
sign-in button goes to the website not the app: exit=1 failing=1
     × SignInPage — creator app (native) > the Create account button opens the in-app sign-up sheet 109ms
sign-in "new" line removed: exit=1 failing=1
     × SignInPage — creator app (native) > replaces the web sign-up link with an in-app "Create account" button 99m
retired key reintroduced (de): exit=1 failing=1
     × retired keys (AH-104d S4) > auth.ui.native.create_account (the "go to the website" pointer) is gone from eve
files identical after: True
restored exit= 0
```

CSS (`native-page-css-scoping.spec.ts`, `native-css-scoping.spec.ts`):

```
hard-coded hex colour in signUpNative.css: exit=1 failing=2
     × 'modules/auth/pages/signUpNative.css' > roots every selector at .sign-up-native 16ms
     × 'modules/auth/pages/signUpNative.css' > uses no literal colours 2ms
unscoped selector in signUpNative.css: exit=1 failing=1
     × 'modules/auth/pages/signUpNative.css' > roots every selector at .sign-up-native 9ms
identical after: True
```

## S5 — the pending page, the prefill, the layout `.catch`

Summary lines of the final run (the failing-spec names were printed by the harness and not saved).

```
re-read removed (a verified account is never noticed): exit=1 failing=3 unhandled=False
verified check inverted: exit=1 failing=5 unhandled=False
dead-session branch removed: exit=1 failing=3 unhandled=True
delete link ungated (flag): exit=1 failing=3 unhandled=False
delete link shown on web: exit=1 failing=1 unhandled=False
delete link shown signed out too: exit=1 failing=2 unhandled=False
button shown on web: exit=1 failing=1 unhandled=False
back link kept on native: exit=1 failing=1 unhandled=False
email dropped from the sign-in hand-over: exit=1 failing=2 unhandled=False
prefill not trimmed: exit=1 failing=1 unhandled=False
prefill not capped: exit=1 failing=1 unhandled=False
prefill trusts an array: exit=1 failing=1 unhandled=False
layout bootstrap .catch removed: exit=1 failing=1 unhandled=False
files identical after: True
restored exit= 0
```

## S6 — the wizard account menu

Summary lines of the final run.

```
menu ungated (renders with the flag off): exit=1 failing=6
gate reads the wrong flag: exit=1 failing=2
menu points at the wrong route: exit=1 failing=2
menu email dropped: exit=1 failing=1
menu gains a sign-out entry: exit=1 failing=1
desktop mount removed: exit=1 failing=2
native mount removed: exit=1 failing=1
web-mobile also gets the menu: exit=1 failing=1
drawer row ungated: exit=1 failing=5
drawer row points at wrong route: exit=1 failing=1
drawer row shown on native too: exit=1 failing=4
Save and exit removed from desktop: exit=1 failing=2
identical after: True
restored exit= 0
```

## S7 (superseded) — the first `setFeatureFlag`: a `tinker` shell-out guarded by an "e2e" database-name test

Kept as the record of what was proved. **CI refused it** (the throwaway Postgres is named `catalyst`), and it was replaced by the `_test/feature-flag` route; see the next section.

```
e2e-name guard removed: exit=1 failing=4
guard accepts any db containing "e": exit=1 failing=2
flag-name check removed: exit=1 failing=7
flag-name check loosened (allows quote): exit=1 failing=6
DB pinned to env only (no e2e default): exit=1 failing=1
DB override dropped from the child env: exit=1 failing=1
off calls activate: exit=1 failing=1
identical after: True
restored exit= 0
```

## S7b — the `_test/feature-flag` route and its Playwright wrapper

Pest, `SetFeatureFlagTest` (5 specs), against `SetFeatureFlagController`; each mutation applied to a copy-restored file, MD5 checked after.

```
M1 activate does nothing:            exit=1 failing=2
M2 deactivate does nothing:          exit=1 failing=2
M3 store skips the allow-list:       exit=1 failing=1
M4 destroy skips the allow-list:     exit=1 failing=1
restored identical; clean run exit=0
```

Vitest, `e2e-feature-flag-helper.spec.ts` (7 specs), against the fixture's `setFeatureFlag`:

```
X1 verbs swapped (POST for off, DELETE for on): 3 failing
X2 failure swallowed (status check disabled):   3 failing
X3 flag name not URL-encoded:                   1 failing
X4 fixture spawns a process (imports child_process): 1 failing
restored identical; clean run 7 passed
```

## W4 follow-up (2026-10-27) — the taken-address guard, `SignUpTest`

`SignUpRequest` mutated, each restored by copy with MD5 checked.

```
S1 unique rule removed:           exit=1 failing=6
S2 lower-casing removed:          exit=1 failing=2
S3 trim removed:                  exit=0  (survives, EQUIVALENT: Laravel's TrimStrings middleware trims every input first; prepareForValidation's trim is redundant)
S4 whole normalisation removed:   exit=1 failing=2
restored identical; clean run exit=0
```

S3 is recorded rather than hidden: a spec for a padded new address was added and still cannot fail it, which is how the equivalence showed.

## "Use a different address" link (2026-10-27, tech-debt 14)

Vitest against `EmailVerificationPendingPage.vue`, `SignUpPage.vue` and `hu/auth.json`; each mutation applied in memory, file restored by source plus MD5 (`identical after: True`), exit code read.

```
M1  link always shown (v-if="true"):               exit=1
M2  shown while signed in:                          exit=1
M3  shown with no address:                          exit=1
M4  link drops the address (no query):              exit=1
M5  link goes to sign-in instead of sign-up:        exit=1
M6  sign-up prefill not seeded (email: ''):         exit=1
M7  prefill not trimmed:                            exit=1
M8  prefill not capped at 320:                      exit=1
M9  prefill trusts a non-string (array) query:      exit=1
M10 hu key missing:                                 exit=1
M11 hu key left in English:                         exit=1
restored identical; clean run exit=0
```

Specs run: `EmailVerificationPendingPage.native.spec.ts`, `SignUpPage.spec.ts`, `i18n-signup-flow-strings.spec.ts`.

### Revised link (2026-10-28, walk feedback): names carried, email empty and focused

Same harness, four specs (`SignUpPage.spec.ts`, `SignUpPage.native.spec.ts`, `EmailVerificationPendingPage.native.spec.ts`, `signUpDraft.spec.ts`). The M1-M11 above were against the first version; M6-M9 no longer apply (`?email=` prefill removed).

```
N1  prefill brings the old email back:            exit=1
N2  first name not carried:                       exit=1
N3  last name not carried:                        exit=1
N4  email not focused:                            exit=1
N5  first name focused instead:                   exit=1
N6  focus even when no names were carried:        exit=1
N7  first draft (read of a key nothing sets):     exit=0  INVALID MUTANT (could never fire); replaced by N7b
N7b password read back from the state:            exit=1  (after adding a smuggled password to the first spec)
N8  sign-up success drops the state:              exit=1
N9  names put in the URL query:                   exit=1
N10 change-address drops the names:               exit=1
N11 change-address puts the old address in query: exit=1
N12 first draft (did not compile): not counted;  N12b plain anchor instead of the button: exit=1
N13 not full width:                               exit=1
N14 outlined instead of text:                     exit=1
N15 shown while signed in:                        exit=1
N16 draft not trimmed:                            exit=1
N17 draft not capped at 120:                      exit=1
N18 draft keeps extra keys:                       exit=1
N19 array accepted as a draft:                    exit=1
N20 state leaks the old email:                    exit=1
restored identical (MD5); clean run exit=0
```

N7 and N12 are recorded as they were: the first versions were not real mutants (one could never fire, one broke compilation), so they were redone, not counted.

## W8 follow-up (2026-10-28): back from Security into the wizard, and the incomplete dashboard card

Vitest, `useShellBack.spec.ts` + `CreatorDashboardPage.spec.ts` (48 tests), each mutation restored by source plus MD5.

```
W1  wizard previous entry no longer counts as history:      exit=1
W2  any previous entry counts (sign-in too):                exit=1
W3  incomplete creators still fall back to the dashboard:   exit=1
W4  every creator goes to the wizard:                       exit=1
W5  fallback ignores next_step (always profile):            exit=1
W6  wizard-name test too loose:                             exit=1
W7  null next_step no longer defaults to profile:           exit=1
W8  card links to welcome-back, not the current step:       exit=1
W9  card link ignores next_step:                            exit=1
W10 card links to the dashboard (itself):                   exit=1
W11 card not a link at all (the old behaviour):             exit=1
restored identical; clean run exit=0
```

`CreatorDashboardLayout.spec.ts` (the shell arrow, mounted for real) was not in the mutation set; its "nothing to go back to" case changed meaning (an approved creator keeps the dashboard; an incomplete one lands on its wizard step) and both are specced there.

## Not proven by a break-revert, and why

- **The email field's keyboard on a phone.** `focus()` is proven (spy); whether iOS opens the keyboard for a focus that follows an async page load is a device question (W4 re-check).
- **The centred-card exclusion in `AuthLayout.vue`** (`:not(.auth-layout--native)` on three selectors). jsdom has no CSS cascade, so no unit spec can see that the sheet rules win over the 480 px card rules.
  It is checked by eye on the device walk (W1, W2) and nothing else.
- **`creator-signup-consent.spec.ts` (Playwright).** See the review file §4: whether it has run, and what it proved.
