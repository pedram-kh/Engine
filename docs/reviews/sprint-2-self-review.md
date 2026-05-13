# Sprint 2 Self-Review (closing artifact for Sprint 2)

**Status:** Closed.

**Reviewer:** Claude (independent review pass) — incorporating Cursor's self-review draft and the mid-review R1 finding closure.

**Scope:** Closing retrospective for Sprint 2 as a whole. Captures Sprint 2's full arc: Chunk 1 (backend — brands, invitations, agency settings, test-helper infrastructure); Chunk 2 (frontend close-out — agency layout shell, brand CRUD UI, invitation UI, settings UI, E2E specs + Chunk-1→Chunk-2 handoff completions).

---

## (a) What Sprint 2 produced end-to-end

Sprint 2 took the Sprint 1 identity platform and extended it into a functioning multi-tenant agency workspace. After Sprint 2, a Catalyst admin can:

1. Sign in to the main SPA.
2. See the agency layout shell (sidebar + top bar + user menu) immediately after authentication.
3. Navigate to brands, create a brand, view it, edit it, and archive it.
4. Invite a team member via email; the invitee receives a magic link and can accept the invitation from the SPA.
5. Adjust agency default currency and language from the settings page.
6. Sign out via the user menu.

**Sprint 2 — Chunk 1 (backend):**

- Multi-tenancy: `Agency`, `AgencyMembership` (pivot), `AgencyUserInvitation`, `Brand` models with soft deletes and ULID public identifiers.
- Brand CRUD: create, show, update (paginated index with status filter), archive (soft-delete + `status=archived`). `BrandPolicy` enforces membership + role. `BrandStatus` enum.
- Invitation system: single-use-with-retry-on-failure tokens (sha256 hashed, Chunk 1 Q1); `invite()`, `accept()` service methods; `AgencyInvitationService` sends `InviteAgencyUserMail`; dedicated accept endpoint (Chunk 1 Q2: Option B).
- Agency settings: `GET`/`PATCH` endpoint with `agency_admin` enforcement. `AgencySettingsResource` returns `default_currency` + `default_language`.
- Test-helper infrastructure: `POST /api/v1/_test/agencies/{agency}/invitations` provisions invitation + returns unhashed token.
- Larastan level 8 + Pint + Pest (452 tests at close, all passing).

**Sprint 2 — Chunk 2 (frontend + handoff gap resolutions):**

- Backend handoff gaps resolved: `UserResource` extended with `agency_memberships` relationship; magic link URL extended with `&agency=<ulid>`; unauthenticated preview endpoint `GET /api/v1/agencies/{agency}/invitations/preview` added; `POST /api/v1/_test/agencies/setup` one-shot E2E provisioning added.
- `@catalyst/api-client` types extended: `AgencyMembershipData`, `BrandResource`, `AgencyInvitationResource`, `AgencySettingsResource`, `InvitationPreview`, `PaginatedCollection<T>`, and all payload types.
- `useAgencyStore`: new Pinia store managing current agency context, seeded from `/me` response, `localStorage`-persisted.
- `AgencyLayout`: sidebar + top bar + workspace switcher (hidden when single membership — Q2) + user menu (ThemeToggle, locale switcher, sign-out).
- `App.vue` / `AuthLayout.vue`: `'agency'` layout added; `ThemeToggle` removed from auth-layer mounts and consolidated into AgencyLayout's user menu.
- Brand pages: `BrandListPage` (server-side paginated, status filter, empty state, skeleton, archive dialog), `BrandCreatePage`, `BrandDetailPage`, `BrandEditPage`, shared `BrandForm`.
- Invitation pages: `AgencyUsersPage` + `InviteUserModal` (Q1: modal); `AcceptInvitationPage` (10 distinct named states — the chat completion summary undercounted to 6; see Chunk 2 review spot-check 2 for full enumeration).
- Settings page: `SettingsPage` (read-only for non-admin, save wired).
- Router: 8 new routes; `requireAgencyAdmin` guard added (with independent unit-test coverage closed mid-review via R1 finding — 3 new tests).
- i18n: full coverage across en/pt/it for all new surfaces.
- E2E Playwright: `brands.spec.ts`, `invitations.spec.ts`, `permissions.spec.ts`. All chunk-7.1 conventions applied from first commit (six-for-six verified — see Chunk 2 review spot-check 3).
- Test counts at close: 298 main Vitest (+12 from Sprint 1 close: 9 for useAgencyStore + 3 for R1), 232 admin Vitest (unchanged), 462 backend Pest (+10), 17 design-tokens, 88 api-client = **1097 tests, all passing.**

