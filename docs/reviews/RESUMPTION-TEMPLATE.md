# Session Resumption — Catalyst Engine

> **How to use this file**
>
> - At each session close, **Cursor refreshes Part 2** (the CURRENT STATE block) — a one-prompt job.
> - **Pedram copies this whole file** as the first message of the next Claude (or Cursor) thread.
> - If the Claude **knowledge-base** doc copies are older than the latest push, **upload fresh ones**
>   before starting — the repo is the source of truth (see Part 1 → _Where the truth lives_).
> - **Part 1** is the stable preamble (rarely changes). **Part 2** is the only section that changes
>   per session.
>
> This template is the thing `PROJECT-WORKFLOW.md` §10 (_Switching chat sessions_) points at; the
> doc-access posture it assumes (Claude has no repo access; reads uploads / repo links / pastes) is
> defined in `PROJECT-WORKFLOW.md` §1 (_Roles_) — this file cross-references those, it does not
> restate them.

---

## Part 1 — Stable preamble (rarely changes)

### What we're building

**Catalyst Engine ("Engine C")** — a **two-sided influencer-marketing platform** for the **EU/UK**
market. It connects **agencies** and **creators**; the two sides plus the admin console are the
**three _sides_ of the platform** — never "three platforms" (one product, one data model, three
surfaces).

**Stack:**

- **Backend:** Laravel 11 (PHP), tested with **Pest**.
- **Frontend:** two Vue SPAs in a **pnpm monorepo** — `apps/main` (the **agency + creator** app,
  split by **role routing**, not two builds) and `apps/admin` (the internal admin console). Shared
  code in `packages/` (`@catalyst/ui`, `@catalyst/api-client`).
- **Data / infra:** **Postgres 16**, **Redis**, **AWS `eu-central-1`** (S3 for private media via
  presigned URLs).

### The three roles

- **Pedram** — solo dev, decision-maker, and the **relay** between the two AIs. Gives **terse
  direction** in **single consolidated Cursor prompts**. Delegates genuinely ("your call") — when he
  does, **decide with stated reasoning**, and he retains the override.
- **Cursor** — the **implementing agent**, with **full repo access**. Plans, builds, tests, commits.
- **Claude** — the **independent reviewer / architect**, with **NO repo access**. Reviews via
  uploaded files, repo links, and pasted snippets; provides architectural + security counsel.

### The chunk loop

`inventory (cite path:line)` → `kickoff (locked decisions)` → `plan-pause (no code until approved)`
→ `build` → `spot-check` → `two-commit pair (feat + docs)` → **held push** (push only on Pedram's
explicit call).

Full lifecycle in `PROJECT-WORKFLOW.md` §3; the "Q-and-A before code" shape in §6; spot-check
discipline in §7.

### Standing disciplines

- **Break-revert on every gate.** For any security/authz gate or architecture-test claim, prove it
  bites: break it, watch the test go red, revert. A gate with no failing-case proof isn't trusted.
- **Docs move with the build.** Every landed change updates its review file / `adhoc-changes-log.md`
  / `tech-debt.md` in the same pair. No silent changes.
- **24-locale i18n done-gate.** New user-facing strings → `en` → regenerate all 24 locales → parity
  green **before** the change is done. Know the **blind spots**: parity proves _keys + placeholders +
  plural shape_ exist in every locale — it can **never** prove a value isn't still English (see the
  AH-001 i18n-completeness tech-debt entry).
- **Read-first.** Read the relevant docs/code before proposing or building. Cite `path:line`.

### Where the truth lives

- **Claude knowledge base** — the uploaded doc set a review thread reads from. **Can go stale**;
  re-upload when older than the latest push.
- **`PROJECT-WORKFLOW.md`** — the master process doc. §5.x is the running list of **team standards
  established through prior chunks** (source-inspection regressions, event-fake split, dual-recipient
  notifications, allowlist discipline, etc.) — consult it before reinventing a pattern.
- **`docs/reviews/adhoc-changes-log.md`** — the **authoritative index of all ad-hoc (AH-NNN) work**.
- **`docs/tech-debt.md`** — deferred items with triggers/owners.
- **`docs/reviews/sprint-{N}-chunk-{M}-review.md`** — per-chunk decisions; **review files are
  authoritative for counts** (test totals, etc.).
