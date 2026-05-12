# Sprint 1 — Chunk 7 Group 3 Review (sub-chunks 7.5 + 7.6 + 7.7: admin auth pages + admin E2E specs + admin module README + chunk-7 self-review)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards) + § 7 (spot-checks-before-greenlighting) + § 10 (session boundaries); `02-CONVENTIONS.md` § 1 + § 3 + § 4.3 (coverage thresholds, with Playwright E2E exception per chunk-6.8 OQ-3); `04-API-DESIGN.md` § 4 + § 7 + § 8 (error envelope shapes); `05-SECURITY-COMPLIANCE.md` § 6 (admin-specific mandatory 2FA, session-cookie boundary, idle-timeout policy); `07-TESTING.md` § 4 + § 4.4 (Playwright config patterns, hermetic-test discipline, fixture conventions); `20-PHASE-1-SPEC.md` § 5 (admin SPA acceptance criteria) + § 7 (E2E priorities); `security/tenancy.md`; `feature-flags.md`; `tech-debt.md` (one entry closed, no new ones added); all four chunk-6 review files; `sprint-1-chunk-7-1-review.md` (including the post-merge addendum — the nine-hotfix saga's lessons are applied from the first commit, not replayed); `sprint-1-chunk-7-2-to-7-3-review.md` (Group 1's admin store + i18n); `sprint-1-chunk-7-4-review.md` (Group 2's admin router + guards + mandatory-MFA); `sprint-1-chunk-6-plan-approved.md`.

This is the third and final review group of chunk 7. After Group 3 lands, the admin SPA is feature-complete for Sprint 1's admin-side acceptance criteria, has working E2E coverage of its critical auth flows (sign-in, mandatory-MFA enrollment + D7 deep-link preservation), and has documentation in place. The separate `docs/reviews/sprint-1-chunk-7-self-review.md` captures the chunk-7 retrospective (pattern-matching sub-chunk 6.9's chunk-6 self-review).

---

## Scope

Cursor's draft enumerates the full scope across three sub-chunks in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

- **Sub-chunk 7.5 (admin auth pages + layouts):** Four auth pages (`SignInPage`, `EnableTotpPage`, `VerifyTotpPage`, `DisableTotpPage`) + `RecoveryCodesDisplay` component + `useErrorMessage` composable + `AuthLayout` shell + `App.vue` layout switcher. Each mirrors `apps/main/src/modules/auth/**` verbatim except three documented adaptations: no sign-up/forgot-password links on SignInPage, `EnableTotpPage` honors `?redirect=<intended>` (D7 deep-link preservation), admin-branded RecoveryCodesDisplay filename. Two new architecture tests (`auth-layout-shape.spec.ts`, extended `no-recovery-codes-in-store.spec.ts`) pin the carve-outs.

- **Sub-chunk 7.6 (admin E2E specs + CI extension):** Dual-webServer Playwright config with admin port offsets (API `:8001`, Vite `:5174`), global setup, fixtures, selectors, two substantive specs (sign-in happy path + mandatory-MFA enrollment chained-flow journey with D7 deep-link preservation). `.github/workflows/ci.yml § e2e-admin` rewritten to mirror `e2e-main`'s full backend stack (Postgres + Redis + PHP + migrate:fresh + per-run TEST_HELPERS_TOKEN). One new backend test-helper endpoint (`POST /api/v1/_test/users/admin`) gated by the existing chunk-6.1 pattern with 11 Pest cases. **The chunk-7.1 hotfix saga conventions manifest from the first commit — no replay.** The tech-debt entry "Admin SPA Playwright job runs without a Laravel backend" is closed.

- **Sub-chunk 7.7 (closing artifacts):** `apps/admin/src/modules/auth/README.md` mirroring main's chunk-6.9 README with admin-specific design decisions called out (mandatory MFA, D7, session-cookie boundary, e2e-admin CI stack, how-to recipes). The merged Group 3 review file (this file). The separate chunk-7 self-review at `docs/reviews/sprint-1-chunk-7-self-review.md`.

