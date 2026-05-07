# Reviews

This directory contains durable records of code reviews performed on each sprint and chunk during the Catalyst Engine build.

## Why this directory exists

Cursor sessions don't share memory across chats. Each Cursor session starts fresh and only knows what's in the repo plus what's said in the current chat.

When a code review surfaces decisions ("we deferred X to Sprint 8"), tradeoffs ("Argon2id parameters tuned to memory:65536, time:4"), or rationale ("AgencyMembership model name vs agency_users table because…"), those decisions live here so:

1. **Future Cursor sessions can read them** when working on related sprints or chunks.
2. **Future humans (you on a new laptop, future hires, audit reviewers)** can understand why the codebase looks the way it does.
3. **Mid-sprint context is preserved** for end-of-sprint reviews, since reviewers can read prior chunk reviews to see what was already discussed.

## File naming

`sprint-{N}-chunk-{M}-review.md` for chunk-level reviews.
`sprint-{N}-review.md` for end-of-sprint reviews.

## What's in each review

- **Status** — open / closed / addressed
- **Scope** — what the chunk built
- **Acceptance criteria status** — what was verified
- **Issues raised and resolutions** — concrete decisions
- **Standout design choices** — unprompted improvements worth recording
- **Clarifications requested** — items addressed before the next chunk
- **Decisions documented for future chunks** — the rules-of-the-road this chunk established
- **What was deferred** — with explicit triggers (which sprint will pick this up)

## How Cursor should use these files

When Cursor starts a new sprint or chunk, the kickoff prompt will instruct it to read:

1. `CURSOR-INSTRUCTIONS.md`
2. `ACTIVE-PHASE.md`
3. The phase spec section for the active sprint
4. Relevant architecture/conventions/spec docs
5. **Recent reviews in this directory** that contain triggers for the active sprint or document patterns the active sprint must follow

If a review's "Decisions documented" section establishes a pattern (e.g., "all destructive routes use `RequireActionReason` middleware"), Cursor must follow that pattern in subsequent work without needing a fresh prompt to remind it.

## How to find triggers for the current sprint

Search this directory for "Sprint N" where N is the current sprint number. Anything mentioned as deferred to Sprint N must be addressed during Sprint N's work, ideally as part of the relevant sprint's planning or kickoff.

This pattern is part of the workflow documented in `docs/CURSOR-INSTRUCTIONS.md`.