- **Rule:** when the knowledge base and the repo disagree, **the repo wins** — the stale KB copy is
  the thing to fix.

### Mode guidance — how much process a change needs

- **Pure-UI / copy / polish** may **skip the full loop** (the **AH-007 pattern**) — build it, log it,
  done.
- **Schema / auth-gate / API-response-shape / i18n** changes get the **full loop** — these are the
  "not minor even when small" categories; a field added or dropped, a gate loosened, or locale parity
  left red is never minor.
- **Watch batch sprawl.** The **48-files / five-themes** lesson (the AH-013→AH-017 working tree): when
  a working tree grows into **several unrelated themes**, don't log it as one blob — split it into
  **separate AH entries** so each surface stays findable, even under a single feat+docs commit pair.

### Read-first (a fresh thread reads these, in order)

1. `docs/PROJECT-WORKFLOW.md` — master process doc (roles, chunk loop, §5.x standards). **First.**
2. `docs/ACTIVE-PHASE.md` — which phase is live.
3. `docs/reviews/adhoc-changes-log.md` — the ad-hoc index (**Part 2 below points into it**).
4. `docs/tech-debt.md` — deferred items + triggers.
5. `docs/security/tenancy.md` — tenancy contract. `docs/feature-flags.md` — flag registry.
6. Phase spec (`docs/20-PHASE-1-SPEC.md` or the active one) + the specs in `PROJECT-WORKFLOW.md` §11
   (architecture / conventions / API / security / testing) **as the task requires**.
7. The relevant `docs/reviews/sprint-*` / AH log entries for the surface being touched.

---

## Part 2 — CURRENT STATE ⟵ refresh this block at each session close

**Last updated:** 2026-07-12 · **Through:** AH-032 (ad-hoc run) · **HEAD:** the AH-032 close-out
commit (`docs: close AH-032 review, refresh resumption template`), sitting atop the AH-032 pair
`d1f2608` (feat) + `797ba05` (AH-032 docs), atop the prior baseline `4c1a154` — a **three-commit
trio** (`git rev-list --left-right --count origin/main...HEAD` = `0 3`). _(The close-out commit is
not pinned by SHA here — a commit can't contain its own hash; the two SHAs above are the stable
pins.)_ Prior baseline **`4c1a154`** = the AH-029–031 docs commit, i.e. AH-018–031 are **already
pushed** (the earlier templates' blanket "push HELD across AH-018→031" prose was stale; the repo ref
is the source of truth). **Push:** the AH-032 trio is pushed this session on Pedram's call —
post-push `origin/main` = HEAD (the close-out commit).

### Delivered

- **Sprints 0–13 + 3.5 closed** (the full Phase-1 spine: identity/auth, onboarding wizard,
  integrations seams, roster + discovery + pools, campaigns/boards, notifications subsystem, EU
  locale support). Per-chunk decisions in `docs/reviews/sprint-*`.
