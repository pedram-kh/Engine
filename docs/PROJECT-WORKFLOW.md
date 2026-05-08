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
