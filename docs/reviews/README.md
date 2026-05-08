# Reviews

Durable records of code reviews performed on each chunk and sprint. These files are the project's institutional memory.

## Why this directory exists

Cursor sessions don't share memory across chats. Each Cursor session starts fresh and only knows what's in the repo plus what's said in the current chat. Same for Claude review sessions — long chats slow down and lose fidelity, so we switch sessions at sprint or chunk boundaries.

When a code review surfaces decisions ("we deferred X to Sprint 8"), tradeoffs ("Argon2id parameters tuned to memory:65536, time:4"), patterns established ("source-inspection regression tests for structural invariants"), or rationale, those decisions live here so:

1. **Future Cursor sessions read them at sprint kickoff** — patterns and standards are inherited, not relearned.
2. **Future humans (you on a new laptop, future hires, audit reviewers)** can understand why the codebase looks the way it does.
3. **Mid-sprint context is preserved** for end-of-sprint reviews, since reviewers can read prior chunk reviews to see what was already discussed.

## File naming

- `sprint-{N}-chunk-{M}-review.md` — chunk-level reviews
- `sprint-{N}-chunk-{M}-{S}-review.md` — sub-chunk reviews when a chunk is sub-divided
- `sprint-{N}-review.md` — end-of-sprint reviews
- `sprint-{N}-chunk-{M}-plan-approved.md` — optional checkpoint files for approved plans (used for resumption)
- `RESUMPTION-TEMPLATE.md` — template for spinning up a fresh chat session

## Authorship workflow (the merge pattern)

Each review file has dual authorship:

1. **Cursor produces a draft** at chunk completion. Status: "Ready for review." Contents: scope, acceptance criteria status, unprompted design choices, decisions for future chunks, follow-up items, deferred items with triggers, verification results, spot-checks performed.

2. **Claude produces the merged final** version. Status: "Closed." Contents: combines Cursor's draft with Claude's independent review pass — spot-check verdicts, follow-up items, observability triggers, team-standard nominations, security-boundary verifications.

3. **Pedram drops the merged file in the repo and commits.** Single source of truth per chunk, dual authorship recorded in the provenance line at the bottom.

The merge pattern is the result of an evolution: early chunks had Claude-only reviews that missed implementation details; switching to merged reviews preserves both perspectives. See `docs/PROJECT-WORKFLOW.md` § 3 for the full chunk lifecycle.

## What's in each review

The structure is non-negotiable; the content fills in based on the chunk:

- **Status** — Closed | Approved with X | Open
- **Reviewer** — Claude (independent review) — incorporating implementation details from Cursor's self-review draft
- **Reviewed against** — list of relevant doc sections
- **Scope** — what the chunk built
- **Acceptance criteria status** — table or list of every review priority with checkmark and brief evidence
- **Standout design choices** — things Cursor did beyond the asks (worth recording — these become future patterns)
- **Decisions documented for future chunks** — patterns established this chunk that future chunks must follow (team standards)
- **Follow-up items** — what this chunk deferred to a specific later sprint, with explicit reasoning and trigger
- **What was deferred (with triggers)** — bulleted list of every deferred item with the sprint that should pick it up; this is what makes the system self-activating
- **Verification results** — test counts, lint status, typecheck, coverage
- **Spot-checks performed** — numbered list of what Claude verified
- **Cross-chunk note** — any latent bugs from earlier chunks discovered during this work; or "None this round"
- **Provenance** — authorship trail at the bottom

## How Cursor uses these files

When Cursor starts a new sprint or chunk, the kickoff prompt instructs it to read:

1. `CURSOR-INSTRUCTIONS.md`
2. `PROJECT-WORKFLOW.md` (this is the master process doc — read first)
3. `ACTIVE-PHASE.md`
4. The phase spec section for the active sprint
5. **Recent reviews in this directory** that contain triggers for the active sprint or document patterns the active sprint must follow
6. Relevant architecture / conventions / spec docs
7. `tech-debt.md`

If a review's "Decisions documented" section establishes a pattern (e.g., "all destructive routes use `RequireActionReason` middleware", "source-inspection regression tests for structural invariants"), Cursor follows that pattern in subsequent work without needing a fresh prompt to remind it.

## How to find triggers for the current sprint

Search this directory for "Sprint N" where N is the current sprint number. Anything mentioned as deferred to Sprint N must be addressed during Sprint N's work, ideally as part of the sprint's planning or kickoff.

This pattern is documented in `docs/PROJECT-WORKFLOW.md` § 8 (Tech debt management).

## Review files vs Cursor's chat completion summaries

Cursor produces TWO things at chunk completion:

1. A **chat completion summary** — narrative, sent in chat to Pedram. Includes implementation highlights, in-development bug fixes, etc. Stays in chat (or commit message). NOT committed as a file.

2. A **draft review file** — structured, in the format above. Goes through Claude's merge process, then commits to `docs/reviews/`.

Don't confuse the two. A new Cursor session reading this directory should see Claude-merged final reviews, not Cursor's raw narrative summaries.