- **Ad-hoc run AH-001 → AH-032 — all Landed** (AH-001–031 pushed at `origin/main` = `4c1a154`; the
  **AH-032 trio is committed with push HELD**). One line each (detail + decisions in
  `docs/reviews/adhoc-changes-log.md`):
  - **AH-001** — EU locale support (24 languages) + persistence.
  - **AH-002** — Digest/invite email locale docblock + English-only decision.
  - **AH-003** — Wizard slim + profile-basics polish.
  - **AH-004** — Portfolio overhaul (schema + async image worker + drawer).
  - **AH-005** — Creator contact details (phone/WhatsApp/address), connected-agency-visible.
  - **AH-006** — Finish the Connect→Add rename (step-3 social copy).
  - **AH-007** — Creator platform mobile-responsive pass (the pure-UI-skips-the-loop precedent).
  - **AH-008** — Portfolio link cards — copy-URL button.
  - **AH-009** — Standalone creator Profile-edit page (reuses wizard steps 2 & 3).
  - **AH-010a** — Relationship messaging: backend spine + gate + attachments + notifications.
  - **AH-010b** — Relationship messaging: WhatsApp-shaped inbox + thread (frontend).
  - **AH-011** — Onboarding architecture-test cleanup (two pre-existing reds).
  - **AH-012** — WhatsApp-style new-conversation flow (symmetric picker) + provisioning fix.
  - **AH-013** — Two-pane (WhatsApp Web) messaging + real contact avatars.
  - **AH-014** — Campaign `ChatPanel` parity with relationship chat.
  - **AH-015** — Portfolio inline collapsible drawer + preview download.
  - **AH-016** — Creator mobile Profile-nav bootstrap fix.
  - **AH-017** — Creator assignments mobile card redesign.
  - **AH-018** — Verify-email `:app` placeholder fix (regression pinned in the §5.3 rendering test).
  - **AH-019** — Category taxonomy 16→28 + chip-grid picker with select-all.
  - **AH-020** — Verify-email pending page: `?email=` carry on the unverified bounce.
  - **AH-021** — Review page numbering + account step surfaced.
  - **AH-022** — Full ISO country/language pickers + creator accent field (three-concept locale split).
  - **AH-023** — Surname at sign-up + account-creation details on three surfaces.
  - **AH-024** — Reset-password route moved to match the emailed link.
  - **AH-025** — Production admin bootstrap command (`admin:create`).
  - **AH-026** — Onboarding floor + score reweight + wizard % display: region joins the six-field
    profile floor (1:1 FE↔BE, source-scan parity spec); profile unit's 25 pts split floor 13 +
    per-optional credit 12 (gate boolean stays floor-only, score numerator partial via
    `profileEarned()`); both wizard chromes + rail show the `%` alongside "Step X of N"; review
    two-signal copy; `creators:recompute-completeness` one-shot command. **Post-deploy:** run
    `php artisan creators:recompute-completeness` once (idempotent).
  - **AH-027** — Creator completeness `%` on the agency discover detail: read-only display of the
    already-on-the-wire `profile_completeness_score` as a `%` bar on `DiscoverProfilePage`; no BE /
    resource / gate / formula change (`app.discover.detail.completeness` × 24 locales, parity green).
    Rode the AH-026 session by go-ahead but logged as a separate entry (separate surface).
  - **AH-028** — Scroll-to-end gate on the click-through master agreement: the acceptance checkbox
    disables until the terms region is scrolled to the bottom (zoom-tolerant, auto-satisfies on
    non-overflowing content — branch spec-pinned); client-side only, backend/accept-endpoint
    unaware. One additive i18n key (`click_through_scroll_hint`) initially shipped with 10 locales
    on English fallback (AH-001 debt class) — fixed with an MT baseline in the closure commit; the
    Playwright happy-path now genuinely scrolls the terms region (the real markdown overflows it).
  - **AH-029** — Master agreement replaced with the real Catalyst Creator T&Cs (new entity, new
    governing law, 10-clause restructure). Version deliberately held at `1.0`; snapshot is the
    authority, not the label — logged as mandatory tech-debt (no re-consent flow for pre-swap
    signees). Engineering reviewed; legal soundness for existing signees is explicitly for counsel.
  - **AH-030** — Contract step: removed the duplicate `<h2>` inside `ClickThroughAccept` (page-level
    title retained as the single heading).
  - **AH-031** — Platform rebrand, Engine C → Catalyst Engine, across emails (`APP_NAME`), both SPA
    titles, 48× `app.json`, 24× `lang/app.php`, API root JSON, seeded admin name, brand-layer
    comments. Value-only swaps, zero keyset change, parity green.
  - **AH-032** — Campaign-creation form simplification: `objective` select removed (server defaults
    `ugc` via `prepareForValidation`; enum/column/Resource/Overview-tab row stay — contract only
    relaxes), `target_creator_count` input removed (storage/emission stay, API-only), and the whole
    brief block removed (form stops sending `brief`; `sometimes` preserves stored blobs by omission).
    Description absorbs the prose role via a new persistent hint. **Wipe-bug fixed by omission** (the
    old form rebuilt the brief jsonb from partial inputs, wiping `dos/donts/mentions/links/attachments`
    on every save) — pinned by a byte-identical preservation test + a tech-debt forward-guard. i18n
    orphan cleanup ×24 (`fields.objective`/`objective.*` kept for the Overview tab); parity green.
    Full loop, review closed (`docs/reviews/campaign-form-simplification-review.md`).

  > **Not an AH entry:** `docs/runbooks/production-queue-worker.md` (`12a7ef5`) landed this session
  > as a docs-only ops runbook (supervisord/systemd config + the `queue:restart` deploy hook,
  > written after the live stuck-at-Processing portfolio incident). It's an operational reference,
  > not an app change — no AH log entry.

