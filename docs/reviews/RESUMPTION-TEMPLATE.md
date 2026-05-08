# Chat Session Resumption Template

When starting a new Claude or Cursor chat session for the Catalyst Engine project, use one of the templates below.

---

## For a new Claude review chat session

Copy this entire block into the new Claude.ai chat. Fill in the `[CONTEXT]` and `[REQUEST]` sections with what's specific to this moment.

```
I'm continuing the Catalyst Engine project — solo dev (Pedram) building a
two-sided influencer marketing platform with Cursor as the implementing AI
agent and Claude as the independent reviewer. The repo is at:

https://github.com/pedram-kh/Engine

Read these files before responding (in this order — the first is the master
process doc and contains everything you need to know about how we work):

1. docs/PROJECT-WORKFLOW.md — master process doc, READ THIS FIRST
2. docs/CURSOR-INSTRUCTIONS.md — Cursor's standing instructions
3. docs/ACTIVE-PHASE.md — confirms which phase is active
4. docs/20-PHASE-1-SPEC.md (or whichever phase is active) — full phase scope
5. docs/reviews/README.md — review file workflow
6. docs/reviews/sprint-{N}-chunk-{M}-review.md for all closed chunks — what's
   been built and decided
7. docs/tech-debt.md — deferred items, triggers
8. docs/security/tenancy.md — tenancy contract
9. docs/feature-flags.md — feature flag registry
10. docs/02-CONVENTIONS.md — coding standards
11. docs/01-UI-UX.md — design system
12. docs/04-API-DESIGN.md — API contracts
13. docs/05-SECURITY-COMPLIANCE.md — security spec
14. docs/07-TESTING.md — testing strategy
15. docs/00-MASTER-ARCHITECTURE.md — system architecture

These files contain everything needed to operate as the reviewer for this
project. The PROJECT-WORKFLOW.md doc explicitly captures the patterns we use
(merge review workflow, Q-and-A before code, spot-checks before approval,
team standards established through prior chunks, the dual-authorship review
files). Confirm you've read everything and understand the workflow before
the next message.

[CONTEXT — what just happened]

[Example: "Cursor just completed Sprint 1 chunk 6.1 (backend additions: GET
/api/v1/me + test-helpers module). Sub-chunks 6.1-6.9 were grouped for
review as: 6.1 alone, 6.2-6.4, 6.5-6.7, 6.8-6.9. Cursor's chunk 6 plan was
approved with three clarifications recorded in
docs/reviews/sprint-1-chunk-6-plan-approved.md."]

[REQUEST — what you need from Claude]

[Example: "Please review Cursor's 6.1 completion summary and draft review
file (pasted below) and produce the merged final
docs/reviews/sprint-1-chunk-6-1-review.md."]

[paste Cursor's completion summary and draft review file]
```

---

## For a new Cursor implementing chat session

Copy this entire block into the new Cursor chat at the start of a sprint or major chunk transition.

```
I'm continuing the Catalyst Engine project. Previous Cursor sessions have
completed work through [LATEST CLOSED CHUNK]. The repo is at the current
local working tree.

Read these files in order before doing anything else:

1. docs/PROJECT-WORKFLOW.md — master process doc with team standards
2. docs/CURSOR-INSTRUCTIONS.md — your standing instructions
3. docs/ACTIVE-PHASE.md — which phase is active
4. docs/20-PHASE-1-SPEC.md (or active phase) — sprint scope
5. docs/reviews/README.md — review file workflow
6. docs/reviews/sprint-{N}-chunk-{M}-review.md for all closed chunks
7. docs/tech-debt.md — deferred items
8. docs/security/tenancy.md
9. docs/feature-flags.md
10. docs/02-CONVENTIONS.md
11. docs/01-UI-UX.md
12. docs/04-API-DESIGN.md
13. docs/05-SECURITY-COMPLIANCE.md
14. docs/07-TESTING.md
15. docs/00-MASTER-ARCHITECTURE.md

We are about to start [SPRINT N CHUNK M] — [BRIEF DESCRIPTION].

Confirm you've read everything, then wait for the chunk kickoff prompt
before producing any plan or code.

When you produce the plan, follow the patterns in PROJECT-WORKFLOW.md § 6
(Q-and-A before code) — surface any clarifying questions upfront in
Q1/Q2/Q3 format with options. Confirm prior team standards (§ 5) will be
applied. List "open items I'm tracking but not asking about now" so I know
what you've noticed and are handling silently.
```

---

## What makes resumption work

Three things make this work:

1. **The docs are the source of truth.** No conversation history is required to operate. The new session reads the docs and is at full context.

2. **The merged review files preserve decisions.** Every closed chunk's decisions are durably written. New sessions inherit the cumulative knowledge.

3. **The PROJECT-WORKFLOW.md captures the meta-process.** Without that doc, a fresh Claude or Cursor session might invent its own workflow. With it, the workflow is durable.

If a fresh session ever asks "how do we work?", point at `docs/PROJECT-WORKFLOW.md` and the recent reviews. That's the answer.
