# Project Workflow

This is the operating manual for how Catalyst Engine is built. It captures the patterns, conventions, and review workflow that have evolved through Sprints 0 and 1. Every Cursor session and every Claude review session should be aligned to this document.

If something here conflicts with another doc, this document wins for **process**; the architectural docs (`00-MASTER-ARCHITECTURE.md`, `02-CONVENTIONS.md`, etc.) win for **technical decisions**. If the two seem to conflict on a real point, raise it as a question rather than guessing.

---

## 1. Roles

- **Pedram (you)** — Solo developer, project owner, integration tester, the human-in-the-loop for all decisions.
- **Cursor** — Implementing AI agent. Reads specs and prior reviews, writes code, writes tests, runs tests, fixes bugs, drafts review summaries.
- **Claude** — Independent reviewer (in claude.ai chat). Reads completed work, runs spot-checks, produces merged final review files. Architectural and security counsel. Cannot read the local filesystem; reviews via uploaded files, repo links, or pasted snippets.

These three roles are durable across chat sessions on either side. A fresh Claude or fresh Cursor session inherits the role from this document, not from any specific conversation.

---

## 2. Sprint structure

Each sprint is broken into **chunks** of 1-2 days of work. Each chunk has:

- A defined scope (what's in / out)
- Acceptance criteria (concrete, testable)
- Review priorities Claude flags before code is written
- A completion summary from Cursor when done
- A merged final review file in `docs/reviews/`

Large chunks may be sub-divided further. For example, Sprint 1 Chunk 6 is split into 6.1 through 6.9 because it's the largest chunk in the sprint. Sub-chunks are reviewed in **groups** at natural boundaries (e.g., 6.1 alone for backend changes, then 6.2-6.4 as the data layer, then 6.5-6.7 as the UI, then 6.8-6.9 as E2E + docs). Group boundaries are decided when the parent chunk is planned.

---

## 3. The chunk lifecycle

Every chunk follows the same nine steps. Don't skip steps. Don't reorder them.

### Step 1 — Claude writes the chunk kickoff prompt

Includes: scope (in/out), acceptance criteria, review priorities, any sprint-spec or doc references Cursor needs to read. Pedram sends this verbatim to Cursor.

### Step 2 — Cursor produces a sub-chunk plan before any code

Cursor reads the kickoff, reads the referenced docs, then **pauses** and produces a multi-step plan with:

- 5-10 sub-steps, each independently green
- Any clarifying questions surfaced upfront ("Q1, Q2, Q3" pattern)
- "Open items I'm tracking but not asking about now" — items Cursor noticed and is handling silently
- Confirmation that prior chunks' team standards will be applied (`Event::fake` test split, source-inspection regression tests, single-error-code non-fingerprinting, etc.)

Cursor **does not** write code at this stage.

### Step 3 — Pedram brings Cursor's plan to Claude

Pedram pastes Cursor's plan into the Claude chat. Claude reviews it against the spec and architecture docs.

### Step 4 — Claude approves, adjusts, or pushes back on the plan

Common outcomes:

- Plan approved as-is → Cursor proceeds
- Plan approved with refinements → Claude writes specific changes; Pedram forwards to Cursor
- Questions answered (Q1/Q2/Q3 pattern) → Claude answers each with reasoning; Pedram forwards
- Plan rejected → Claude explains why; Cursor revises and resubmits

The plan is the contract. Once approved, Cursor builds against the contract; deviations require pausing and re-asking.

### Step 5 — Cursor builds the chunk

Cursor builds each sub-step to completion before moving to the next. Sub-steps end green: tests pass, lint clean, types clean. Cursor commits as it goes (using Conventional Commits).

If Cursor encounters an architectural ambiguity mid-build, Cursor **stops and asks** rather than guessing. Pedram forwards the question to Claude. Claude answers. Cursor continues.

### Step 6 — Cursor produces a chunk completion summary AND a draft review file

When the chunk is complete:

1. **Chat completion summary** — narrative summary of what was built, how each review priority was addressed, design choices made unprompted, any issues fixed mid-development, verification results (test counts, lint, typecheck, coverage).

2. **Draft review file** at `docs/reviews/sprint-{N}-chunk-{M}-review.md` (or `sprint-{N}-chunk-{M}-{S}-review.md` for sub-chunks) following the structure in section 5 below. **Status: "Ready for review."** Cursor does NOT commit this file; it goes only into the chat for Claude.

Cursor pauses at this point and waits.

### Step 7 — Pedram brings completion summary + draft review to Claude

Pedram pastes both the summary and the draft review file into the Claude chat.

### Step 8 — Claude does an independent review pass

Claude:

1. Reviews the work against architectural docs, conventions, security spec, testing spec, and the chunk's review priorities
2. Identifies any spot-checks needed — usually 2-5 grep commands or file inspections
3. Sends spot-checks back to Pedram (in one consolidated prompt for Cursor) if they're needed before merging
4. Produces the merged final review file combining Cursor's draft with Claude's independent assessment

The merged review file:

- Has Status flipped to "Closed" (or "Approved with X" if minor fixes are pending)
- Combines Cursor's implementation details with Claude's independent verdicts and follow-up items
- Has a provenance line at the bottom recording dual authorship
- Lists deferred items with explicit triggers (which sprint will pick them up)

### Step 9 — Pedram drops the merged review into the repo and commits

```bash
# Drop the file into docs/reviews/, then:
git add docs/reviews/sprint-{N}-chunk-{M}-review.md
git commit -m "docs(reviews): close sprint {N} chunk {M} review (final merged version)"
git push
```

Then move to the next chunk's kickoff.

---

## 4. Review file structure

Every chunk review file in `docs/reviews/` follows this structure. The structure is non-negotiable; the content fills in based on the chunk.

```markdown
# Sprint {N} — Chunk {M} Review

**Status:** Closed | Approved with X | Open
**Reviewer:** Claude (independent review) — incorporating implementation details from Cursor's self-review draft
**Reviewed against:** [list of relevant doc sections]

## Scope

[bulleted list of what was built — endpoints, modules, services, schema changes, test infrastructure]

## Acceptance criteria — all met (or list which weren't)

| #   | Priority | Status |
| --- | -------- | ------ |

[Table or list of every review priority from the kickoff with checkmark and brief evidence]

## Standout design choices (unprompted)

[Things Cursor did beyond the asks. Worth recording — these become future patterns and are worth recognizing.]

## Decisions documented for future chunks

[Patterns established in this chunk that future chunks must follow. Team standards.]

## Follow-up items

### For Sprint {X} ({trigger})

[What this chunk deferred to a specific later sprint, with explicit reasoning]

## What was deferred (with triggers)

[Bulleted list of every deferred item with the sprint that should pick it up. This is what makes the system self-activating.]

## Verification results

| Gate | Result |
[Test counts, lint status, typecheck, coverage]

## Spot-checks performed

[Numbered list of what Claude verified]

## Cross-chunk note

[Any latent bugs from earlier chunks discovered during this work; or "None this round."]

---

_Provenance: [drafted by Cursor / merged by Claude — describe the authorship trail]_
```

---

## 5. Team standards established through prior chunks

These patterns emerged organically and are now mandatory for the rest of the build. Each one was established in a specific chunk and is enforced going forward.

### 5.1 Source-inspection regression tests for structural invariants

**Established:** Sprint 1 Chunk 5 (`TwoFactorIsolationTest`)

When the type system can't enforce a structural rule, write a test that walks the source tree with regex and asserts the rule holds. Examples:

- "Library X is only imported in file Y" — `TwoFactorIsolationTest`
- "No `! $matched` short-circuit in recovery code lookup" — `TwoFactorEdgeCasesTest`
- "Recovery codes are never assigned to a Pinia state field" — chunk 6 plan

These tests fail loudly when a future engineer breaks the invariant, with a clear reason. They're cheap to write and the only protection against subtle architectural drift.

### 5.2 Event::fake test split

**Established:** Sprint 1 Chunk 4

When a test asserts both an event is dispatched AND its audit-log consequence happened, split into two tests:

- One with `Event::fake([SomeEvent::class])` asserting the event was dispatched
- One without `Event::fake` asserting the audit row was written

`Event::fake` swallows listeners, so a single test using both can silently pass through a broken listener.

### 5.3 Real-rendering mailable test pattern

**Established:** Sprint 1 Chunk 4

Tests that rely solely on `Mail::fake()` skip actual template rendering, so broken Blade templates pass tests. Every new mailable must have at least one real-rendering test (e.g., `MailLocalizationTest` switches `App::setLocale()` and renders `envelope()` directly).

### 5.4 Single error code for non-fingerprinting

**Established:** Sprint 1 Chunk 4 (verification token failures), refined Chunk 5 (2FA disable)

When multiple distinct internal failure modes must not be distinguishable to the caller (security boundary), they all collapse to a single error code. Examples:

- All four email verification failure modes → `auth.email.verification_invalid`
- 2FA disable: wrong password vs wrong code → `auth.mfa.invalid_code`

Differential error codes leak information; collapsing prevents fingerprinting.

### 5.5 Transactional audit on state-flipping actions

**Established:** Sprint 1 Chunk 3 (`AccountLockoutService::escalate`), Chunk 5 (`TwoFactorVerificationThrottle::suspendEnrollment`)

When an action flips a security-relevant state (account suspension, MFA enrollment freeze), the state change AND the audit log row must be written in the same DB transaction. Mismatch between system state and audit log is forensically catastrophic.

The corresponding event listener is intentionally NOT registered (would double-audit); the event remains a fan-out signal only. Both decisions documented inline at the call site.

### 5.6 Idempotency on state-flipping actions

**Established:** Sprint 1 Chunk 5 (`AccountLockoutService::escalate`, `TwoFactorVerificationThrottle::suspendEnrollment`)

Re-running a state-flip action on an already-flipped resource is a no-op. No duplicate audit rows, no overwritten timestamps. Test asserts this explicitly.

### 5.7 Constant-verification-count for credential lookups

**Established:** Sprint 1 Chunk 5 (recovery code lookup)

When verifying a credential against multiple stored hashes, run the verification function on every slot regardless of whether a match has already been found. Otherwise response time leaks the matching slot's position.

Pinned by source-inspection regression test (see 5.1).

### 5.8 Reasoned removal of dead code

**Established:** Sprint 1 Chunk 5

If a code branch is unreachable under current configuration, remove it AND replace with an explanatory comment so a future config change is an intentional decision. Don't leave dead code "just in case" — that creates ambiguity about whether it's dead or merely unused.

### 5.9 User-enumeration defense across the auth surface

**Established:** Sprint 1 Chunks 3, 4

Every auth endpoint that takes an email must respond identically for unknown / unverified / verified / suspended emails (where the response shape would otherwise leak existence). Examples:

- `forgot-password` returns 204 silently for unknown emails
- `resend-verification` returns 204 silently for unknown / already-verified emails

### 5.10 The review-files workflow itself

**Established:** Sprint 1 Chunks 1-5 (refined Chunk 4)

`docs/reviews/` is the durable record of decisions. Files are produced by Cursor (draft) + Claude (merge). Single file per chunk. Provenance line records dual authorship. Future Cursor sessions read this directory at sprint kickoff to absorb prior decisions.

When Sprint N starts, Cursor's kickoff prompt includes: _"Read `docs/reviews/` for any review file that mentions this sprint as a trigger or deferral target."_

### 5.11 Cross-chunk handoff contract verification

**Established:** Sprint 2 Chunk 2 (caught during pre-planning read pass)

When Chunk N provides an endpoint, URL, or token that Chunk N+1 consumes, the consuming chunk's read pass must explicitly verify:

- The full URL shape (path params, query params)
- The authentication requirement (unauthenticated? auth:web? auth:sanctum?)
- Every parameter the consumer will embed (e.g. `&agency=<ulid>` in a magic link)

This is not the providing chunk's responsibility alone — the consuming chunk's plan must confirm the contract before building against it. Sprint 2 caught that the magic link URL lacked the agency ULID required by the accept endpoint. Without the read-pass catch, the bug would have reached E2E testing.

**Process note for Cursor kickoffs:** include an explicit "cross-chunk handoff contracts verified" section in the plan response when the chunk consumes backend endpoints from a prior chunk.

### 5.12 Test-helper one-shot provisioning

**Established:** Sprint 2 Chunk 2 (`CreateAgencyWithAdminController`)

E2E test provisioning helpers must create a complete test subject + all dependencies in a single API call and return all identifiers needed by the spec. No multi-step provisioning chains in specs. Pattern: `POST /api/v1/_test/{subject}/setup` → `{ email, password, {subject}_ulid, ... }`.

Mirror: `CreateAdminUserController` (chunk 7.6), `CreateAgencyWithAdminController` (Sprint 2 Chunk 2).

### 5.13 Module-scoped API files

**Established:** Sprint 2 Chunk 2 (codified from chunk 6.4 origin)

Every frontend module that communicates with the API has its own `<module>.api.ts` file. No cross-module API calls. The file exports a single named object (e.g. `brandsApi`, `invitationsApi`). All HTTP interaction for that module is centralized there.

### 5.14 AgencyLayout is the authenticated agency shell

**Established:** Sprint 2 Chunk 2

All post-auth agency-scoped routes use `meta.layout: 'agency'`. No new routes may use `meta.layout: 'app'` for authenticated agency surfaces. The layout switcher in `App.vue` dispatches via `route.meta.layout`; the three-way dispatch (`auth` / `agency` / bare catch-all) is the established pattern.

### 5.15 Architecture test allowlist discipline

**Established:** Sprint 2 Chunk 2 (`use-theme-is-sot.spec.ts`)

Any non-theme `localStorage` usage in the main SPA requires:

1. An allowlist entry in `use-theme-is-sot.spec.ts`
2. A corresponding entry in `docs/tech-debt.md` with risk, mitigation, and resolution trigger

The architecture test is doing its job when it catches the new file — the allowlist + tech-debt record is the documented resolution, not a bypass.

### 5.16 Vuetify v-data-table slot modifier allowance

**Established:** Sprint 2 Chunk 2

ESLint must be configured with `vue/valid-v-slot: ['error', { allowModifiers: true }]` in any project using Vuetify `v-data-table` or `v-data-table-server` with dot-notation slot names (e.g. `#item.attributes.status`). Without this, ESLint incorrectly flags valid Vuetify slot syntax. Applies to both `apps/main` and `apps/admin`.

### 5.17 Defense-in-depth coverage for permission guards

**Established:** Sprint 2 Chunk 2 (review pass — `requireAgencyAdmin` unit test gap)

Every route guard in `apps/main/src/core/router/guards.ts` must have Vitest unit test coverage covering:

1. The allow path (authorized role/state → returns `null`)
2. The deny path (unauthorized → returns the correct redirect)
3. Registration in the `guards` registry

The guards file is imported by every protected route; a broken guard is a security regression. Unit coverage at this layer allows empirical break-revert verification without the full E2E stack. The `vi.mock()` + `vi.mocked().mockReturnValue()` pattern (module-level mock, per-test override) is the established pattern for mocking Pinia stores in guard unit tests.

### 5.18 CI-authoritative Pint verification

**Established:** Sprint 2 Chunk 1 (hotfix); codified Sprint 2 Chunk 2

Cursor sandbox Pint runs are not authoritative. The sandbox's PHP binary may differ from the project's pinned Pint version, producing false passes or false failures. Authoritative Pint checks come from:

- CI (GitHub Actions)
- `./vendor/bin/pint --test` run with `required_permissions: ["all"]` to bypass the sandbox

If a Pint check is run in the sandbox and produces an unexpected result, trust CI over the sandbox result.

### 5.19 User-enumeration defense for unauthenticated preview/status endpoints

**Established:** Sprint 2 Chunk 2 (`InvitationPreviewController`) — extends 5.9

Standard 5.9 covers auth endpoints that take an email. This extends the principle to any unauthenticated endpoint that returns data about an authenticated subject:

- An unknown token → 404 with a generic message (not "token not found")
- A valid token belonging to a different agency → 404, not "wrong agency" (avoids cross-tenant token enumeration)
- Only after token + agency both match does the endpoint return subject data (`is_expired`, `is_accepted`, `agency_name`, `role`)

The invariant: the response body must not reveal whether the token exists independently of the agency check.

### 5.20 Read the prior review file before producing the merged review (Claude-side discipline)

**Established:** Sprint 2 Chunk 2 (Claude-side; recorded here for Cursor context)

Before Claude produces a merged review file for Chunk N, Claude must read the prior chunk's merged review (Chunk N-1) to:

1. Verify that deferred items from Chunk N-1 are addressed or remain correctly deferred
2. Confirm that standards established in Chunk N-1 are applied in Chunk N
3. Anchor the cross-chunk arc accurately in the new review's prose

This is Claude's counterpart to Cursor's mandatory read-list at session start.

### 5.21 Cross-tenant allowlist categorisation

**Established:** Sprint 3 Chunk 1 (F1 audit)

The tenancy allowlist conflates three semantically distinct categories (cross-tenant admin tooling / tenant-less / path-scoped tenant). When adding routes that bypass the standard tenancy stack, name the category explicitly in the row justification. The categorisation note lives in `security/tenancy.md` § 4; the structural `Category` column is open tech-debt.

### 5.22 `withAdmin()` factory for symmetric resources

**Established:** Sprint 3 Chunk 3

When a resource serves both creator-self + admin audiences, keep ONE `toArray()` shape with an `admin_attributes` block conditionally appended via a factory toggle (`->withAdmin(true)`). No parallel `AdminXResource` subclass. Applies to all admin-bearing resources.

### 5.23 Module-scoped boolean for "did this surface render once this tab?"

**Established:** Sprint 3 Chunk 3 (`internal/welcomeBackFlag.ts`)

The three-signals-three-timing-windows analysis (auth-store flag vs onboarding-store flag vs module-scoped flag) is the reusable framing for any future "first-mount-in-tab" detection. The module-scoped in-memory boolean is reset by the relevant store action on logout and has zero coupling to persisted state.

### 5.24 Per-route MFA-enrolment gating, not blanket gating

**Established:** Sprint 3 Chunk 4

Admin-sensitive surfaces (`/agency-users`, `/creator-invitations/bulk`) carry `requireMfaEnrolled` in their guard chain; non-sensitive surfaces (dashboard, brands, settings) do not. Selective gating must be pinned with a negative-case assertion (see 5.34) verified via break-revert (see 5.35).

### 5.25 Backend / frontend constant parity via architecture tests

**Established:** Sprint 3 Chunk 4 (`field-edit-config-parity.spec.ts`)

When a backend Laravel `Request` class pins enums / field lists as a SOT, an architecture test source-inspects both layers (extends 5.1). Where backend validation is permissive (e.g. `size:2` strings), parity is docstring-only with a tech-debt entry — and the review prose must NOT claim the test enforces what it cannot (see 5.35).

### 5.26 Test-helper seam for "skip multi-step setup"

**Established:** Sprint 3 Chunk 4 (`enroll_2fa` flag on `agencies/setup`); precursor Chunk 3 `setQueueMode`

When an E2E spec needs a subject in a state that would require 10+ SPA navigations to reach via production paths, extend the existing test-helper with an optional flag for the target state, gated by the chunk-6.1 helper-token middleware. The double-gating discipline (provider gate at boot + token middleware per-request) keeps production traffic out.

### 5.27 Cross-layer contract-gap diagnostic pattern

**Established:** Sprint 3 Chunk 3 (avatar-completeness gap); reinforced Chunk 4 (B1 multipart Content-Type gap)

CI failures on cross-layer specs are first-class diagnostic surfaces. Trace the disabled-state condition (which field is missing? which calculator returns false?) rather than retrying. Cross-layer contract gaps surface as CI timeouts ("Submit never enables"), not explicit assertion failures — the seam between two structurally-correct layers is the highest-leverage bug class.

### 5.28 `Promise.all([page.waitForURL, click])` for cross-step navigation

**Established:** Sprint 3 Chunk 3 (CI race)

Pin the navigation expectation BEFORE the click dispatches. Applies to all future Playwright specs with cross-step navigation. **Companion pattern (Chunk 4):** prefer `page.goto()` for Vuetify `:to`-bound widget navigation in Playwright specs (verify-visible-then-goto).

### 5.29 Single async path for long-running operations

**Established:** Sprint 3 Chunk 4 (bulk-invite)

Submit + 202 + poll → terminal status. No "inline preview + edit + submit" hybrid UX. Applies to all future long-running operations (campaign launch, payout disbursement, etc.).

### 5.30 One row per field for admin edit, not multi-field forms

**Established:** Sprint 3 Chunk 4

Each editable field is its own transaction with its own audit row. Avoids partial-state ambiguity. Applies to all future admin edit surfaces.

### 5.31 Server-side markdown rendering with strict CommonMark config

**Established:** Sprint 3 Chunk 3 (`ContractTermsRenderer`)

`league/commonmark` with `allow_unsafe_links: false` + `html_input: 'escape'` for any platform-controlled markdown source rendered via `v-html` in the SPA. Applies to any future contract/terms/markdown-source rendering.

### 5.32 Decision reinterpretation at plan-pause-time

**Established:** Sprint 3 Chunk 4 (B=c, C2=a); load-tested across all of Sprint 3.5

Locked decisions can survive read-pass divergences via reinterpretation provided the **structural intent** is preserved while the **mechanism** adapts to verified reality. The cost saving is meaningful: a re-decision round-trip costs at minimum one review pass; reinterpretation costs zero extra round-trips when the intent is preserved. The trick is knowing which intent the decision was actually serving — so every locked decision should have its structural intent explicitly named in the kickoff, so future read passes know what is reinterpretable vs load-bearing. (Sprint 3.5 exercised this every chunk; Chunk 4's "auth hero / welcome bar" → "thin accents on persistent surfaces that exist today" is the clearest case of the pattern changing _what_ gets built.)

### 5.33 Setter-injection breaks Pinia circular dependencies

**Established:** Sprint 3 Chunk 4 (`useAgencyStore.setAuthRebootstrap(fn)`)

When store A needs to invoke store B's actions but B already imports A, the dependency-aware store imports a `setHook(fn)` setter from the dependency-free store and calls it from inside the factory function body. Reusable for any future cross-store action coupling.

### 5.34 Negative-case assertions in architecture tests

**Established:** Sprint 3 Chunk 4 (PMC-1)

Tests that pin a positive case (X has property P) often miss the negative case (only X has property P). Pinning both is what defends a decision against silent broadening. Pairs with 5.17 (selective gating) and is verified via break-revert (5.35).

### 5.35 Architecture-test claim verification via break-revert

**Established:** Sprint 3 Chunk 4 (spot-check pass surfacing two overclaims); reaffirmed throughout Sprint 3.5

Every "the architecture test enforces X" claim in a chunk review must pair with a break-revert verification — temporarily mutate the source to violate the invariant, confirm the test fails, revert. The only mechanism that surfaces the "claim more rigor than the test enforces" failure mode. Sprint 3.5 added the corollary (Chunk 4): **after any break-revert, verify the restore via `git status` / `git diff`** — a `git checkout` restore can silently fail to take, leaving an uncommitted mutation.

### 5.36 Asymmetric test coverage acknowledgement

**Established:** Sprint 3 Chunk 4 (B1 multipart fix)

Multi-part fixes may have one leg pinned in unit tests and another leg covered only by E2E. Document the asymmetry explicitly in the review prose rather than implying uniform coverage. A generalisation of 5.35: some defense-in-depth coverage is structurally untestable at the unit level and relies on integration paths.

### 5.37 Dual-recipient notifications use TWO types, not one (per-direction)

**Established:** Sprint 11 — Messaging (D-7)

When a single event can notify EITHER party depending on who triggered it (e.g. a new chat message: the creator's send notifies the agency, the agency's send notifies the creator), model it as **two notification types**, one per recipient direction — `<event>_by_creator` (recipient = creator) + `<event>_by_agency` (recipient = agency) — NOT a single `<event>` type. A single type forces one static `recipient` in the `LIVE_TYPES` registry, so the OTHER party would receive the row but get no preference toggle — the exact "receive-but-can't-toggle" dead control the Ch3b role-filter exists to prevent. The full ripple per type: one `AuditAction` verb (the `NotificationType` one-vocabulary tie, even when no audit row is written on the event), one `NotificationType` case, one `LIVE_TYPES` entry (templateKey + recipient + group). The two types keep the prefs role-partition disjoint-and-complete (the parity spec covers both, verified via 5.35 break-revert). Reusable for any future either-party notification.

### 5.38 New event consumers listen against a shared contract keyed by `eventKey()`, not dedicated per-event classes

**Established:** Sprint 12 Chunk 1 — Boards & Automation (D-6)

> **⚠ Flagged to the reviewer for ratification — this generalises beyond boards.** When a domain already emits ONE rich event implementing a small interface (here `AssignmentTransitioned` implements `AssignmentEventContract` — `assignment()` / `eventKey()` / `metadata()` / `triggeredByUserId()`), a NEW reactive feature should add a listener that **binds to the contract and switches on `eventKey()`**, NOT a fan of dedicated per-event classes. Sprint 8 deliberately built the single `AssignmentTransitioned` keyed by the `AuditAction` value; the board-automation spec sketched dedicated `AssignmentDraftApproved`-style classes, and that sketch was **superseded** — the listener (`BoardAutomationListener`) reads the contract and the service maps `processEvent(assignmentId, eventKey, metadata, triggeredByUserId)` 1:1 onto it. Benefits: the new consumer shares the existing single `Event::listen` subscription (no event-class proliferation, no re-dispatch plumbing), and config-driven reactions (the `board_automations.event_key` rows) bind directly to the same `eventKey` vocabulary the audit catalogue already owns — one source of truth for "what happened." When ordering matters between consumers of the same event, register them explicitly in order AND make the later consumer a no-op on the precondition the earlier one establishes (belt + suspenders — here `CreateBoardCard` before `BoardAutomationListener`, and the automation no-ops on a missing card). Reusable for any future event-reactive feature on an already-contract'd event spine.

### 5.39 Session close: refresh the resumption template

**Established:** AH-018–025 close-out, 2026-07-08

At session close, before switching threads, Cursor refreshes Part 2 of `docs/reviews/RESUMPTION-TEMPLATE.md` (new AH entries, HEAD SHA, open threads) in the closing docs commit, so the next resumption is copy-paste from the repo, not reconstruction from chat.

### 5.40 Production-data safety (we are live)

**Established:** production-data safety standing standard, 2026-07-17

We are live in production with real users, and live data is the platform's most important asset. Every chunk is written under the assumption that a single mistake can destroy irreplaceable production data. This standard is binding on both agents.

- **Migrations are additive-first.** New columns are nullable or defaulted. **No** `DROP COLUMN`, `DROP TABLE`, destructive `ALTER`, or type-narrowing on a populated table without an explicit, separately-reviewed migration plan. Renames happen **expand → migrate → contract** (add the new column, dual-write/backfill, retire the old one later) — never in-place on live data.
- **Data mutations ship as guarded, idempotent, dry-runnable commands** — never as migration side effects. The only exception is a narrowly-scoped backfill that is (a) idempotent, (b) predicate-guarded to touch **only** the rows belonging to the concept it serves, and (c) test-pinned including the **leaves-everything-else-alone** case. Reference examples: the **AH-041** board backfill (default-name-only rename predicate + an agency-rename-survives test) and the **AH-048** posture (additive-nullable column, single-timestamp write, `--dry-run` mutates nothing).
- **`down()` must be honest** — a true inverse, or an explicit comment stating exactly what it cannot restore. A `down()` that silently loses data is worse than one that aborts.
- **Deletion is never casual.** No hard deletes of user-generated data in application code — soft-delete or archive instead. Any command or endpoint that can remove rows requires a §5.34 negative case proving it cannot over-reach its predicate.
- **Every chunk's review file gains a "Production posture" section** (the AH-048 shape): what the migration does to existing rows, what the feature writes, and the blast radius of a bug. The sentence we aim to be able to write every time: _"additive-nullable only, flag OFF, single-column write."_
- **Pre-deploy snapshot is mandatory** before any deploy carrying migrations, backfills, or one-shot commands. The deploy order is the checklist in `docs/runbooks/production-queue-worker.md` §8.
- **The alarm rule (mandatory, both agents).** Before **any** code is written — at plan-pause for full-loop chunks, or before building each item in a direct-iteration batch — the implementing agent (Cursor) MUST state a production-data risk line: **`PROD-DATA RISK: NONE`** (pure read/UI/additive work) **or** an explicit **`⚠️ PROD-DATA RISK:`** naming every operation that modifies, deletes, migrates, or backfills existing production rows, in plain language Pedram can act on (e.g. _"this deletes X where Y"_, _"this rewrites column Z on all rows"_). A risky operation discovered mid-build that was not declared up front is a **stop-the-build event**: pause, declare it, wait for Pedram's explicit go. Silence is never acceptable — `NONE` must be stated **affirmatively**, not implied by omission. The independent reviewer (Claude) states the same line at kickoff/scoping; either agent staying silent is itself a process violation.

> **Standing open item (owned by Pedram) — the standard is incomplete until this is done.** The backup/restore posture is currently **unverified**: RDS automated snapshots enabled, PITR retention window, and — critically — a **tested restore** have not been confirmed on this deployment. A snapshot you have never restored from is a hope, not a backup. Until a restore has been rehearsed once end-to-end, treat this standard as provisional and lean even more conservatively. Tracked in `docs/runbooks/production-queue-worker.md` §8 and the resumption template's open threads.

---

## 6. The "Q-and-A before code" pattern

When Cursor encounters architectural ambiguity, it asks structured questions instead of guessing. The format:

```
Q1. [question describing the ambiguity, with context about why it matters]
    Option A — [option with reasoning]
    Option B — [option with reasoning]
    Option C — [option with reasoning]
    Use your judgment

Q2. [next question]
...
```

Claude answers each question with reasoning, not just a letter. Pedram forwards the answers verbatim. Cursor proceeds.

This pattern saved real time in Sprints 0 and 1 (Sprint 0 manual-steps batching, the test-helpers Playwright pattern, AuthLayout placement). When in doubt, Cursor asks.

---

## 7. Spot-checks before greenlighting

Claude does not approve a chunk based solely on Cursor's self-review. After reading the draft, Claude usually identifies 2-5 spot-checks — small grep commands or file inspections — that verify specific claims in Cursor's draft.

Examples of effective spot-checks:

- "grep for X in file Y to verify the claim about Z"
- "Show me the relevant lines of file W"
- "Confirm pattern P appears in test T"

Spot-checks are sent to Cursor as a consolidated prompt. Cursor runs them and pastes outputs. Claude verifies. Then the merged review is produced.

This is _non-negotiable_ for security-critical chunks. It's how we caught the constant-time recovery-code claim being inaccurate (Sprint 1 Chunk 5 spot-check 3, which Cursor honestly corrected, switching from Option A defer to Option B fix-now).

---

## 8. Tech debt management

`docs/tech-debt.md` is the durable list of known deferrals. Each entry includes:

- What was deferred
- Why
- Trigger condition (which sprint or condition will pick it up)

When Sprint N starts, Cursor's kickoff prompt includes: _"Read `docs/tech-debt.md` and surface any items triggered by this sprint at the top of your plan."_

Entries are appended, not edited. When an item is resolved, the entry is marked **Resolved in Sprint X, commit Y** with a strikethrough — the historical record is preserved.

---

## 9. The two-way ratchet on team standards

Standards established in earlier chunks become mandatory for later chunks. This is a one-way ratchet: easier to add a standard, harder to remove one.

If a chunk wants to NOT follow an established standard, the kickoff or the sub-chunk plan must explicitly say so with reasoning. Default is "follow all prior standards."

A standard is "established" when:

- It's documented in this file (Section 5)
- It's referenced in at least one prior chunk's merged review
- It has a test or pattern that enforces it

If only two of three are true, it's a candidate. Three of three makes it a standard.

---

## 10. Switching chat sessions

Both Claude and Cursor operate inside chat sessions with finite working memory. Long sessions slow down and lose fidelity.

**Switch Cursor sessions** at:

- End of a sprint (always)
- End of a chunk that involved heavy review back-and-forth
- When responses noticeably slow or quality degrades
- When switching between substantially different work (e.g., backend → frontend)

**Switch Claude sessions** at the same triggers. Claude's sessions are typically more durable than Cursor's because Claude reviews don't accumulate as much state, but the same triggers apply.

Resumption is cheap because of `docs/reviews/`, `docs/tech-debt.md`, and the architecture docs. A new session reads these files and is at full operating context in 30-60 seconds of file reads.

The resumption prompt template lives in `docs/reviews/RESUMPTION-TEMPLATE.md`.

---

## 11. What this document is NOT

This document is the **process** record. It's not the technical record. For technical questions:

- Architecture decisions → `00-MASTER-ARCHITECTURE.md`
- Coding conventions → `02-CONVENTIONS.md`
- Data model → `03-DATA-MODEL.md`
- API contracts → `04-API-DESIGN.md`
- Security and compliance → `05-SECURITY-COMPLIANCE.md`
- Testing strategy → `07-TESTING.md`
- Phase 1 scope → `20-PHASE-1-SPEC.md`
- Active phase pointer → `ACTIVE-PHASE.md`
- Tenancy contract → `security/tenancy.md`
- Feature flags → `feature-flags.md`
- Per-chunk decisions → `reviews/sprint-{N}-chunk-{M}-review.md`

This document is the glue between all of those.