### Load-bearing invariants (do not regress)

- **Messaging gate semantics.** A pair is messageable only when **roster + non-blacklisted +
  approved**; **`declined` is blocked** (a declined connection is _not_ messageable). Mirrored FE/BE;
  the backend is the source of truth.
- **One shared predicate.** `AgencyCreatorRelation::scopePermitsMessaging()` is the single leg both
  the single-pair gate and the set-valued `MessageableContactsFinder` route through, pinned by an
  **agreement test** with a both-ways **break-revert** — the picker and the gate cannot drift.
- **Contact-detail withholding.** The AH-005 contact block is server-gated by omission
  (blacklisted-but-rostered agency gets no keys); the **withholding assertions** pin this — don't
  weaken them.
- **Portfolio ready-gate.** Only `ready` items are previewable/downloadable; processing/failed items
  are gated via the **`PortfolioItemPresenter`** — keep the gate server-side.
- **Provisioning is on intent.** A relationship thread persists on **first sent message OR attachment
  upload — never on open** (opening returns a transient, unsaved thread). Inboxes filter to
  ≥1-message threads.
- **Campaign messaging is untouched** by the relationship-messaging work. AH-014 changed only
  `ChatPanel` **presentation** — no campaign data/behavior/gate change. Keep the spine separate.
- **Profile floor is a 1:1 FE↔BE mirror** (AH-026). The six floor fields (`display_name`,
  `country_code`, `region`, `primary_language`, `categories`, `avatar_path`) live in BE
  `isProfileComplete()` and FE `floorMet` and are pinned by a **source-scan parity spec**
  (`floor-mirror-parity.spec.ts`) that lists the tokens once — a one-sided floor edit is a red.
  **Gate/score separation:** the profile gate boolean is floor-only; the score awards partial
  optional credit (`profileEarned()`), so submit-ready-but-<100% is normal. **No gate reads the
  score** — the submit gate is `incompleteSteps.length === 0`.

### Open threads

- **Campaign Drafts tab** — merged in code, still **pending an independent review pass** (see the
  Live Status pointer in the ad-hoc log).
- **Sprint 10 (Payments/Escrow)** — **blocked on Stripe Connect production approval**; the
  `payment_released` automation is wired but inert until then. Tracked in `tech-debt.md`.
- **AH-029 counsel check (external dependency)** — the master-agreement swap held the version at
  `1.0` across an entity + governing-law change, with no re-consent flow for pre-swap signees. The
  engineering was reviewed (snapshots immutable, tests green); whether that posture is legally sound
  for existing signees is explicitly outside this codebase's review and needs a counsel sign-off.
  Logged as tech-debt (`docs/tech-debt.md` — "Contract version-label ambiguity + missing re-consent
  flow") until resolved either way.
- **Pending post-deploy operational step (AH-026 D5) — still pending, carry forward.** When the
  AH-026→028 range ships, run `php artisan creators:recompute-completeness` **once** (optionally
  `--dry-run` first) so every existing creator's persisted `profile_completeness_score` moves to the
  new formula (region floor + D4 optional credit). Idempotent — safe to re-run; a second run reports
  0 changes. There is **no scheduler**, so this must not be forgotten at the next deploy. The new
  `docs/runbooks/production-queue-worker.md` (`12a7ef5`) cross-links this step so a deploy checklist
  finds both operational obligations in one place. (Also logged as a standing tech-debt obligation
  below.)
- **Key tech-debt pointers** (full detail in `tech-debt.md`):
  - **AH-001 i18n completeness** — English fragments inside translated values in ~10 locales; parity
    is structurally blind to it (per-market human QA is a go-live gate, not a merge gate).
  - **Attachment-orphan sweep** — an upload-then-abandon leaves an empty thread row + orphaned S3
    object; D2 hides it from inboxes but does not clean it up. Deferred to an S3-hygiene sweep.
  - **Pending-incomplete-is-intentional** — a recorded decision (needs no work); it exists to prevent
    a future "fix" of intended behavior. AH-026 reinforced it: with per-optional score credit,
    submit-ready-but-<100% is now the normal case, not an edge.
  - **Completeness-formula recompute is manual** (AH-026) — a formula change leaves un-touched rows
    stale until an operator runs `creators:recompute-completeness`. No scheduler; it's a documented
    post-deploy step. Re-run it on the next weights/floor/split change.