---

## Acceptance criteria — all met

(All Group 3 acceptance criteria from the kickoff — admin auth pages render with 100% Vitest coverage, admin layout switcher works correctly, e2e-admin CI job mirrors e2e-main's full backend stack, admin E2E specs implemented for sign-in + mandatory-MFA enrollment, all chunk-7.1 saga conventions applied from the start, admin module README + chunk-7 self-review in place, all existing tests remain green, lint/typecheck clean, one tech-debt entry closed, no new tech-debt added — all ✅. Reproduced verbatim in Cursor's draft; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — three items

**Ninth instance** in chunk 6 + 7.1 + 7.2-7.3 + 7.4 + 7.5-7.7 of Cursor flagging where the kickoff carried hidden assumptions that didn't hold. **Nine for nine; the pattern is permanent.**

Deviation count for Group 3 is **3 — the lowest yet**. This validates the "file:line citations to main" discipline upgrade introduced in Group 2's review. Paraphrase-vs-actual deviations dropped to **zero** in Group 3; the three remaining deviations are all structurally-correct adaptations driven by admin's stricter security model or by minimal extensions justified by gaps in the production surface.

### D1 — Backend test-helper for admin user provisioning (structurally-correct minimal extension)

**Implicit kickoff assumption:** "Sub-chunks 7.5 and 7.6 don't require new backend endpoints."

**Why it didn't hold:** The production sign-up endpoint (`POST /api/v1/auth/sign-up`) cannot create `platform_admin` users — admin onboarding is documented as out-of-band per `docs/20-PHASE-1-SPEC.md` § 5. The admin SPA's E2E specs therefore cannot seed their own subject through any production surface.

**Alternative taken — accepted:** New `POST /api/v1/_test/users/admin` test-helper endpoint following the chunk-6.1 gating pattern verbatim. The kickoff's "no new backend endpoints" reads as a constraint on production surfaces; test-helper endpoints exist specifically to fill setup gaps the production API cannot serve, gated by `VerifyTestHelperToken` + env + provider. 11 Pest cases pin every branch.

**Why this is structurally correct, not tech debt:** The endpoint has no production surface (`gateOpen()` returns false in production regardless of token). The shape mirrors the existing pattern. The alternative (driving E2E against a Pest-style factory) would break the chunk-6.1 "test helpers are HTTP-only" contract.

### D2 — `RecoveryCodesDisplay` is a path-(b) mirror, not a `@catalyst/ui` extract (structurally-correct admin adaptation)

**Implicit kickoff assumption:** That extracting `RecoveryCodesDisplay` to `@catalyst/ui` was a real alternative.

**Why it didn't (cleanly) hold:** Main's component is internal to main's module tree, not in `packages/ui`. Extracting now would be a chunk-8+ refactor with consumer surface implications AND would move the component out from under the chunk-6.7 / 7.5 architecture-test scope without a clean substitute.

**Alternative taken — accepted:** Path-(b) mirror with admin-specific branding (download filename: `catalyst-admin-recovery-codes.txt`). Architecture test extended to cover admin's copy. Vue 3 attribute fall-through fix from chunk-7.1 manifests in admin's `EnableTotpPage` comment block from the first commit.

**Why this is structurally correct, not tech debt:** Consolidation into `@catalyst/ui` is justified only when a third consumer surfaces (rule-of-three). Two consumers across two SPAs is not yet enough; the duplication is ~150 lines of mostly i18n-string-anchored markup, and the divergence (download filename, locale switcher label) is exactly the "shared, except for…" case that complicates shared-package extracts. Decision criterion + future trigger documented in the auth module README.

### D3 — Admin `EnableTotpPage` honors `?redirect=<intended>` (structurally-correct admin adaptation)

**Implicit kickoff assumption:** "EnableTotpPage.vue + EnableTotpPage.spec.ts — mirror main's."

**Why it didn't fully hold:** Main's `EnableTotpPage.vue` hard-codes `router.push({ name: 'app.dashboard' })` on the post-confirm navigation. Main's 2FA flow is opt-in (no enforcement redirect, nothing to preserve). Admin's chunk-7.4 mandatory-MFA flow rebounds an unenrolled admin's deep-link to `/auth/2fa/enable` with the intended destination preserved (D7 decision from Group 2). For preservation to manifest end-to-end, the `EnableTotpPage`'s post-confirm navigation has to honor the query — main's identical-shape page does not.

**Alternative taken — accepted:** Admin's `EnableTotpPage.vue` reads `route.query.redirect` after the recovery-codes confirmation and navigates there if it's a non-empty string; falls back to `app.dashboard` otherwise. Two extra spec cases pin the branches. The chunk-7.6 `admin-mandatory-mfa-enrollment.spec.ts` exercises the chain end-to-end (deep-link → sign-in → enrollment → land on `/settings`).

**Why this is structurally correct, not tech debt:** The divergence is exactly the chunk-7.4 D7 decision propagated forward by one page. Without this adaptation, the unit-test D7 chained-flow case Claude added during Group 2's spot-check would not have an end-to-end counterpart. The page's docblock cites `guards.ts:92-99` so a future maintainer changing one side sees the other.

### Process record on these three deviations

**The "file:line citations to main" discipline upgrade worked exactly as intended.** Group 2's nine deviations were dominated by paraphrase-vs-actual issues (five out of nine); Group 3's three are dominated by structurally-correct adaptations and minimal extensions (three out of three). This is the discipline upgrade hitting the paraphrase category at the source.

**For Sprint 2 onward:** the "file:line citations to main" discipline is now baseline. Kickoffs should cite specific main files by path + line range when specifying mirrors. The remaining deviations will be the ones that genuinely matter — structurally-correct divergences driven by admin's security model or by production-surface gaps that test-helpers fill.

---

## Standout design choices (unprompted)

Cursor's draft enumerates several design choices. Three deserve highlighting as broadly applicable patterns:

- **Vue 3 attribute fall-through reminder comment block at the consuming call-site, not the consumed component.** Admin's `EnableTotpPage.vue` lines 207-218 carry an explicit comment block citing the chunk-7.1 hotfix and the tech-debt entry by name, immediately above the `<RecoveryCodesDisplay>` invocation. A future contributor adding props to that invocation sees the comment block first. **This is the canonical pattern for any pitfall that's invisible at the call-site but visible from looking at the component's root template.** The comment is at the point of risk, not at the point of safety.

- **`defaultHeaders` constant declared once at the top of the fixture file, forwarded by every API-driven helper.** Lines 69-72 declare the constant; lines 122/166/202/219/260/284/306 forward it. Seven for seven. The pattern means a future fixture author who adds a new API-driven helper can't accidentally omit the headers — the existing helpers' shape is the precedent. **Canonical for any cross-cutting convention that must apply uniformly across a fixture set.**

- **Convention captured as durable contract in fixture docblock, even when not exercised by the current spec.** The chunk-7.1 saga's T0 = `Date.now() + 30 days` rule lives in the `setClock` fixture's docblock even though neither Group 3 spec uses `setClock`. The next spec that needs clock-pinning won't replay the saga because the rule is durable in the fixture API, not just in the specs that currently exercise it. **Canonical for any chunk-7.1-style saga lesson that needs to survive across specs that don't yet exist.**

---

## Decisions documented for future chunks

- **Module READMEs follow the five-section pattern** when the module has loudly-different-from-main decisions: "Where to start reading" + "Architecture tests that protect the module" + "Recurring patterns" + chunk-specific design decisions + how-to recipes. Established for admin's auth module in 7.7; pattern is per-module.

- **Test-helper endpoints fill seeding gaps that production surfaces cannot serve.** Established by D1. Future Sprint-2+ E2E work that needs subjects the production API can't create (admins, system roles, special-case users) follows the same shape: test-helper endpoint gated by the chunk-6.1 pattern, mirror existing test-helper conventions, Pest coverage of every branch.

- **Path-(b) mirror is the default for shared-shape components across SPAs; `@catalyst/ui` extraction waits for a third consumer.** Established by D2. The rule-of-three threshold prevents premature consolidation; the divergence cost is bounded by the existing architecture tests pinning each SPA's copy.

- **D7-style cross-page/cross-router behavior must propagate end-to-end with file-to-file citations.** Admin's `EnableTotpPage` cites `guards.ts:92-99`; the guards cite the page; the chained-flow spec exercises both. Future cross-cutting behavior (idle timeout, audit logging, theme preference) follows the same shape — both sides of the boundary cite each other.

- **Port-offset convention `+1` for concurrent CI E2E jobs.** Established by 7.6 (admin: API `:8001`, Vite `:5174` vs main's `:8000`, `:5173`). Future SPA E2E jobs use `+2` from main if any.

- **Vite proxy target env-overridable via per-SPA env var (`CATALYST_<SPA>_API_PROXY_TARGET`).** Established by 7.6 for admin. Lets Playwright config override the proxy target without forking the Vite config.

- **Chunk-7.1 hotfix saga conventions are now baseline in every kickoff.** Specifically: per-spec auth-ip neutralization, T0 = Date.now() + 30 days baseline, shared `defaultHeaders` constant, no parent `data-test` attribute fall-through, backend `meta.seconds` envelope shape, prefix-allowlist error resolver. Sprint 2+ kickoffs should reference the chunk-7.1 saga conventions as a single named convention rather than re-listing them.

---

## Tech-debt items

**One entry closed:**

- **"Admin SPA Playwright job runs without a Laravel backend"** → closed in Group 3 via the e2e-admin CI extension (Postgres + Redis service containers, PHP setup, migrate:fresh, TEST_HELPERS_TOKEN rotation, dual webServer in playwright.config.ts).

**No new entries opened by Group 3.** The three Group 3 deviations are structurally-correct adaptations with documented decision criteria + future-trigger conditions; they do not warrant tech-debt entries.

**Pre-existing items from chunks 6 + 7.1 + 7.2-7.3 + 7.4 remain open** (SQLite-vs-Postgres CI, TOTP issuance does not honor `Carbon::setTestNow()`, `auth.account_locked.temporary` `{minutes}` interpolation gap, Laravel exception handler JSON shape for unauthenticated `/api/v1/*`, test-clock × cookie expiry interaction, Vue 3 attribute fall-through architecture test, idle-timeout unwired on both SPAs). None are triggered by Group 3 work; all remain durably tracked for their respective trigger conditions.

---

## Verification results

| Gate                                             | Result                                                                                                                                      |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pint                                  | Pass                                                                                                                                        |
| `apps/api` PHPStan (Larastan level 8, 217 paths) | Pass — 0 errors                                                                                                                             |
| `apps/api` Pest                                  | 367 assertions passing (+11 new for the test-helper endpoint)                                                                               |
| `apps/main` typecheck / lint / Vitest            | Pass / Pass / 234 passed                                                                                                                    |
| `apps/admin` typecheck / lint                    | Pass / Pass                                                                                                                                 |
| `apps/admin` Vitest                              | 180 passed across 18 spec files; 100% coverage on `src/modules/auth/**` + `src/core/router/**` + `src/core/api/**`                          |
| `packages/api-client` Vitest                     | Pass                                                                                                                                        |
| Repo-wide `pnpm -r lint` / `typecheck`           | Clean                                                                                                                                       |
| Architecture tests                               | All standing tests green; two new admin tests (`auth-layout-shape.spec.ts`, extended `no-recovery-codes-in-store.spec.ts`)                  |
| Playwright `pnpm test:e2e`                       | Deferred to CI per standing convention — the chunk-7.6 stack (Postgres + Redis + Laravel API + Vite + Playwright) is the durable proof site |

**Total non-E2E test count:** 234 main Vitest + 180 admin Vitest + 367 backend Pest = **781 tests, all passing.**

---

## Spot-checks performed

One spot-check, six conventions verified.

**Spot-check — chunk-7.1 saga conventions manifest in admin E2E from the start** (load-bearing review priority #1; the whole point of Group 3's E2E was "don't replay the chunk-7.1 saga"):

| Convention                                                                                                        | Verdict                                          | Evidence                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| (a) Per-spec `auth-ip` neutralization in `beforeEach` + restore in `afterEach`                                    | **Green**                                        | Both specs apply, named in docblocks as "saga finding #1". `admin-sign-in.spec.ts:58/62`; `admin-mandatory-mfa-enrollment.spec.ts:70/74`.                                                                                                                                                                                                                                                                                                                        |
| (b) T0 = `Date.now() + 30 days` baseline for `setClock` use                                                       | **Green by absence + fixture docblock contract** | Neither admin spec pins the clock; both call `resetClock` in `afterEach` belt-and-suspenders. The `setClock` fixture docblock carries the T0 rule for the next spec that needs it. Saga findings #2 + #6 manifest as durable contract waiting to be consumed.                                                                                                                                                                                                    |
| (c) Shared `defaultHeaders` constant on every API-driven fixture call                                             | **Green**                                        | Declared once at fixture file lines 69-72; forwarded by 7-for-7 helpers (signUpAdminUser, mintTotpFromSecret, setClock, resetClock, neutralizeThrottle, restoreThrottle, signOutViaApi). Saga finding #3 manifests from the first commit.                                                                                                                                                                                                                        |
| (d) No parent `data-test` attribute fall-through onto `<RecoveryCodesDisplay>` (chunk-7.1 hotfix #6 anti-pattern) | **Green**                                        | `EnableTotpPage.vue:219` invokes `<RecoveryCodesDisplay v-else :codes="recoveryCodes" @confirmed="onCodesConfirmed" />` — only `:codes` and `@confirmed` props, no `data-test`. Lines 207-218 carry an explicit reminder comment block citing the chunk-7.1 hotfix and tech-debt entry. Saga finding #4 manifests from the first commit. **This is the marquee verification** — future contributors who add props to this invocation will see the comment block. |
| (e) Backend `meta.seconds` envelope consumed by `useErrorMessage`                                                 | **Green**                                        | Resolver reads `details[0].meta.seconds` / `.meta.minutes` (useErrorMessage.spec.ts lines 49/53/62/66/117/121). Chunk-7.1 prefix-widening case pinned (line 112). Saga finding #5 manifests in resolver + spec.                                                                                                                                                                                                                                                  |
| (f) Idle-timeout absence (D6 deferred-to-future-security-sprint boundary)                                         | **Green**                                        | Zero code references to `useIdleTimeout` or `idle.*timeout` anywhere in `apps/admin/src/modules/auth/`, `apps/admin/src/App.vue`, or `apps/admin/src/main.ts`. Single README forward-reference describing the deferred path. D6 boundary intact.                                                                                                                                                                                                                 |

**Verdict: six for six green.** The chunk-7.1 saga's nine hotfixes produced six durable conventions that admin manifests from commit 1. No replay.

**Diff stat:** 17 modified files (+424/-124), 27 untracked files (+~4,131 lines). Shape matches expectations: backend test-helper controller + Pest test + route registration; admin SPA surface (pages + components + composables + layouts + helpers); Playwright stack (config + global-setup + fixtures + selectors + 2 specs); CI workflow extension; 2 review files; admin module README; closed tech-debt entry.

---

## Cross-chunk note

None this round. Confirmed:

- Chunks 6.2–6.4 main store invariants intact; admin's mirror consumes them through admin-specific endpoints.
- Chunks 6.5–6.7 main router + pages invariants intact; admin's mirrors are path-(b) copies, not extensions.
- Chunks 6.8–6.9 main Playwright + selectors invariants intact; admin's mirrors are independent under `apps/admin/playwright/`.
- Sub-chunk 7.1 hotfix saga's findings are manifest in admin E2E from the first commit (spot-check verified).
- Groups 1 + 2 admin foundations consumed correctly by Group 3.
- The chunk-6.1 `App\TestHelpers` gating contract holds for the new `CreateAdminUserController` — same env+token+provider gating shape.

---

## Process record — compressed pattern (ninth instance) + closing chunk 7

The compressed pattern continues to hold. Group 3 was three sub-chunks in one Cursor session — the highest density yet in the project. The result is what the Option A grouping decision predicted: chunk-7 closure work consolidated into one review round, no loss of review quality.

Specific observations:

- **Three sub-chunks in one session:** the cognitive load on Cursor was manageable. Plan-then-build worked across three layers (pages + E2E + closing artifacts) without confusion; the single completion artifact (one merged review file + one self-review file) didn't obscure any layer. **Confirms Option A grouping as the default for chunk-closing work in Sprint 2+.**

- **The "file:line citations to main" discipline upgrade was the highest-leverage process refinement in chunk 7.** Group 2's nine deviations were dominated by paraphrase-vs-actual issues; Group 3's three are dominated by structurally-correct adaptations. The discipline upgrade hit the paraphrase category at the source. **Now baseline in every kickoff.**

- **Mid-spot-check disciplined self-correction was not needed this round.** Cursor's draft was complete and accurate; spot-check verified rather than surfaced new gaps. This is the pattern operating at full maturity — the discipline upgrades introduced through chunks 6 + 7 mean fewer corrections are needed at review time because more are caught at build time.

- **Single spot-check round was sufficient.** Per the operating-mode adjustment after chunk-7.1, I'm doing fewer spot-checks by default; the one spot-check targeted the load-bearing review priority (chunk-7.1 saga conventions manifest from the start) and confirmed all six conventions green. No further verification needed.

- **Zero change-requests** for the third consecutive review group (sub-chunk 7.1, Group 1, Group 2 each closed with zero or near-zero change-requests; Group 3 is the cleanest yet). The combination of compressed pattern + honest deviation flagging + file:line citations + disciplined self-correction + load-bearing-spot-check selection is operating at full effectiveness.

**Chunk 7 closure:** the merged Group 3 review (this file) + the chunk-7 self-review together close chunk 7. After Group 3 lands, Sprint 1's admin-side scope is complete. Sprint 2 owns admin's substantive console surface (creator onboarding admin views, brand management admin views, theme system, admin nav shell, admin settings page substantive UI).

---

## What chunk 7 closes for Sprint 1

- ✅ Admin SPA feature-complete for Sprint 1 acceptance criteria: Pinia store + i18n + router + guards + mandatory-MFA enforcement + auth pages + layouts + E2E coverage of critical flows.
- ✅ Admin module documentation in place (`apps/admin/src/modules/auth/README.md`).
- ✅ Chunk-7 retrospective captured (`docs/reviews/sprint-1-chunk-7-self-review.md`).
- ✅ One tech-debt entry closed (admin Playwright backend); no new entries opened by Group 3.
- ✅ Sprint 1 critical-path E2E priorities for the auth surface fully met (specs #19 + #20 from chunk-7.1; admin sign-in + mandatory-MFA enrollment from chunk-7.6).
- ✅ Chunk-7.1 hotfix saga's lessons manifest in admin E2E from the first commit — no replay.

**Sprint 1 status after chunk 7:** structurally complete pending chunk 8 (theme system + remaining sprint cleanup). The admin SPA is feature-complete; the main SPA is feature-complete; backend is feature-complete; only the cross-cutting theme system and any remaining sprint-cleanup items remain.

---

_Provenance: drafted by Cursor on Group 3 completion (compressed-pattern process across three sub-chunks per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with one targeted spot-check covering all six chunk-7.1 saga conventions (load-bearing review priority #1). Three honest deviations surfaced and categorized (1 structurally-correct minimal extension, 2 structurally-correct admin adaptations), all resolved with structurally-correct alternatives. The "file:line citations to main" discipline upgrade introduced in Group 2's review dropped paraphrase-vs-actual deviations to zero in Group 3. The pattern of "every chunk-6 + 7.1 + 7.2-7.3 + 7.4 + 7.5-7.7 group catches at least one hidden assumption" is now nine-for-nine. Status: Closed. No change-requests; Group 3 lands as-is. **Closes chunk 7 — Sprint 1 admin-side scope complete.**_

---

## Post-merge addendum — Group 3 hotfixes (commits `2e31a19` + `4ff9bb6`)

Appended after the merged review's main body. This addendum is the durable record of what surfaced AFTER Group 3's work commit (`d457cb1`) and chunk-7 closure commit (`2706632`) landed on `main` and CI ran for the first time against the new admin E2E job + the chunk-7-closure docs.

### Final commit lineage on `main`

- `4ff9bb6` — `test(identity): deterministic signature tampering in VerifyEmailTest [flake fix]` (hotfix #2)
- `2e31a19` — `fix(admin-auth): match literal '/' in D7 redirect URL regex [post-chunk-7 hotfix]` (hotfix #1)
- `2706632` — `docs(reviews): close chunk 7 — sprint 1 admin-side scope complete` (closure)
- `d457cb1` — `feat(admin-auth): admin auth pages + E2E + CI extension (sprint 1, chunk 7 group 3 closes chunk 7)` (work)

CI run after hotfix #2: all four jobs green. Frontend lint+typecheck+Vitest, Backend Pint+Larastan+Pest, E2E admin SPA, E2E main SPA.

### Two hotfixes surfaced after the closure push

**Hotfix #1 — D7 deep-link URL regex mismatch in admin mandatory-MFA enrollment spec.**

- **Surface:** `apps/admin/playwright/specs/admin-mandatory-mfa-enrollment.spec.ts` (the chunk-7.6 load-bearing D7 spec).
- **Symptom:** the spec failed all three Playwright retries on the assertion `await expect(page).toHaveURL(/\/sign-in\?redirect=%2Fsettings$/)` — expected pattern wanted percent-encoded `%2F`, received string had literal `/settings`. A second assertion against `/auth/2fa/enable\?redirect=%2Fsettings` had the same shape.
- **Root cause:** Vue Router serialises `query: { redirect: '/settings' }` with a **literal `/`**, not percent-encoded `%2F`. Slashes are reserved-but-permitted in the query component per RFC 3986 § 3.4, and Vue Router does not encode them. The spec assertions were paraphrasing a percent-encoding assumption that doesn't hold against real router output.
- **Not a guard bug:** `apps/admin/src/core/router/guards.ts:92-99` was correctly redirecting on every retry; the spec was just asserting against the wrong encoding. Trace evidence: the call log showed the page reaching `/sign-in?redirect=/settings` (literal `/`) on every attempt — the assertion never matched because the regex was wrong, not because the redirect was wrong.
- **Fix:** two regex updates (`%2F` → `\/`) plus a docblock paragraph capturing the RFC 3986 § 3.4 finding so the next reviewer doesn't repeat the encoding assumption. Lint + typecheck green pre-push.
- **Category:** spec-side assumption error, surface-localised. Production code untouched. The chunk-7.4 guard logic + the chunk-7.5 EnableTotpPage's `?redirect=` honor logic both remain unchanged and were validated as correct by the trace.

**Hotfix #2 — Pre-existing `VerifyEmailTest` tampering-logic flake.**

- **Surface:** `apps/api/tests/Feature/Modules/Identity/VerifyEmailTest.php:111-117`.
- **Symptom:** the test `it returns 400 with auth.email.verification_invalid for a tampered signature` failed on the chunk-7 closure CI run (which only modified `docs/reviews/sprint-1-chunk-6-plan-approved.md` — zero backend code changes between work-commit green run and closure-commit failing run).
- **Root cause:** the tampering line was `$tampered = $payload.'.'.($signature === 'a' ? 'b' : 'a'.substr($signature, 1));`. It compared the **whole** signature against the literal single-character string `'a'` (never true for a real signature) and then unconditionally prepended `'a'` to `substr($signature, 1)`. When the random signature's first byte already was `'a'`, the "tampered" token was **byte-identical to the original** → backend correctly returned 204 success → assertion of 400 failed. Probabilistic flake at ~1/64 over the base64 signature alphabet.
- **Pre-existing, NOT chunk-7 caused:** the test was unchanged by chunk-7's work; the work-commit run happened to land on a signature whose first byte was NOT `'a'`, the closure-commit run happened to land on one whose first byte WAS `'a'`. Same code path, different random seed.
- **Fix:** check the FIRST CHARACTER, not the whole signature: `($signature[0] === 'a' ? 'b' : 'a').substr($signature, 1)`. Now if the first byte is `'a'`, swap to `'b'`; else swap to `'a'`. Deterministic, zero remaining probability surface. Plus an inline comment explaining the prior bug so the next contributor doesn't reintroduce it.
- **Verified deterministic:** 30 sequential local runs all pass. Under the prior logic, the expected flake count over 30 runs would be ~0.47 (probabilistically ~1/64); under the fix it's exactly zero.
- **Category:** pre-existing test-fixture flake, surface-localised. Production code untouched, no other tests affected.

### What this validates

- **The no-bundling-under-hotfix-pressure convention held cleanly.** Two distinct root causes → two distinct commits with distinct conventional-commit scopes (`fix(admin-auth)` for the chunk-7 hotfix, `test(identity)` for the pre-existing flake). The chunk-7 hotfix's scope and the unrelated flake fix were kept in separate commits per the kickoff's modification #8. Same discipline as the chunk-6 hotfix saga + the Group 2 hotfix.
- **The chunk-6 hotfix saga's "scope-discipline-under-pressure" pattern continues to operate cleanly without Claude in the loop.** Cursor identified both root causes, scoped them as separate hotfixes (refusing to bundle), verified each fix locally (30× stress-test for the determinism claim), and pushed both to green CI autonomously.
- **Commitlint discovered a kebab-case-with-digits gap.** Cursor's initial commit message `fix(admin-e2e): ...` was rejected by commitlint's default kebab-case rule (digits aren't allowed inside kebab-case scopes). Cursor pivoted to `fix(admin-auth): ...` to match the work-commit's scope and preserve lineage clarity. Worth recording: the project's commitlint config rejects scopes containing digits — future hotfix scopes should use letter-only kebab forms (`admin-auth`, `spa-auth`, `admin-playwright`, etc.). No config change needed; just a convention to keep in mind.
- **The "diagnostic uncertainty about flake-vs-not" was resolved correctly.** When CI failed on a docs-only commit, the working hypothesis was either (a) a pre-existing flake or (b) a real backend bug introduced by something unrelated. The trace pointed unambiguously at (a) once the tampering logic was read carefully. Worth recording as a process pattern: when CI fails on a commit that touched zero production code, "look for a flake in the unchanged tests" is the right first hypothesis, not "look for an environmental issue."

### Open tech-debt items after this hotfix

- No new tech-debt entries opened by either hotfix. Hotfix #1 was a spec-side encoding assumption fix; hotfix #2 was a pre-existing tampering-logic fix. Both are surface-localised and require no follow-up.
- All pre-existing tech-debt entries from chunks 6 + 7 remain unchanged by these hotfixes.

### Final state for Group 3 + chunk 7

- ✅ Group 3 work merged on `d457cb1`.
- ✅ Chunk 7 closure docs merged on `2706632`.
- ✅ D7 deep-link spec hotfix merged on `2e31a19`.
- ✅ VerifyEmailTest determinism fix merged on `4ff9bb6`. CI green.
- ✅ Sub-chunks 7.5 + 7.6 + 7.7 fully closed. Chunk 7 fully closed. No further follow-ups expected against this review file.

**Sprint 1 admin-side scope complete. Sprint 1 proceeds to chunk 8 (theme system + remaining sprint cleanup).**