---

## (b) Team standards established or extended in Sprint 2

All Sprint 1 standing standards carry forward (per `docs/PROJECT-WORKFLOW.md § 5`). Sprint 2 extends the list:

1. **Cross-chunk handoff contract verification** — consuming chunk's read pass must explicitly verify URL shape, auth shape, and all path/query parameters for every endpoint provided by the prior chunk. First established in Chunk 2's retrospective when D_new_2 surfaced (magic link URL missing agency identifier). Applies Sprint 3 onwards.

2. **Test-helper one-shot provisioning pattern** — `CreateAgencyWithAdminController` follows `CreateAdminUserController` (chunk 7.6 shape): creates the full subject + dependencies in a single call, returns all identifiers needed for E2E specs. No multi-step provisioning. Pattern is now established for agency-scoped subjects.

3. **Module-scoped API files** — every new module has its own `<module>.api.ts` re-export (`brands.api.ts`, `invitations.api.ts`, `settings.api.ts`). Established in auth module (chunk 6.4), now codified for all Sprint 2 modules.

4. **AgencyLayout is the authenticated shell** — all post-auth agency-scoped routes use `meta.layout: 'agency'`. No new routes should use `meta.layout: 'app'` for authenticated agency surfaces going forward.

5. **Architecture test allowlist discipline** — any non-theme localStorage usage requires an allowlist entry in `use-theme-is-sot.spec.ts` AND a tech-debt entry in `docs/tech-debt.md`. First exercised by `useAgencyStore` (D_new_5). Pattern is documented.

6. **`vue/valid-v-slot: allowModifiers: true`** — required for Vuetify `v-data-table` with nested key slots. ESLint config updated.

7. **Every defense-in-depth layer requires independent test coverage.** Established by Sprint 2's two instances: Chunk 1's `BrandPolicy` coverage gap (closed mid-review with `BrandPolicyTest` adding 17 tests); Chunk 2's `requireAgencyAdmin` coverage gap (closed mid-review with R1 finding adding 3 new tests). Both were surfaced by the break-revert empirical verification pattern; both were closed mid-review. When integration tests pass with a layer broken, that layer is structurally untested even if it's nominally enforced. **Pattern is now baseline standing standard for Sprint 3+.**

These additions should land in `docs/PROJECT-WORKFLOW.md § 5` so they survive into Sprint 3 sessions. Cursor adds them during the closing commit per the established workflow.

---

## (c) Honest deviation tally (both chunks)

| ID           | Chunk | Category                                                | Summary                                                                                                                                                  |
| ------------ | ----- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1 (Chunk 1) | 1     | structurally-correct adaptation                         | Laravel Pint CI vs. sandbox discrepancy — trust CI or `required_permissions: ["all"]` for authoritative Pint; sandbox runs not authoritative.            |
| D2 (Chunk 1) | 1     | structurally-correct adaptation                         | `BrandController::destroy()` returns 200 with updated resource instead of 204 — matches the backend's "archive is a soft-delete with payload" semantics. |
| D_new_1      | 2     | structurally-correct Chunk-1→Chunk-2 handoff completion | `UserResource` extended with `agency_memberships` relationship.                                                                                          |
| D_new_2      | 2     | structurally-correct Chunk-1→Chunk-2 handoff completion | Magic link URL extended with `&agency=<ulid>`; preview endpoint added (with user-enumeration defense).                                                   |
| D_new_3      | 2     | structurally-correct minimal extension                  | New `POST /api/v1/_test/agencies/setup` test-helper.                                                                                                     |
| D_new_4      | 2     | structurally-correct minimal extension                  | `UserResource.relationships?` optional for Sprint 1 unit-test corpus compat.                                                                             |
| D_new_5      | 2     | tech-debt-flagged carry-forward                         | `useAgencyStore` direct localStorage (allowlist + tech-debt note).                                                                                       |
| D_dash       | 2     | structurally-correct adaptation                         | Dashboard route layout changed from `'app'` to `'agency'`.                                                                                               |

**Chunk 1 also recorded four backend-spec-gap deviations** (`brands.status` column not in `03-DATA-MODEL.md`; `agency_user_invitations` table required because `agency_users.user_id` is NOT NULL; settings columns already present in agencies table; explicit `assertBelongsToAgency()` controller-level check due to `SubstituteBindings` ordering) — captured in `docs/reviews/sprint-2-chunk-1-review.md`. Not re-counted here to avoid double-counting; the Chunk 1 review is the durable record for those.

**Running tally across Sprint 2:** 8 deviations explicitly recorded at sprint-scope + 4 deviations recorded at Chunk 1 review scope. **Across the project (Sprint 1 + Sprint 2):** thirteen review groups, thirteen-for-thirteen on the honest-deviation-flagging pattern.

---

## (d) Compressed-pattern process record

Sprint 2 used the compressed plan-then-build pattern established in Sprint 1. Each chunk ran as a single session: plan → build → verify → review artifact.

**Chunk 1** (backend): One session. One Pint hotfix (sandbox vs. CI discrepancy — same pattern as Sprint 1 chunk 7.1). One mid-spot-check coverage gap surfaced and closed (`BrandPolicyTest` added; defense-in-depth without independent coverage pattern's first instance). Zero change-requests on the review pass.

**Chunk 2** (frontend): One session (the largest single session to date). Two pause conditions caught during the pre-planning read pass and resolved before the plan was finalized. Three explicit design questions (Q1-Q3) answered with reasoning in the plan response and durably recorded in the Chunk 2 review. One mid-review coverage gap surfaced and closed (`requireAgencyAdmin` 3 new tests; R1 — the defense-in-depth pattern's second instance). Zero change-requests on the review pass.

**Total Sprint 2 round-trips with Claude:** 2 (one per chunk) + 1 Cursor-solo Pint hotfix + 2 mid-review extensions (BrandPolicyTest + R1) — effectively under-budget vs the 5-8 round-trip estimate.

The pre-planning read list (27 files in Chunk 2) is load-bearing: it is what caught D_new_2 (the magic link URL mismatch) before any frontend code was written. Without the read pass, the frontend would have been built against an incorrect assumption and the bug would have surfaced only in E2E testing.

---

## (e) What is deferred to Sprint 3+

- **Workspace switching full UX** — `router.go(0)` reload is a placeholder; proper route-param-based tenant navigation lands Sprint 3.
- **Agency users list pagination** — current implementation shows `useAgencyStore.memberships` (from bootstrap). A dedicated `/agencies/{agency}/members` paginated endpoint is needed for a complete team roster.
- **Brand restore UI** — backend supports it; frontend deferred.
- **Full dashboard surface** — placeholder page still in place.
- **Invitation history in agency users page** — pending/expired invitations list requires a list endpoint not shipped in Sprint 2.
- **Creator-side workspace access** — Sprint 3+ feature.
- **Campaigns, Assets, Creators sidebar items** — extensible structure is in place; content arrives in Sprint 3+ (Campaigns specifically at Sprint 8).
- **MFA guard on agency routes** — currently `requireAuth` only; `requireMfaEnrolled` should gate all agency routes in Sprint 3 when the MFA requirement is enforced.
- **Email-mismatch + already-member states on `AcceptInvitationPage` automated coverage** — within-policy per kickoff (page tests focus on user-facing behavior, not branch enumeration), but worth automated coverage in Sprint 3+ given they're reachable only via the accept error path.
- **End-user help documentation** — deferred to Sprint 11-12 per `docs/tech-debt.md`; Cursor drafts from codebase + review files + specs; Claude reviews; user refines.

---

## (f) Cursor-side observations

1. **Pause conditions are load-bearing.** Both D_new_1 and D_new_2 would have caused silent runtime failures with no obvious failure mode. The mandatory pre-planning read pass is the mechanism that prevents these from reaching E2E testing. The investment in the 27-file read list paid off.

2. **The `UserResource.relationships?` decision is calibrated.** Making it required would have forced 8+ existing spec files to add non-functional `relationships` fields. Making it optional risks callers not handling the undefined case. The `?.` optional chaining + `?? []` default in `setUser` makes the optional safe without a breaking change.

3. **The architecture test ecosystem is working.** The `use-theme-is-sot.spec.ts` test caught `localStorage` usage in `useAgencyStore` immediately. The allowlist + tech-debt discipline is the correct resolution. No rules were bypassed; the rule had always said "extend the allowlist with a tech-debt note for non-theme uses."

4. **Sprint 2 test count growth:** 452 → 462 backend (+10), 286 → 298 main Vitest (+12: 9 for useAgencyStore, 3 for requireAgencyAdmin gap closure), 232 → 232 admin Vitest (unchanged). Total: 992 tests at intermediate (post-Chunk-2-pre-merge) state; **1097 tests at final close** including api-client + design-tokens. All green.

5. **The `'agency'` layout dispatch in App.vue is clean.** Three-way dispatch with `v-if / v-else-if / v-else` ensures exactly one `<v-app>` per route — the invariant established in chunk 6.8 is preserved.

---

## (g) Claude-side observations

Endorsing Cursor's (f) observations; adding the reviewer-side perspective.

**Sprint 2 surfaced the "defense-in-depth without independent coverage" pattern twice.** Chunk 1's `BrandPolicy` had no independent test coverage; Chunk 2's `requireAgencyAdmin` had no independent test coverage. Both were defense-in-depth layers protecting the same invariant as another layer (HTTP middleware in Chunk 1; UI gating + backend policy in Chunk 2). Both passed integration tests because the other layers caught violations first. Both were surfaced by the break-revert empirical verification pattern (chunk 8 baseline). Both were closed mid-review without round-trip. **The pattern is now reliable enough to record as a baseline standing standard for Sprint 3+:** every defense-in-depth layer requires independent test coverage. The integrated stack passing tests is not proof of layer-by-layer correctness. Worth landing in `PROJECT-WORKFLOW.md § 5` so it survives the new-thread reset.

**The break-revert empirical verification pattern continues to be the highest-leverage spot-check tool.** Without it, both Sprint 2 coverage gaps would have shipped. The pattern's value compounds: each gap surfaces a missing test; the missing test becomes durable regression protection; the workflow standard becomes baseline.

**Cross-chunk handoff verification is the new baseline (§ b #1).** The Chunk 1 review missed the magic-link-URL gap (D_new_2). Cursor caught it during Chunk 2's read pass — but only because the read pass was thorough. **For Sprint 3+:** the consuming chunk's read pass must verify URL shape + auth shape + path/query parameters for every endpoint provided by the prior chunk. The producing chunk's review is necessary but not sufficient; the consumer's verification is independent and authoritative.

**The Cursor sandbox Pint discrepancy (D1) is a real, durable infrastructure constraint.** Sprint 2 Chunk 1's hotfix proved sandbox Pint output can lie. **For Sprint 3+:** never let "sandbox Pint says clean" be the final word on Pint compliance. Trust CI or run with `required_permissions: ["all"]`. Worth landing in `PROJECT-WORKFLOW.md § 5` as a standing standard.

**Two Claude-side discipline lessons surfaced this sprint.** Both worth recording so they don't repeat in Sprint 3:

- **Read the review file before producing the merge.** Mid-Sprint-2, the merged review for Chunk 1 was initially produced from the chat completion summary alone — Cursor's review file content was never loaded into Claude's context. The user caught it before commit; the correction added three specific implementation decisions that the chat summary had under-represented (`invitation.expired_on_attempt` audit emphasis, accept-endpoint-not-under-tenancy.agency middleware shape, mailable test count breakdown). **For Sprint 3+:** Claude must require both review files in context before producing the merge. Chat summary is structural orientation; review files are the durable record.

- **Chat summaries can undercount; review files are authoritative.** Spot-check 2 surfaced that the chat summary said `AcceptInvitationPage` had "6 states" while the actual implementation has 10 distinct states. The chat summary compressed detail; the review file preserved it. **For Sprint 3+:** when chat summaries and review files disagree on counts or enumerations, defer to the file.

**The user-enumeration defense in the preview endpoint deserves explicit recording.** The preview endpoint returns `{agency_name, role, is_expired, is_accepted}` without exposing the email. If preview returned the email, an attacker with token guessing could enumerate invitee emails per agency. Token-only preview with generic-404-on-not-found preserves the existing security posture. **General principle for Sprint 3+:** unauthenticated endpoints returning data about authenticated subjects must never expose enumerable identifiers. Worth landing in `PROJECT-WORKFLOW.md § 5` or `docs/security/tenancy.md` as a worked example.

**Sprint 2 reached genuine workflow stability AGAIN.** Zero change-requests across two consecutive review groups (Chunk 1 + Chunk 2), plus two mid-review coverage extensions and two pause-condition catches and one Cursor-solo Pint hotfix — all handled within the established workflow without breaking it. **The compressed pattern + Option B grouping + pre-planning read pass + break-revert empirical verification + design Qs in plan response + mid-review disciplined self-correction is operating at full effectiveness across Sprint 2.**

**Sprint 3 starts from genuinely stable foundations.** Sprint 1 + Sprint 2 deliver: identity + multi-tenancy + agency workspace + brands + invitations + settings + theme + 1097 tests. Sprint 3's creator surface builds on top, not against. Worth noting: this is 2 of 12 sprints; the workflow that got us here is the durable asset more than the code.

---

## (h) Status

**Sprint 2 is closed.** Both chunks are complete. All tests green. Ready for the closing commits.

| Artifact                | Location                                           | Status                                  |
| ----------------------- | -------------------------------------------------- | --------------------------------------- |
| Sprint 2 Chunk 1 review | `docs/reviews/sprint-2-chunk-1-review.md`          | Closed (commit `e51fc32` + Pint hotfix) |
| Sprint 2 Chunk 2 review | `docs/reviews/sprint-2-chunk-2-review.md`          | Closed (this file's sibling)            |
| Sprint 2 self-review    | `docs/reviews/sprint-2-self-review.md` (this file) | Closed                                  |

**Standing standards from § b (#1-7) plus § g additions to land in `PROJECT-WORKFLOW.md § 5` during the closing commit.**

Sprint 3 owns creator self-signup wizard + creator dashboard + bulk roster invitation per `20-PHASE-1-SPEC.md` § 5. New thread recommended for Sprint 3 per the long-thread context-degradation discussion in the Sprint 2 close-out conversation.

---

_Provenance: drafted by Cursor as the closing artifact for Sprint 2 (Chunk 2's compressed-pattern process — single chat completion summary + three structured drafts per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Claude-side observations added on independent review pass. **Status: Closed. Sprint 2 is done.**_
