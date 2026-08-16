# Catalyst Engine — Ad-Hoc Changes Log

A running record of changes made **outside** the sprint plan and the phase roadmap —
out-of-band UX improvements, polish, small fixes, and tech-debt paydowns that don't
belong to any numbered sprint. The aim is simple: nothing the platform does should be
unexplained. If a change isn't a sprint and isn't on the roadmap, it lives here, so any
developer (or future us) can open this one file and know **what changed, why, and where**.

This file is the **index and history** for ad-hoc work. Changes that go through the full
chunk loop still get their own detailed review file in `docs/reviews/`; the entry here is
a short pointer to it, not a duplicate.

---

## How this file works

**Scope.** Any change not driven by the active sprint or the phase spec: UX tweaks, copy
fixes, accessibility, performance polish, small bugfixes, doc corrections, tech-debt
cleanup. If it _is_ part of a sprint, it belongs in that sprint's review, not here.

**Relationship to the existing workflow.** Ad-hoc changes still follow the house loop —
inventory (when the surface is unknown) → kickoff with locked decisions → plan-pause →
build → spot-check → two-commit pair → push. This file doesn't replace that; it's the
durable record so the work isn't invisible afterward. Larger ad-hoc changes get a full
`docs/reviews/<name>-review.md` and this log just links to it.

**Entry lifecycle.** An item starts in **Live Status** (below) when proposed, moves through
`In progress`, and graduates into the **Change Log** as `Landed` once merged. Parked or
dropped items stay in the log with that status so the decision is on record.

**Reading a `Status:` line.** `Status: Landed (push HELD)` records the state at
entry-writing time — it is a **close-time snapshot, not a live claim**. Many older entries
still carry it despite having been pushed long since; the marker means "the push was held
when this was written," never "the push is held now." **Live push state is `origin/main`;
live deploy state is the resumption template's Pushed-≠-deployed section.** Those two are
the only authorities, and they are the reason older entries are deliberately **not**
retro-edited: rewriting history to track a moving fact is how the drift started.
**Convention change (2026-07-26):** new entries write `Status: Landed` plain and keep push
state out of this file entirely, so an entry can never go stale by standing still.

**IDs.** Each entry gets a stable `AH-NNN` id so it can be referenced from commits,
reviews, and conversations.

**Entry template** (copy this for each new change):

```
### AH-NNN · <short title>

- **Status:** Proposed | In progress | Landed | Parked
- **Date:** YYYY-MM-DD (landed date, or last-updated while open)
- **Why:** the user problem / motivation in one or two sentences
- **What:** the change in plain terms
- **Touched:** files / modules / surfaces affected
- **Decisions:** any locked calls made along the way
- **Ref:** kickoff / review file / commit(s), if applicable
```

---

## Live Status (open + in-flight)

| ID  | Title                                    | Status  | Notes                                                                |
| --- | ---------------------------------------- | ------- | -------------------------------------------------------------------- |
| —   | Campaign Drafts tab — independent review | Pending | Merged in code; review file reads "pending independent review pass." |

> Pointer, not an ad-hoc item: **Sprint 10 (Payments/Escrow)** remains the deepest pending
> roadmap dependency, Stripe-gated. Tracked in `tech-debt.md`, not here.

---

## Change Log (newest first)

### AH-069 · Not every campaign asks the creator to post — approval can be the finish line, per campaign

- **Status:** Landed. Full chunk loop (inventory → kickoff → plan-pause → build), so the detail lives
  in its own review file and this entry is the pointer.
- **Commit:** `451388f1` — `feat(campaigns): let a campaign end at draft approval when creators do not post (AH-069)`,
  plus the docs commit carrying this entry, the review file, the `deploy-log.md` PENDING entry, two
  `tech-debt.md` entries, the Sprint-10 spec pointer and the resumption refresh.
- **Date:** 2026-08-16
- **Why:** the assignment lifecycle had exactly one ending — the creator posts the deliverable and
  the post is verified. Real campaigns do not all work that way: for a hand-off deliverable (an asset
  the brand publishes itself, a white-label edit, a stock shoot), the approved draft **is** the
  delivery, and the platform was making creators stare at a "Mark as posted" form for a post that
  will never exist. Client ask (B) of Draft Workflow v2.
- **What:** a per-campaign toggle, **"Deliverables are posted by creators"**, on the create form and
  the Settings tab. When it is off, approving a draft chains a second transition in the **same**
  transaction and lands the assignment on a new terminal state, `completed_on_approval`; the board
  stops rendering the Posted column; the creator gets a green completion banner instead of a post
  form; the posting deadline is never stamped and the overdue scan never flags it. When it is on —
  which is every campaign that exists today — nothing changes at all.
- **Touched:** `apps/api` — new migration, new `AssignmentStatus` case + `AuditAction` verb +
  `NotificationType` + mailable and Blade view, `CampaignAssignmentStateMachine`,
  `CampaignAssignmentReviewController`, `CampaignController`, both campaign requests,
  `CampaignResource`, `Campaign` + factory, `SendAssignmentNotifications`, `WriteSystemMessage`,
  `BoardResource` / `BoardController` / `BoardCardService` / `BoardDefaults`, `OverdueScanService`,
  `CampaignInvitationService`, `CreatorAssignmentDraftController`, `JobLifecycleState`,
  `lang/*/campaigns.php` × 24, one new test-helper endpoint. `apps/main` — `CampaignForm.vue`,
  `CampaignCreatePage.vue`, `CampaignDetailPage.vue`, `CreatorAssignmentDetailPage.vue`,
  notification templates, `app.json` / `creator.json` / `notifications.json` × 24, a new Playwright
  leg. `packages/api-client` — campaign and notification types.
- **Decisions (D1–D9 locked at kickoff; Q1–Q8 ruled at plan-pause):** **Q1 killed the backfill
  command** the kickoff had specified — the column defaults to `true` instead, which is exactly what
  the command would have written, with no window in which live campaigns read OFF. That left a
  deliberate **two-layer default**: the DB default is `true` (the safety floor for anything that
  doesn't name the field — API, factories, seeders), the create form pre-sets `false` and always
  sends it explicitly (the product decision). **D3's chain runs inside the review path's existing
  transaction** — both audit rows or neither — and drops AH-042's `withoutGlobalScope` read, which
  would have been cargo here: the campaign is route-bound and already authorised. **D6's Posted
  column is a render filter, never a row deletion**, and flipping to OFF while cards sit in Posted is
  refused with a 422 naming the creators. **D7's banner is a third branch**, not a widened
  `isVerified` — the copy assertion exists because a banner that says "verified" about a post that
  does not exist is the specific lie this chunk could most easily have told.
- **The listener sweep found nothing to suppress.** All seven `AssignmentTransitioned` consumers were
  put on paper before code (the AH-043 lesson). Five are untouched because their own verb gates
  already exclude a verb they have never seen; two opt in deliberately. `DispatchPostedContentVerification`
  needed no guard for the same reason, and that is pinned rather than assumed.
- **Risk line: PROD-DATA RISK: LOW–MEDIUM**, re-derived down from the kickoff's MEDIUM. The plan's
  MEDIUM rested on the backfill window; Q1 removed the window and the data mutation with it. One
  additive column, catalogue-only, no existing row read or rewritten, no board row deleted, no
  historical notification or audit row touched. The sharp edge that remains is the new terminal
  state: nothing in the application can leave it. Mail copy moved in all 24 locales, so the
  **queue-worker restart obligation applies**; deploy order is **migrate only**, no command.
- **Q7, inherited in writing by Sprint 10:** `completed_on_approval` joins `isPaymentEligible()`,
  which means a payable assignment on this campaign type may have **no `campaign_posted_content` row
  at all**. Any payment code that reaches for the posted-content record must treat its absence as
  normal. Recorded in the review file and pointed at from `tech-debt.md`.
- **Break-reverts:** eight mutations, each reddening the test it was aimed at, each restored
  byte-for-byte by SHA-256. Two of them are worth the ink: the enum case with no `JobLifecycleState`
  family arm reddens **PHPStan before any test runs**, and in the two `posting_due_at` mutations the
  toggle-ON sibling test correctly stayed green — the §5.34 pair proving the guard is narrow is a
  different test from the one proving it works.
- **Gates:** backend **2522 / 1 skipped** (9368 assertions), PHPStan level max **0 errors**, Pint
  clean; `apps/main` **1500 / 154 files**, `apps/admin` **449 / 53**, api-client **204 / 9**;
  typecheck clean, ESLint 0 errors; the new E2E leg green with **flaky-10 at 10/10**; **full
  Playwright 28/28, first pass** — the suite is one spec larger (this chunk's leg), and the
  `2fa-enrollment-and-sign-in` cold-start flake that AH-064/066/068 each had to re-run passed first
  this time, recorded as an observation rather than a fix.
- **The E2E limit, stated:** the new leg covers the toggle-**OFF** lifecycle end to end, through the
  real Settings switch. The posting path — approve → post → verify — remains the named pre-existing
  gap it was before this chunk.
- **Ref:** [`draft-posting-toggle-review.md`](draft-posting-toggle-review.md) · plan
  [`draft-posting-toggle-plan.md`](draft-posting-toggle-plan.md) · inventory
  [`draft-workflow-v2-inventory.md`](draft-workflow-v2-inventory.md). With AH-068 (ask A), this
  **closes Draft Workflow v2**.

### AH-068 · Draft rounds are numbered and visible — one "Draft {n}" vocabulary across five surfaces, the creator's own review trail, and the round in the cycle's notifications

- **Status:** Landed. Full chunk loop (inventory → kickoff → plan-pause → build), so the detail lives
  in its own review file and this entry is the pointer.
- **Commit:** `36fa454` — `feat(drafts): number the review rounds and say so on every surface (AH-068)`,
  plus the docs commit carrying this entry, the review file, two `tech-debt.md` entries and the
  resumption refresh.
- **Date:** 2026-08-16
- **Why:** the review cycle has always kept every round — one `campaign_drafts` row per submission at
  `version = max + 1`, closed in place by its own `review_feedback` + `reviewed_at`. It just never
  **said so**. One surface called a round "Version 3", the next "Draft v3", a third "Pending review";
  the creator could not read the feedback their own browser had already been sent; and no
  notification or email named the round it was about. Client ask (A) of Draft Workflow v2.
- **What:** **"Draft {n}" everywhere**, in five status-bearing forms
  (`Draft 2 — awaiting review / changes requested / approved / not accepted / submitted`), resolved
  through one shared helper so five surfaces cannot drift. The creator's history rows gain the
  agency's feedback and `Reviewed {date}`. The two review-cycle mails and the four in-app
  notification types carry the round, read off the event context the domain already emits.
- **Touched:** `apps/main` — `CreatorAssignmentDetailPage.vue`, `ReviewDraftDrawer.vue`,
  `DraftsTab.vue`, `BoardCardDrawer.vue`, `NotificationCenter.vue`, new
  `modules/campaigns/draftRounds.ts`; `app.json` / `creator.json` / `notifications.json` × 24.
  `apps/api` — `SendAssignmentNotifications.php`, both draft mailables, their two Blade views, new
  `Mail/Concerns/CarriesDraftRound.php`, `lang/*/campaigns.php` × 24. **No migration, no route, no
  resource shape, no gate, no enum case, no flag, no api-client type.**
- **Decisions (D1–D6 locked at kickoff; Q1–Q8 ruled at plan-pause):** the round **is**
  `campaign_drafts.version` — no counter column, pinned structurally. **Q1 → the conditional round
  detail**, not a `{version}` body placeholder: every notification row already in production was
  written without a `version`, and a body placeholder would have left a hole in all of them. **Q2 →
  omit-when-absent**, so a direct state-machine call cannot invent a round number. **Q3 → five
  composite keys with an `{n}` param**, never template-side concatenation — Hungarian ships
  `{n}. vázlat — ellenőrzésre vár`, which is the argument. **Q6 → unify the copy, keep both key
  paths**: D2's "one vocabulary" was reinterpreted per §5.32 as what users read, not key topology, so
  the Sprint-9-c2 namespace split that fixed a real harness key-miss survives. **Q8 → the two new
  §5.3 mail render tests stay in this chunk**, because shipping changed mail copy with no render test
  is the exact false-green §5.3 exists to prevent.
- **The §5.3 gap this closed.** Both draft mailables shipped in Sprint 9 Chunk 2 with **queue
  assertions only** — three specs, every one a `Mail::assertQueued(...)`, which renders nothing. Two
  new files (40 tests) now render subject and body across six locales plus the flaky 10, assert the
  three outcomes produce three genuinely different bodies, assert the no-round shape reads as a
  complete message, and pin the emitted deep link, **which was pinned nowhere before**. The
  still-uncovered mailables get a `tech-debt.md` entry rather than a drive-by fix.
- **Risk line: PROD-DATA RISK: LOW.** No migration, no schema change, no column, no backfill, no
  one-shot, no scheduled job, no new API field. The only new runtime write is **one additive key in
  the `data` bag of newly-created notification rows**. The one live-data consequence — historical
  notification rows rendering with a hole — was designed around rather than accepted, and is pinned
  by a pre-AH-068-shape fixture asserted byte-identical. Mail copy changed in all 24 locales, so the
  **queue-worker restart obligation applies** on deploy.
- **D6 evidenced twice.** Thirteen paths zero-diff by command output (state machine, both
  controllers, both draft resources, every `Campaigns` enum, the `Notifications` and `Audit` modules,
  migrations, routes, Boards, api-client, admin), **plus** an executable parity test driving
  submit → changes → resubmit → approve through the real endpoints and pinning every row, status and
  audit verb. Two mutations confirmed both pins load-bearing, with byte-identical SHA-256 restores.
- **Gates:** backend **2438 / 1 skipped** (9077 assertions), PHPStan level max **0 errors**, Pint
  clean; `apps/main` **1485 / 152 files**, `apps/admin` **449**, api-client **204**; typecheck clean,
  ESLint 0 errors; every parity spec green; **full Playwright 27/27 effective** (26 first-pass plus
  the documented `2fa-enrollment-and-sign-in` cold-start flake, green on isolated re-run — the same
  flake class AH-064 and AH-066 both recorded). The E2E suite is claimed as
  the standing pre-push **gate, not as coverage** — no spec asserts a string this chunk renames and
  none traverses the draft cycle.
- **Ref:** [`draft-rounds-review.md`](draft-rounds-review.md) · plan
  [`draft-rounds-plan.md`](draft-rounds-plan.md) · inventory
  [`draft-workflow-v2-inventory.md`](draft-workflow-v2-inventory.md).
  **Chunk B (the per-campaign posting toggle) shipped separately as AH-069** — ask (B) of the same
  inventory, one chunk later.

### AH-067 · `composer install` was silently rotating the production `APP_KEY` — the hook is gone, and it's pinned

- **Status:** Landed
- **Commit:** `b13ee71` — `fix(api): stop composer install from rotating the production APP_KEY (AH-067)`
  (the fix + the pinning test). Docs (this entry, the deploy-log incident record, the runbook and
  process-doc notes, the tech-debt entry) land in a separate, docs-only commit — see this file's
  header commit list at push time.
- **Date:** 2026-08-11
- **Why:** during the AH-065/AH-066 deploy, `composer install --no-dev` triggered an `APP_KEY`
  rotation in production, taking MFA down platform-wide. The full incident — symptom, timeline,
  recovery, outcome — is recorded verbatim in
  [`deploy-log.md`](../runbooks/deploy-log.md)'s 2026-08-11 entry, "Anything unexpected"; this
  entry covers the **fix and the pin**, not a second copy of the incident narrative.
- **What:** `apps/api/composer.json`'s `post-install-cmd` carried both
  `"@php -r \"file_exists('.env') || copy('.env.example', '.env');\""` and
  `"@php artisan key:generate --ansi"`. Stock Laravel doesn't have a `post-install-cmd` at all —
  those two commands belong to `post-root-package-install` and `post-create-project-cmd`
  respectively, hooks Composer fires **once**, at `composer create-project` scaffolding, never
  again. This project's `post-install-cmd` fired on **every** `composer install`/`update`,
  including a production deploy's `composer install --no-dev`. Both commands were already present,
  correctly scoped, in the once-only hooks — so the fix is a straight **removal** of the duplicate
  four lines from `post-install-cmd`, not a move; nothing a fresh `composer create-project` needs
  is lost.
- **Provenance — this had been visible for a long time and never read as a production risk.**
  `docs/reviews/sprint-1-chunk-7-1-review.md` (Sprint 1) already notes in passing that "the
  composer post-install hook runs `php artisan key:generate` before migrations" — as a _CI_
  observation, where a fresh throwaway `APP_KEY` every run is invisible and harmless. The same
  hook, on the same trigger, is catastrophic the moment it runs against a live database instead of
  a disposable CI one. Nobody connected the two contexts until this deploy did it for real.
- **Touched:** `apps/api/composer.json` (4-line removal), new
  `tests/Feature/Core/ComposerInstallHooksTest.php`. **No migration, no route, no Resource/Controller
  change, no i18n key** — this is install-time tooling, not application code.
- **The pin, source-scan style.** `ComposerInstallHooksTest` parses `composer.json` and asserts,
  for every command under `post-install-cmd`, `post-update-cmd`, and `post-autoload-dump` (the
  three hooks Composer fires on **every** install/update): no occurrence of `key:generate`, no
  occurrence of `.env`. A counter-test asserts the **opposite** for the once-only hooks —
  `post-create-project-cmd` still calls `key:generate`, `post-root-package-install` still
  bootstraps `.env` — so the fix can't be read as "delete these commands everywhere," which would
  quietly break a fresh scaffold. Break-revert: re-added the two lines to `post-install-cmd`,
  watched the first test go red naming the exact violation, reverted, confirmed green.
- **Risk line:** touches install-time **tooling** — not a migration, not an API/resource shape,
  not a validation rule, not a gate/policy, not a notification/mail path, not an i18n keyset, and
  none of the platform's named pinned surfaces. The **production impact of the underlying bug**
  was severe (MFA down platform-wide); the **fix itself** is a same-day, zero-runtime-code, 4-line
  removal with a pinning test — the risk asymmetry between the incident and the fix is the point.
- **Docs:** `production-queue-worker.md` §8 step 2 now names the composer install step explicitly
  and notes the hooks are verified inert (pointing at this incident);
  `WORKING-PROCESS.md` §5 and `PROJECT-WORKFLOW.md` §5.40 both gain the standing rule — install-time
  tooling must never mutate secrets or `.env`. Tech-debt gains a **separate, still-open** entry:
  `APP_KEY` living in exactly one `.env` file on one volume is a distinct structural gap this fix
  does not close (it stops the key from rotating unexpectedly; it doesn't give the key a second,
  durable copy). `RESUMPTION-TEMPLATE.md`'s Open threads references both.
- **Gates:** `apps/api` — the two new tests pass (16 assertions), break-revert confirmed red on the
  violation and green on the fix; full `composer stan`/`pint`/`test` run below.

### AH-066 · The campaign-invite and talent-pool pickers search the roster server-side — the 100-row page was also the alphabet's ceiling

- **Status:** Landed
- **Commit:** `6cdf0a5` — `fix(pickers): search the roster server-side so every creator is reachable`.
- **Date:** 2026-08-11
- **Why:** Pedram could not find a connected creator ("Rita") in the campaign invite dialog's
  search box, despite the creator being rostered with the agency.
- **What:** `InviteCreatorsDialog` and `AddCreatorsToPoolDialog` fetched **one 100-row page** of
  the roster on open and filtered it **client-side**. 100 is also the server's hard page cap and
  neither dialog asked for page 2, so on a roster past that size the alphabet's tail was not
  merely hard to search — it was **never fetched**, unreachable by scrolling and impossible to
  invite. The agency that reported it has **176 creators**, leaving 76 uninvitable. Both dialogs
  now send `?q=` debounced at 300ms, mirroring the pattern `CreatorRosterPage` already used, with
  a sequence guard so a slow early response cannot overwrite a newer one.
- **Touched:** `apps/main` — `modules/campaigns/components/InviteCreatorsDialog.vue` + new spec,
  `modules/pools/components/AddCreatorsToPoolDialog.vue` + spec,
  `modules/roster/pages/CreatorRosterPage.vue` + spec. **No backend diff, no route, no migration,
  no i18n key** — `rosterApi.list`'s `q` param already existed and was simply never wired into
  these two callers.
- **Pre-existing, and old.** Neither dialog is arc work. `InviteCreatorsDialog` dates to `5b3d711`
  (2026-06-05, Sprint 8 Chunk 2); `AddCreatorsToPoolDialog` to `2484357` (2026-06-04) — both two
  months before the Jobs Board arc (AH-053, earliest 2026-07-24) and before every deploy on
  record, including the 2026-07-26 AH-051/052 deploy. The 100-row ceiling shipped on day one of
  each dialog and survived at least two production deploys before anyone hit an agency large
  enough to expose it.
- **Three fixes fall out of the one root cause.** The **empty states split**: a search matching
  nothing now renders no-match rather than the generic no-roster state, which also stops the
  search field itself being unmounted mid-edit — the old behaviour trapped the user by removing
  the box they were typing in. **`clearable` writes `null`**, which the `.trim()` reads
  downstream threw on; the search ref is nullable now in all three components, read through one
  trimmed accessor. And moving to server-side search meant the **pool dialog's hard-blacklist
  confirm** could no longer resolve a selected creator against rows that had scrolled out of the
  current query — `rosterById` changed from a computed (current page only) to an
  **accumulating map** across every query the dialog has run since opening, so a creator selected
  under an earlier search term still trips the blacklist gate. Skipping that gate silently is a
  safety regression, not a cosmetic one.
- **Risk line, explicit per surface named:** no migration, no API/resource-shape change (both
  dialogs call the pre-existing `rosterApi.list({ q })`, unchanged shape), no validation rule, no
  gate/policy, no notification/mail path, no i18n keyset — the "Showing X of Y" hint Pedram was
  offered was explicitly declined. None of the named pinned surfaces (jobs-board predicate,
  listing gate, application endpoints, board column negatives, D5 mapping, the pixel mount, the
  glow-token contract) are touched; grepped both architecture-spec directories for either
  component and for `CreatorPolicy` and found no reference. It **does** sit under two
  E2E-traversed surfaces: `AddCreatorsToPoolDialog` (`talent-pools.spec.ts`, asserts
  `add-creators-row-*`) and `CreatorRosterPage` (`roster-search-and-affordances.spec.ts`) — both
  green on the full run below.
- **Adjacent to, but not caused by or a workaround for, the missing `campaign_applications`
  GRANTs.** The invite this dialog completes ends in `CampaignAssignmentController::store`, which
  unconditionally calls `settlePendingApplication()` against `campaign_applications` in the same
  transaction. If those GRANTs are still missing on production, an invite sent through this
  now-fixed picker hits the same `SQLSTATE[42501]` already reported — that exposure sits on the
  invite endpoint itself and predates this fix entirely. This change widens **who is reachable to
  invite**; it does not change **what happens** once an invite is sent, so it neither depends on
  nor repairs the GRANTs gap. Unrelated to `APP_ENV=local` on the same grounds — nothing here
  reads environment or debug configuration.
- **Test-gap closure.** `InviteCreatorsDialog` had **zero spec coverage at any layer** —
  no unit test, no E2E traversal — before this fix. That absence is precisely how a
  76-creator-invisible bug shipped and stayed invisible through two deploys; it gets nine tests
  now (`InviteCreatorsDialog.spec.ts`, new), covering the debounced query, reaching creators past
  page one (the original bug, reproduced and then fixed), both empty states, `clearable`, and
  stale-response ordering. The pool dialog's existing D-5 test — which had pinned the client-side
  filter that **was** the bug — is rewritten against the server-side behaviour, plus new tests for
  the accumulating blacklist map. `CreatorRosterPage.spec.ts` gains one test for the `clearable`
  crash. Break-revert on each: restoring the client-side filter reddens five invite specs;
  reverting the accumulating map reddens the blacklist-confirm test; restoring the bare `.trim()`
  reddens the roster page's clear spec.
- **Gates:** `apps/main` Vitest **1457 / 151 files** (incl. `i18n-locale-parity.spec.ts`, green —
  no locale file was touched); typecheck clean; ESLint 0 errors (2 pre-existing warnings, both in
  files this change does not touch). Full Playwright, dev stack cold-started for the run: **27/27
  effective** — 26 green on the first pass, one unrelated cold-start flake
  (`2fa-enrollment-and-sign-in.spec.ts`, spec #19 — a locator timeout in 2FA enrollment, which
  shares no code with either dialog) green on isolated re-run, matching the flake class AH-064
  already documented. No spec's assertions were altered to reach green. `apps/admin` carries no
  diff from this change and was not re-run. No Pest / Pint / PHPStan: `apps/api/**` untouched.

### AH-065 · Agency-side `CreatorPolicy` checks stop gating on `users.type` — membership is the only authority on the agency side

- **Status:** Landed
- **Commit:** `df1c56c` — `fix(creators): authorise agency teammates by membership, not users.type`.
- **Date:** 2026-08-10
- **Why:** Pedram found a production agency admin whose account read `type = creator` and asked
  whether that was correct, having assumed agency admins were always `agency_user`-typed.
- **What:** It is correct, and it is the **common** case, not an edge one: the public sign-up form
  is the platform's only sign-up path and always stamps `UserType::Creator`
  (`SignUpService`); accepting an agency invitation later
  (`AgencyInvitationService::accept()`) adds the membership row but never flips `users.type`. A
  production query Pedram ran found **26 of 30** agency admins carrying `type = creator` this way.
  Three `CreatorPolicy` methods — `canSeeContactDetails`, `canMessageRelationship`, and the
  private `hasAgencyAccess` (backing `view`/`update`/`delete`) — nonetheless gated on
  `$user->type === UserType::AgencyUser` before ever consulting membership, so those 26 admins
  were denied contact details, relationship messaging, and creator profiles outright. The SPA's
  own `requireAgencyUser` router guard already treats membership as authoritative
  (`apps/main/src/core/router/guards.ts`); these three backend checks did not.
- **Touched:** `apps/api` — `Modules/Creators/Policies/CreatorPolicy.php` (the three methods),
  `Modules/Identity/Database/Factories/UserFactory.php` (new `creatorTypedAgencyMember` state, to
  reproduce the production shape in tests), `tests/.../CreatorPolicyTest.php`. **No migration, no
  route, no Resource/Controller shape change, no i18n key.**
- **Pre-existing, and old — not the Jobs Board arc's work.** The offending checks date to
  `5dc1e1f` (2026-06-28, "add contact details with connected-agency visibility") and `2656e5a`
  (2026-06-29, AH-010a messaging). Both predate the Jobs Board arc (AH-053, earliest 2026-07-24)
  by nearly four weeks and predate the 2026-07-26 AH-051/052 deploy as well — this bug shipped on,
  and survived, at least that one prior production deploy before this fix.
- **The fix:** accepted, active agency membership (`activeAgencyIds()`,
  already the mechanism behind every other agency-side check in this policy) is now the **sole**
  authority; the `users.type` guard is removed from all three methods rather than widened. The one
  narrowing: `canMessageRelationship`'s platform-admin exclusion, previously an accident of admins
  holding no membership row, is now an **explicit** `if ($user->type === UserType::PlatformAdmin)
return false;` — a guarantee should not depend on the accident that produced it.
- **Break-revert found a hollow test, not just a missing one.** The existing deny-path specs
  passed even before this fix — but only because no test seeded **any** membership anywhere, so
  "not a member of this agency" and "this agency has no members at all" were indistinguishable.
  Each now seeds a genuine member alongside the outsider being tested, and dropping the `user_id`
  filter in `activeAgencyIds()` reddens exactly the three abilities this fix touches — proving the
  new deny-path is real rather than coincidental. Confirmed separately: an empty membership list
  still firmly denies (`hasNonBlacklistedRelation()`'s `$agencyIds === []` early return is a
  round-trip saver on top of a `whereIn` that already compiles to `0 = 1`, not the actual gate).
- **Risk line, explicit per surface named:** touches a **gate/policy** (`CreatorPolicy`) —
  squarely that category. No migration, no API/resource shape (no Resource or Controller changed),
  no validation rule, no notification/mail path, no i18n keyset. None of the named pinned surfaces
  (jobs-board predicate, listing gate, application endpoints, board column negatives, D5 mapping,
  the pixel mount, the glow-token contract) are touched — grepped every consumer of
  `CreatorPolicy` and every architecture spec; the one adjacent reference,
  `CampaignApplicationListItemResource`'s docblock (`@see CreatorPolicy::canSeeContactDetails()`,
  AH-051), is a comment justifying an omission and calls no method — the applications list is
  unaffected. It does sit under one E2E-traversed surface: `creator-detail.spec.ts` exercises the
  contact-email visibility this policy gates, unaffected in practice because that spec's fixture
  is already `agency_user`-typed.
- **Not related to the missing `campaign_applications`/`campaign_job_notifications` GRANTs or to
  `APP_ENV=local`.** Different tables (`agency_memberships`, `creators`), different subsystem, no
  dependency on environment or debug configuration either way. This is a genuine code fix to a
  pre-existing authorization defect, not a workaround for either open ops item.
- **Test-gap closure.** No test before this fix exercised a `creator`-typed user holding real
  agency membership — every prior fixture was either `agency_user`-typed or held no membership at
  all, so the widening this fix needed was invisible to the suite. `UserFactory::creatorTypedAgencyMember()`
  reproduces the production shape directly; `CreatorPolicyTest.php` adds cases for `view`,
  `canSeeContactDetails`, and `canMessageRelationship` for both a creator-typed member (must pass)
  and a creator-typed non-member (must still fail), plus a platform-admin-with-membership case
  proving the explicit exclusion holds. Break-revert as above.
- **Gates:** `apps/api` Pest **2391 passed, 1 skipped, 8771 assertions**; Pint passed; PHPStan
  (Larastan) — **no errors**. No Vitest/Playwright: `apps/main`/`apps/admin` carry no diff from
  this change.

### AH-064 · Meta Pixel on the sign-in page — scoped to one route, Advanced Matching off, un-gated by decision

- **Status:** Landed
- **Commits:** `ebcc50a` — `feat(auth): load the Meta Pixel on the sign-in page only`; `2153e9e` —
  `test(playwright): block Meta from every E2E run` (the close-out fix, below).
- **Date:** 2026-07-31
- **Why:** Pedram asked for the Meta Pixel on the login page, supplying the vendor snippet.
- **What:** A queueing loader (`metaPixel.ts`) mounted from `SignInPage`'s `onMounted` — **not**
  `index.html`, **not** `AuthLayout`, which is how the vendor snippet is normally installed. Advanced
  Matching is disabled before `init`. It fires with no consent gate and the pixel ID is hardcoded;
  both are Pedram's recorded decisions, not oversights.
- **Touched:** `apps/main` — `modules/auth/internal/metaPixel.ts` + spec (new), `SignInPage.vue` +
  spec. `apps/main` + `apps/admin` — `playwright/fixtures/test.ts` (new, both suites), all 20 spec
  files' `test` import, `tests/unit/architecture/e2e-third-party-blocked.spec.ts` (new). Docs —
  `tech-debt.md`. **No backend diff, no route, no i18n key, no migration.**
- **The placement is the security content of this entry.** The pixel reports the full document
  location with every event, and three sibling auth routes carry a **single-use credential in the
  query string** — `/auth/reset-password?token=…`, `/auth/verify-email`, `/auth/accept-invite`.
  Installed the normal way, in `index.html`, this would have sent password-reset and invite tokens to
  Meta. So the mount point is not a stylistic preference, and `SignInPage.spec.ts` **pins it** with
  the reason written into the test: a future refactor that "tidies" the loader up into the layout or
  the entry point re-opens the leak, and there is no way to notice it from the outside.
- **Automatic Advanced Matching is off**, via `fbq('set', 'autoConfig', false, id)` **before** `init`.
  Left on, the pixel scrapes recognised form fields and ships hashed values — and the form on this
  page is the login form, so the harvested field would be the user's email as they type it. The
  **ordering** is asserted, not just the call: `set` after `init` is silently a no-op, so the wrong
  order looks identical in review and does nothing.
- **The consent gap is a recorded decision, taken with the conflict on the table.** The pixel sets
  `_fbp`, a non-essential cookie; `docs/05-SECURITY-COMPLIANCE.md` §2.1 puts non-essential tracking
  behind consent and §2.7 requires a CMP in Phase 1; that CMP is Sprint 14 work and **does not exist
  in either SPA**. Catalyst is a UK entity, so PECR applies directly. This was raised as a hard
  stop-gate **before any code was written**, with the three options spelled out; Pedram chose to ship
  un-gated on `/sign-in` alone and log it. `tech-debt.md` carries it with the trigger "the Sprint 14
  consent banner lands" and the CSP/SRI conflict recorded alongside (SRI is **unachievable** here —
  Meta serves `fbevents.js` as a mutable file, so a pinned hash breaks on their next deploy).
- **The hardcoded ID.** No `VITE_META_PIXEL_ID`, so local development and staging report into the
  production pixel. Pedram's explicit choice when offered the env-var alternative; the fix is a
  one-line swap whenever that traffic needs separating.
- **What this entry got wrong, and the fix that closes it.** The consent shortcut was flagged and
  logged. **The pixel's E2E blast radius was not**, and it should have been: every spec in the main
  suite starts at `/sign-in`, so from `ebcc50a` onward **every E2E run fetched `fbevents.js` and
  registered the production pixel once per spec** — CI traffic in real analytics, and a third-party
  network dependency on the critical path of a suite that has nothing to do with analytics. Found at
  close-out by asking which specs traverse the changed surface, which is the check that should have
  run at build time. `2153e9e` fixes it: an **auto-applied** Playwright fixture aborts both Meta
  endpoints on the browser context, so no spec opts in and no future spec has to remember. It also
  makes each spec **prove** it — a `requestfinished` listener collects Meta URLs and the fixture
  asserts the list is empty after the body, and since an aborted route raises `requestfailed`
  instead, anything collected is a request that escaped. **The pixel code is untouched by the fix**,
  deliberately: the block belongs to the harness, so production still ships exactly what a reviewer
  reads in `metaPixel.ts`. Specs now take `test` from the fixture rather than `@playwright/test`, and
  an architecture spec pins that for **both** suites — importing from the package yields an unblocked
  context and silently resumes calling Meta with everything green. `apps/admin` has no pixel and
  carries the fixture anyway, because "applied to e2e-main, not to e2e-admin" is precisely what
  reproduced the DB-isolation incident on 2026-07-13.
- **Verified both directions.** The suite being green proves nothing _finished_; it does not prove the
  block ever fired. A throwaway spec (run, then deleted) confirmed the positive: on `/sign-in`,
  `connect.facebook.net/en_US/fbevents.js` is **attempted and aborted**, zero Meta requests finished.
  Blocking the loader stops the chain at the root, which is why one abort replaces the two requests
  observed before the fix.
- **Gates:** `apps/main` Vitest **1444 / 150 files**; `apps/admin` **449 / 53**; both typechecks
  clean; ESLint 0 errors; **full Playwright green in both suites** with the dev stack down — main
  27/27 effective (one documented cold-start flake, green on isolated re-run), admin 2/2 — and **zero
  requests to Meta across the whole run**. No Pest / PHPStan / Pint: `apps/api/**` is untouched.

### AH-063 · The sign-in landing's marketing tail — creator-guide CTA, marketing footer, and the interactive monogram

- **Status:** Landed
- **Commits:** `ceb15f0` — `feat(auth): creator-guide CTA and marketing footer on the sign-in landing`;
  `6d970a8` — `chore(auth): add the creator guide PDF served by the sign-in landing`; `f62529e` —
  `fix(auth): seat the footer monogram in flow and link out to the marketing site`; `6ddad53` —
  `feat(auth): rebuild the footer monogram with the live site's glass card and motion`; `63f1d8d` —
  `test(auth): pin the --auth-glow-gradient full-strength contract` (the close-out tripwire, below).
  Split by **eyes-on round**: `ceb15f0` is the build, `f62529e` and `6ddad53` are two successive
  passes answering what Pedram saw on screen, and the PDF stands alone because a 5.6 MB binary in a
  code commit makes the diff unreadable.
- **Date:** 2026-07-31
- **Why:** The rebrand Figma gave the login page a creator-guide block and the marketing site's
  footer. Neither existed, and the monogram in the footer is a signature brand moment on
  catalyst-growth.com that the SPA rendered as a flat shape.
- **What:** Two sibling components rendered **only in hero mode** (`CreatorGuideCta`,
  `AuthMarketingFooter`), an interactive monogram (`AuthFooterMonogram`) rebuilt from the live site's
  SVG with orbit and sheen animation plus cursor-driven 3D tilt and glare, a link table
  (`footerLinks.ts`) owning the inert-vs-anchor branch, the guide PDF, 12 i18n keys across 24 locales,
  and three design tokens.
- **Touched:** `apps/main` — `modules/auth/layouts/AuthLayout.vue`; `modules/auth/components/` gains
  `AuthMarketingFooter.vue`, `CreatorGuideCta.vue`, `AuthFooterMonogram.vue`, `footerLinks.ts`,
  `monogramTilt.ts`, each with a spec; `modules/auth/assets/catalyst-monogram.svg` +
  `assets/guide/guide-card-{1,2,3}.webp`; `public/creator-guide.pdf`; `core/i18n/locales/{24}/auth.json`;
  `tests/unit/architecture/auth-layout-shape.spec.ts`. `apps/admin` —
  `modules/auth/layouts/AuthLayout.vue` (the compensating dim, below). `packages/design-tokens/tokens.css`.
  **No backend diff, no route change, no migration.**
- **The token change is a semantics change, and it fans out across both SPAs.**
  `--auth-glow-gradient` used to carry its strength **baked into the stops** (`rgba(…, 0.2)`); it is
  now the aurora at **full strength**, and every consumer dims it itself with `opacity: 0.3`. That
  had to happen because the footer's radial bloom masks the gradient and needs it undimmed. Both
  references agree on 30% (the Figma overlay and the live site's own band), so the old `0.2` was also
  wrong against the comment that claimed 30%. All three consumers — both SPAs' `AuthLayout.vue` and
  the new footer — carry the compensating declaration.
- **That fan-out check happened at close-out, not at build time, and that is the process note.**
  Changing a token's meaning in `packages/design-tokens` is a cross-app contract change; it was
  treated as part of the footer work and never flagged. The outcome was correct — all three consumers
  were right — but correctness rested on someone remembering the admin SPA, and a fourth consumer
  that forgot would render the aurora **~5× too bright** with every gate green. Nothing pinned it:
  `aurora-surfacing.spec.ts` only asserts the variable is _referenced_, `no-hard-coded-colors.spec.ts`
  only forbids hex literals, and the design-tokens spec covers no `--auth-*` token. `63f1d8d` closes
  it with a tripwire pinning **both halves** of the contract — the token carries no alpha, and every
  consumer declares `opacity` in the same rule — and it **discovers** consumers by scanning both
  SPAs rather than listing them, so a fourth is covered when it is written rather than when someone
  remembers the file. Break-revert: dropped `opacity: 0.3` from the admin `AuthLayout`, watched it go
  red naming that file across the SPA boundary, restored it. **The generalisable lesson: a shared
  token whose _meaning_ changes is a fan-out event and belongs in a flag, and "I checked all the
  consumers" is a claim that should be a test.**
- **`v-html` is introduced here under a suppression, and the justification is in the file verbatim:**
  _"Build-time asset with no runtime input, so there is nothing to sanitise. It must be inlined for
  the CSS to reach inside it."_ The markup is a `?raw` SVG import resolved at build time; nothing
  user-supplied can reach it, and inlining is what lets the scoped CSS animate the artwork's internals
  (`:deep` throughout, since v-html content carries no scope attribute). It is nonetheless a **new
  suppression of an XSS rule on the unauthenticated login page**, which is why it is recorded here
  rather than left as a code comment. It is also why `no-hard-coded-colors.spec.ts` stays green: the
  colour literals moved into the `.svg` asset, which that spec does not scan — a real, if benign, hole
  in that gate's reach.
- **The `AuthLayout` ceiling was raised 215 → 240, with the note the spec demands.** The spec's own
  docblock requires a chunk-scoped justification on any raise, and it names the Figma nodes: the two
  blocks are sibling components with their own coverage, and the layout gained **only** two imports,
  two `v-if="isHero"` tags and the spacing rules that place them — including the negative margin that
  lets the footer bleed past the layout's 24 px gutter. **No `<script setup>` logic was added**;
  `isHero` is still the only computed, so the no-function and no-multi-statement-arrow guards hold
  unchanged. The ceiling exists to keep the layout a structural shell, and it still is one.
- **Decisions:** **Hero mode only** — the marketing tail is for the landing, not for every auth
  route. **The tilt maths live in `monogramTilt.ts`**, extracted as a pure function precisely so the
  interactive behaviour is unit-testable; jsdom cannot verify the rendered transform, but it can
  verify the numbers. **`prefers-reduced-motion` is honoured** for orbit, sheen and tilt alike.
  **Footer links point at catalyst-growth.com** through one table, with the inert-vs-anchor branch
  owned in one place so a not-yet-supplied URL renders as text rather than a dead link.
- **Tech-debt logged:** the **5.6 MB PDF ships inside the SPA bundle** rather than from a CDN. It is
  served unauthenticated from the busiest page the product has, it is copied into `dist/` on every
  build, and at 5.6 MB (down from a 12 MB export via Ghostscript) it is the largest artefact in the
  repo. Nothing is broken — `public/` assets are not bundled into JS, so first paint is unaffected —
  but `catalyst-engine-public-prod` already exists as the CloudFront-fronted public bucket, and this
  is exactly the asset class that belongs there.
- **Gates:** `apps/main` Vitest **1444 / 150 files**; `apps/admin` **449 / 53**; typechecks clean;
  ESLint 0 errors (the 2 `vue/no-v-html` warnings are the pre-existing onboarding pair, not this
  one — the new suppression is inline); **locale parity green across all 24 locales**, with the 12 new
  keys verified present in every locale and their **values** audited against English in the flaky 10
  (the only three EN-identical values are `footer.nav.blog = "Blog"` in hu, mt and ro, which is the
  correct word in all three — a loanword, not a fallback); full Playwright green.
- **Spec changed to stay green:** `auth-layout-shape.spec.ts` only, `MAX_LINES` 215 → 240, justified
  above. No spec was weakened, skipped or relaxed; every other touched spec **gained** tests.

### AH-062 · Tall card stacks scroll instead of squashing

- **Status:** Landed
- **Commits:** `ee8a917` — `fix(boards): let tall card stacks scroll instead of squashing the cards`
- **Date:** 2026-07-31
- **Why:** Past a screenful, a board column compressed its cards to fit the viewport instead of
  scrolling, so every card in a tall stack became unreadable rather than the last one being below the
  fold.
- **What:** `flex: 0 0 auto` on the list's children, in both column components. The scroll container
  already existed and was correct — flex children were shrinking to fit and defeating it. Pure CSS:
  no script, no template, no prop.
- **Touched:** `apps/main` — `modules/boards/components/BoardColumn.vue`,
  `modules/boards/components/BoardApplicationsColumn.vue`. Nothing else, anywhere.
- **It reads as trivial and it is not, which is the whole reason it survived this long.** The cards
  were not merely squashed — `.board-card` sets `overflow: hidden`, so the compressed content was
  **clipped silently** instead of overflowing visibly. There was no ragged layout to notice; the cards
  just quietly stopped saying anything. Both components carry a comment recording that, because the
  next person to see `flex: 0 0 auto` will reasonably wonder why one declaration warranted a commit.
- **Verifiability, stated plainly: this is eyes-on-only.** No test covers it and none is added,
  because the claim is a **layout** fact and **jsdom has no layout engine** — it computes no box
  sizes, so a Vitest assertion about whether children shrink would pass identically before and after
  the fix. The honest options were a real-browser Playwright assertion on rendered geometry (the
  AH-057 precedent, which bought a genuine viewport-dependent bug) or nothing; nothing was chosen
  here because the surface is already E2E-traversed for behaviour and the failure mode is visible the
  moment anyone looks at a tall column. **Recorded so a future reader does not mistake the absence of
  a test for an oversight** — and so that if this regresses, the fix is to add the browser-level
  assertion, not to hunt for the unit test that was never possible.
- **Arc-adjacency, named:** `BoardApplicationsColumn.vue` **is** AH-059's D4 pseudo-column, the
  closest call in the batch. The change adds one declaration to its list children and touches
  neither the pending-only predicate, the ability plumbing, nor the drag exclusion the D4 pins
  protect — and those pins (`BoardApplicationsColumn.spec.ts`, `BoardColumns.spec.ts`,
  `BoardView.spec.ts`) are all green.
- **Gates:** `apps/main` Vitest **1444 / 150 files**; typecheck + lint clean; full Playwright green
  (the board layout is traversed by `jobs-board-full-lifecycle.spec.ts` and
  `campaign-applications.spec.ts` for behaviour, not geometry).

### AH-061 · Review hand-off from the board card drawer

- **Status:** Landed
- **Commits:** `877e81b` — `feat(boards): review hand-off from the card drawer's Draft-submitted row`
- **Date:** 2026-07-31
- **Why:** The drawer told an operator a draft had been submitted and then offered no way to act on
  it — a dead end next to a row that is entirely about something needing a decision.
- **What:** A Review button on the `draft_submitted` timeline row, styled as the Resolve action
  beside it, shown only with the `review` ability, emitting a payload-free navigation hand-off that
  `CampaignDetailPage` turns into a Drafts-tab switch.
- **Touched:** `apps/main` — `modules/boards/components/BoardCardDrawer.vue` + spec,
  `modules/boards/components/BoardView.vue` (new `canReview` prop, `review` emit),
  `modules/campaigns/pages/CampaignDetailPage.vue` (`onBoardReview` → `tab = 'drafts'`) + spec.
  **No backend diff, no new i18n key, no new ability, no route.**
- **Decisions:** **A navigation hand-off, not a write.** The button moves the operator to the surface
  that already owns draft review; it approves nothing and mutates nothing, so it needs no new
  endpoint, no new gate and no confirm. **An existing ability, reused** — `review`, the same one
  already behind `canResolve`, rather than a fifth clone. **An existing key, reused** —
  `app.campaigns.review.action`, so this adds nothing to the 24-locale surface. **A tab switch, not a
  route** (`tab.value = 'drafts'`), so the AH-024 route-table precedent does not apply and the board's
  scroll position and drawer state are the caller's business, not the router's.
- **Gates:** `apps/main` Vitest **1444 / 150 files** (drawer spec +2: Review offered on a submitted
  draft, hidden without the ability; detail-page spec +1: the hand-off leaves Board for Drafts);
  typecheck + lint clean; full Playwright green, including the two specs that traverse the drawer.

### AH-060 · Answer a campaign invitation from its detail page

- **Status:** Landed
- **Commits:** `8081435` — `feat(creators): answer a campaign invitation from its detail page`
- **Date:** 2026-07-31
- **Why:** The invitation list offered View, Accept and Decline; a creator who used View to read the
  invitation before deciding then had to navigate **back** to the list to act on it. The one place a
  creator has all the information is the one place they could not answer.
- **What:** The same accept/decline pair the list offers, in the same shape and copy, on the
  assignment detail page — gated on `status === 'invited'`, hitting the same
  `creatorAssignmentsApi.accept` / `.decline` endpoints, raising the same toasts. One `answering`
  flag drives both buttons, so a double-answer is impossible in flight. Mobile splits the pair across
  the width, as the list does.
- **Touched:** `apps/main` — `modules/creators/pages/CreatorAssignmentDetailPage.vue` + spec.
  **Nothing else: no backend diff, no new endpoint, no new i18n key, no route.**
- **Decisions:** **Reuse, not re-implement** — the same two API functions and the same six existing
  i18n keys, so the two surfaces cannot drift in copy or behaviour and the 24-locale surface is
  untouched. **The gate is the status, not a new ability**: the endpoints already authorize the
  creator, and the UI simply declines to offer an answer to an invitation that is no longer open.
  **A failed call leaves the pair in place** — spec-pinned — so a network error is retryable rather
  than a dead end, which is the failure mode the list already had right.
- **Gates:** `apps/main` Vitest **1444 / 150 files** (spec +4: renders the pair, accept, decline, and
  failure leaves the pair in place); typecheck + lint clean; full Playwright green — this surface is
  the one theme in the batch **no** spec traverses (`jobs-board-full-lifecycle` accepts from the
  list, not the detail page), so Vitest is its only automated cover.

### AH-059 · Jobs Board chunk 5 — the eyes-on fixes, the board Applications column, lifecycle reflection, the full-loop E2E, and the arc's close-out

- **Status:** Landed
- **Commits:** `a70c548` — `feat(creators): coarse job lifecycle state, derived from the assignment enum`;
  `c27926a` — `fix(campaigns): make the application-mail flag's silence legible`;
  `b2ca89b` — `feat(creators): emit assignment_state on the job card and detail`;
  `bb825a8` — `fix(creators): order the engagement above the application on job surfaces`;
  `75076ef` — `feat(campaigns): make the list page's Job board column an interactive toggle`;
  `ce841e2` — `test(campaigns): pin the single-key listing PATCH the list page sends`;
  `e2501b3` — `refactor(campaigns): extract RejectApplicationDialog + useCampaignApplications`;
  `a4897b5` — `feat(boards): render pending applications as the board's first column`;
  `df99cac` — `test(helpers): seed a listABLE campaign for the full-lifecycle E2E`;
  `0c7ea82` — `test(playwright): the jobs board end to end, both roles, one spec`;
  `26a127a` — `test(campaigns): satisfy PHPStan on the AH-059 test files`; plus the docs commit
  carrying the review, this entry and the D7 close-out documents. Split by **surface and decision**.
  Two deliberate features: `e2501b3` is a **pure refactor** standing alone (the 11-spec
  `ApplicationsTab` guard held byte-for-byte across it, so a later break is provably not the
  refactor), and `ce841e2` is **test-only** — "the single-key PATCH is judged against the stored row"
  is a claim about existing backend code, not a change to it.
- **Date:** 2026-07-29
- **Why:** The last chunk of the five-chunk Jobs Board arc. Pedram's eyes-on of chunks 3 and 4
  produced five items; this chunk answers all five, adds the cross-role regression net owed since
  chunk 3, and writes the arc's close-out documents.
- **What:** **D1** — the rejected-chip contradiction is dead: a creator who was rejected and then
  invited anyway no longer reads "Not selected" beside a live invitation (branch ordering; the
  application row stays `rejected`, because the agency's answer to _that_ application was truthful).
  **D2** — investigated, explained, **not fixed** (below). **D3** — the campaigns-list "Job board"
  chip is now an interactive switch driving the same endpoint and gates as the Settings tab, with a
  confirm on the ON direction and refusals that **name** the missing fields. **D4** — the board's
  **Applications column**: first column, real-column visual language, pending-only, **no drag in or
  out**, the same Accept and Reject dialogs the tab uses. **D5** — the creator's job surfaces reflect
  the engagement's stage (In progress / Completed / Ended) through one exhaustive mapping over the
  16-case assignment enum. **D6** — one Playwright spec walks the whole loop across both roles.
  **D7** — the combined first-enable ritual, the arc's single-deploy runbook entry, the resumption
  template, two recorded gaps.
- **Touched:** `apps/api` — one new enum (`JobLifecycleState`), one new correlated subquery, two
  resources, the applications notifier's logging, one flag docblock, one `_test` helper. `apps/main` —
  the two creator job surfaces, the campaigns-list toggle, `BoardApplicationsColumn` +
  `RejectApplicationDialog` + `useCampaignApplications`, the board's prop chain. `packages/api-client`
  — the `JobLifecycleState` union. i18n **528 leaves** (22 × 24). Docs — `feature-flags.md`,
  `runbooks/production-queue-worker.md`, `tech-debt.md`, `RESUMPTION-TEMPLATE.md`.
  **No migration, no new production route, no new flag, no new notification type, and zero diff in the
  Boards module, the migrations, the automation seeds and the whole D2 mail path** — all four proven by
  `git diff --stat` returning empty, not by prose.
- **The item that turned out not to be a bug, and is the real content of this entry:** the "auto-reject
  mail missing" report. **There was no mail-path defect.** `application_notifications_enabled` was
  **never armed** — the `features` row's `updated_at` never moved, `audit_logs` holds zero
  `feature_flag.toggled` rows for it against two for the other flags, Mailhog's 153 retained messages
  contain **zero** hits for `application` or `selected` with the gaps landing exactly where the six
  emissions were, the DB rows and `laravel.log` show the job ran to completion and wrote its in-app
  rows, and nothing application-shaped was ever queued or failed. The kickoff's premise — manual reject
  mails, auto-reject does not — **is not in the evidence**: neither mailed. Confirmed by Pedram at
  plan-pause (in-app notification only, for the manual reject too). **The c3/c4 eyes-on item #2 is
  corrected here: the observed symptom was real; the attributed asymmetry was not.**
- **What that investigation legitimately found, and what shipped because of it:** the notifier was
  **silent about its own silence** while its sibling fan-out logs every run, so an operator could not
  distinguish "no mail because an operator chose that" from "no mail because something is broken" —
  which is exactly what cost an hour of eyes-on. S2 ships one structured log line at every emission
  decision naming the type, the recipients, the queued count and the flag state; the flag is still read
  in **one** place. It also corrects a docblock that described a flag re-check the job deliberately does
  not do (c4's C5 ruling: a mail flag must not gate database truth). And the missing arm-verification
  became **step 4 of D7's ritual — read both flags back** — with this incident named as its reason.
- **Decisions:** **One derived field serves two decisions.** D1's stated mechanism (consult
  `assignment_ulid`) could not work on the card, which does not carry it — and a c4 test asserts it must
  not, because Q7 ruled detail-only. So `assignment_state` ships on **both** shapes, D1 becomes branch
  ordering over D5's field, and Q7 is **preserved, not reversed**. **The assignment always wins whenever
  one exists, including an ended one** — "Not selected" is unreachable for a pair that was ever invited,
  and that is the honest story. **`approved` is In progress, not Completed** (nothing is live yet), and
  **the payment pair is Completed** — the `isTerminal()` trap, since `payment_released` is terminal _and
  a success_. **The no-drag rule is enforced by ABSENCE** — no `<draggable>`, absent from `localColumns`
  — because a `:disabled` flag is one prop away from being flipped by someone who does not know what it
  protects. **The Applications tab stays** (full history, including rejected; the column is a
  pending-only working surface), with the revisit note recorded. **The E2E stops** at the creator
  accepting the offer, stating two product facts plainly rather than working around them: the assignment
  auto-advances to `contracted` (the campaign is `requires_per_campaign_contract = false`), and the card
  **does not move columns** — same ULID, changed chip.
- **Ref:** [`jobs-board-c5-review.md`](jobs-board-c5-review.md) (plan:
  [`jobs-board-c5-plan.md`](jobs-board-c5-plan.md)). Five mutations executed and reverted; full gate
  board including **Playwright 27/27** with the dev stack down and a restart plus health-check after.
  Two new `tech-debt.md` entries: the shared dev/E2E Redis queue (recorded, not fixed) and the
  **product** gap that no creator-facing `is_discoverable` control exists.

### AH-058 · Jobs Board chunk 4 — agency applications: the Applications tab, accept, reject, and terminal auto-reject

- **Status:** Landed
- **Commits:** `0abba72` — `refactor(campaigns): extract CampaignInvitationService + AssignmentOffer from store()`;
  `78c2dd8` — `feat(notifications): jobs-board application vocabulary, flag, notifier + mails`;
  `86a44a4` — `feat(campaigns): applications tab endpoints — list, accept, reject`;
  `5f6486c` — `feat(campaigns): D3b invite-path convergence — settle pending applications in store()`;
  `af0f343` — `feat(campaigns): terminal auto-reject for pending applications`;
  `d33df9e` — `feat(campaigns): applications tab — list, accept dialog, reject confirm`;
  `ef195f8` — `test(campaigns): satisfy PHPStan on the new application test files`;
  `8ff9985` — `feat(creators): bridge an accepted application to its offer`;
  `a1c66ab` — `test(campaigns): agency applications e2e leg + seed helper`;
  plus the docs commit carrying this entry and the review. Split by **surface and risk**, not by
  sub-step. The one deliberate feature of the split is that **`5f6486c` stands alone**: D3b is the only
  change here that alters behaviour on a path the agency already uses every day, and a reviewer who
  wants to read exactly that and nothing else should be able to `git show` one commit. The pure
  refactor is first and separate for the same reason inverted — if a later commit broke an invite, the
  refactor is provably not why, because it changed no test.
- **Date:** 2026-07-28
- **Why:** Chunk 4 of the five-chunk Jobs Board arc, and the chunk that makes chunk 3 mean something.
  AH-056 let a rostered creator browse an agency's listed campaigns and apply; nothing read those
  applications. Chunk 4 is the agency's half: read them, and answer them.
- **What:** An **Applications tab** on the campaign detail (pending-first, badge counting pending
  only, applicant shown at roster level with their note); **accept**, which opens a real offer form
  and creates a **standard invitation** the applicant can still decline; **reject**, terminal and
  pending-only behind a confirm dialog; **terminal auto-reject**, so a cancelled or completed campaign
  answers whatever was still pending; the three-type application notification vocabulary chunk 3
  deferred, dual-emitting in-app plus queued localized mail behind one new default-OFF flag; and the
  **bridge** that carries an accepted creator from the job page to the offer waiting for them.
- **Touched:** `apps/api` — one new controller, one job, three services (`CampaignInvitationService`,
  `CampaignApplicationDecisionService`, `CampaignApplicationNotifier`), one value object
  (`AssignmentOffer`), three mailables + their Blade views, one new enum
  (`ApplicationRejectionCause`), two new `AuditAction` cases, three new `NotificationType` cases, one
  Pennant flag, a shared offer-validation trait, three routes, one `_test` helper; `apps/main` — the
  tab, two dialogs, the extracted `OfferFieldsForm`, the creator job-detail third state; `packages/api-client`
  — the agency-side application types. i18n **1176 leaves** across four files × 24 locales. **The
  backend migration diff is zero** — chunk 3's table, its `responded_at` column and its
  `(agency_id, status)` index are the whole storage.
- **Decisions:** **Applications are a TAB, not a board column** — a recorded §5.32 reinterpretation of
  chunk 3's migration docblock, and the evidence is structural: `board_cards.assignment_id` is
  `NOT NULL` + `UNIQUE` + `CASCADE`, so a card **is** an assignment at three layers and an applicant
  has none; and §4.4's drag-is-consequence-free invariant cannot express accept or reject. Nothing
  chunk 3 shipped is wasted — the index and the denormalized `agency_id` serve the tab identically,
  and the `agency_id` turned out to be **load-bearing for a use the docblock did not anticipate** (it
  is what lets the auto-reject job re-impose tenancy in a worker that has none). **Accept creates a
  standard invitation**, so an accepted applicant is byte-indistinguishable downstream from a cold
  invitee and can still decline: applying is not a contract. **The `is_discoverable` gate leg is
  dropped** on the AH-051 ruling — browsing preference is not eligibility, and an applicant who has
  since hidden from discovery must not 404 on their own application — while the agency-wide hard
  blacklist re-check and the availability 409 are kept, because a blacklist may postdate an
  application. **No reject reason, anywhere**: not collected, not stored, not rendered; the audit row
  and its actor is the record, and the creator-facing copy is the same kind generic sentence either
  way. The three-way argument was heard; this is the only version that keeps the chunk migration-free.
  **One ability, not a fifth clone**: `invite` for both accept and reject, `view` for the list.
  **The preference group splits** — `jobs_board` is new, and `campaign.job_posted` moved into it,
  honouring the trigger chunk 3 wrote into the enum rather than re-arguing it.
- **The finding the plan-pause existed for, and the real content of this entry:** `config/queue.php`
  sets **`after_commit => false`** on all four connections, so a `Mail::queue()` issued inside an open
  transaction is visible to a worker **immediately** — and if that transaction rolls back, the creator
  has already been told they were accepted for an invitation that does not exist. So **every DB write
  in this chunk is one transaction and every emission happens after it returns**, at all four emission
  sites. The residual failure mode inverts to the strictly better one: a committed accept whose in-app
  row failed to write, rather than a rolled-back accept that already mailed. Proven by mutation, not
  by reading: moving the accept emission inside the transaction reds three tests.
- **And it named a pre-existing defect of the same class.** `SendAssignmentNotifications` queues mail
  from **inside** `CampaignAssignmentStateMachine::commit()`'s transaction — the same shape, on the
  platform's single status authority, reached by every assignment transition in the app. It is now a
  `tech-debt.md` entry with the trigger "the next chunk touching the state-machine emission path", and
  deliberately **not** fixed here: moving that dispatch is a change with its own review, not a rider
  on an applications chunk.
- **The one touch on the live invite path, named as a delta.** `store()` gained a `DB::transaction()`
  it did not have, plus one guarded hook that settles a pending application for the pair it is
  inviting — called from **both** branches, the create and the AH-035 declined re-offer, because a
  create-only hook passes every other test in the suite while leaving an application pending forever
  on exactly the pair AH-035 exists to serve. The transaction is a behavioural delta (a mid-flight
  failure that previously left an orphaned assignment row now leaves none) and a strict improvement.
  A pair with **no** application is pinned byte-identical to before, field by field, with the
  notification flag deliberately **armed** so a silent flag-OFF cannot be why nothing was sent.
- **Ten mutations, each reverted.** The claims that could otherwise be read rather than proven: the
  emission ordering, the accept transaction, the D3b hook on both branches, the dropped-discoverable
  leg and the kept-blacklist leg in opposite directions, the auto-reject job's in-worker `pending`
  re-filter, the mail flag (one mutation, four reds — one per emission site, which is what a
  single-checkpoint flag buys), the D7 subquery's creator correlation, and the badge's pending-only
  count.
- **Gates:** backend **2338 passed / 1 skipped** (8587 assertions, up from 2234), PHPStan level max
  **0 errors**, Pint clean; `apps/main` **1319 / 141 files**, `apps/admin` **449**, api-client **204**;
  three typechecks clean, ESLint 0 errors (the same 2 pre-existing `v-html` warnings); locale parity
  green across all four touched i18n files; both notification tripwires green (`LIVE_TYPES` 15 → 18 by
  hand); **full Playwright 26/26 in 4.7m** including the new agency-side leg, with the dev stack down
  and an isolated E2E database.
- **Ref:** [`jobs-board-c4-review.md`](jobs-board-c4-review.md) — the completion package, with the
  Production-posture section, the ten mutations verbatim, and the D1 annotation against chunk 3's
  docblock. Plan: [`jobs-board-c4-plan.md`](jobs-board-c4-plan.md). Inventory:
  [`jobs-board-c4-inventory.md`](jobs-board-c4-inventory.md).

### AH-057 · Jobs Board chunk 3, eyes-on fixes — the mobile bottom bar, the job detail frame, and the campaigns listing column

- **Status:** Landed
- **Commits:** `fc88347` — `fix(creators): unclip the mobile bottom bar and frame the job detail (AH-057)`;
  `bbafc9c` — `feat(campaigns): show jobs-board listing state on the campaigns table (AH-057)`;
  `d147baf` — `test(creators): iPhone-13 playwright project for the creator shell (AH-057)`;
  plus the docs commit carrying this entry and the review addendum. Split by **kind**, not by
  finding: the two creator-shell fixes are one commit because they are one eyes-on pass over one
  shell, the campaigns column is a `feat` because it adds a surface nobody had, and the E2E project
  is its own `test` commit on the AH-056 precedent (`d37d43c`) — a new Playwright project is
  suite infrastructure and deserves to be readable alone.
- **Date:** 2026-07-27
- **Why:** Pedram ran the AH-056 chunk-3 walkthrough locally after the review closed and found three
  things the gate board had not. All three are UI, all three are on surfaces AH-054/AH-056 shipped,
  and none is a behaviour regression — which is precisely why they are worth a numbered entry: they
  are the class of defect this project's gates are structurally blind to, and two of the three fixes
  are about ending that blindness rather than about the pixels.
- **What:** (1) The creator **mobile bottom bar** becomes four primary slots plus a "More" sheet, so
  its width no longer depends on how many sections the creator shell has. (2) The **job detail page**
  gets the outlined-card framing the list page it is reached from already had. (3) The **campaigns
  table** gets a "Job board" column — a chip when listed, a dash when not. (4) A second Playwright
  project on the **iPhone 13 profile**, scoped to one new spec, that measures the bar's geometry.
- **Touched:** `apps/main` only — `CreatorDashboardLayout.vue`/`.spec.ts`,
  `CreatorJobDetailPage.vue`/`.spec.ts`, `CampaignListPage.vue`/`.spec.ts`, `playwright.config.ts`,
  the new `playwright/specs/creator-shell-mobile.spec.ts`, one `test.slow()` on
  `failed-login-lockout-and-reset.spec.ts`, and i18n ×48 (`availability.json` ×24 for "More",
  `app.json` ×24 for the column header and the "Listed" chip). **The backend diff is zero** — no
  migration, no endpoint, no resource, no enum, no lang file.
- **Decisions:** **Four slots, not five or six.** A bottom bar neither wraps nor scrolls, so its
  width is a hard budget and the fix has to be a policy, not a nudge. The four are the working loop
  — dashboard, jobs, assignments, messages; Profile and Availability are deliberate overflow,
  because both are settings-shaped and neither is where a creator returns daily. Adding a nav item
  now lands it in More by default, which is the safe direction; promoting one is a deliberate edit
  to `MOBILE_PRIMARY_KEYS`, and the spec pins that every key in that list resolves to a real
  `navItems` entry so a rename cannot silently empty a slot. **Listing state is its own column, not
  a second chip in Status**, because listing is orthogonal to lifecycle — a campaign can be
  active-and-unlisted or active-and-listed, and conflating them is what would make a later "listed
  only" filter awkward. **The mobile E2E project is one spec, not the suite doubled**: the desktop
  legs already cover behaviour, and what went unseen was a viewport-dependent layout fact.
- **The gap this closes, which is the real content:** `useDisplay()` reads `window.innerWidth` and
  jsdom fixes it at 1024, so the mobile chrome had **never rendered under any Vitest spec in this
  repo**, and Playwright ran a single desktop project. The bottom bar was unpinned at every layer.
  That is how AH-056's sixth nav item reached a phone before it reached a test, with 24/24 green.
  Both layers now cover it: the layout spec narrows the window before creating the Vuetify instance
  (and stubs `VMenu` inline, since it teleports and swallows its own activator), and the mobile
  project measures what jsdom cannot, having no layout engine.
- **The E2E leg paid for itself on its first run.** It found a **residual 5px overhang the fix had
  not removed**: Vuetify sets `min-width: 80px` at
  `.v-bottom-navigation .v-bottom-navigation__content > .v-btn`, and the override was written at a
  shorter selector depth — which ties on specificity and loses on order. Five 80px floors make a
  400px row inside a 390px viewport, so the bar overhung 5px each side and scrolled. Invisible to the
  eye and invisible to every structural assertion, because the labels were inside the frame. The leg
  now holds the bar to zero horizontal overflow and asserts both button boxes and **label** boxes,
  since a button can sit inside the viewport while its label spills out — and it was labels the
  eyes-on screenshot showed clipped.
- **Reinterpretation, recorded:** the ruling specified `devices['iPhone 13']`, whose own
  `defaultBrowserType` is `webkit`. Playwright's WebKit build for macOS 14 is frozen and bus-errors
  on launch on this host, so the project runs the iPhone 13 **profile** (390×664, DPR 3, touch,
  mobile UA) on the **Chromium engine**, and `test:e2e:install` still fetches chromium alone. What
  the spec measures is layout geometry from Vuetify's flex CSS at a phone viewport — the profile
  supplies that, and it does not turn on the engine. A leg green only in CI is not a leg anyone would
  trust. If a WebKit-specific rendering bug is ever the suspect, that is a different spec and a
  working WebKit build.
- **`listed_at` stays off the agency side, deliberately.** Fix 3 surfaced an asymmetry:
  `CreatorJobCardResource` emits `listed_at` and the creator's board renders D4's "Listed today /
  N days ago" chip from it, while `CampaignResource` does not, so the agency's own table can show only
  _that_ a campaign is listed, never _when_. One resource line and an emission test would close it,
  and it was offered as part of fix 3. **Pedram's boolean-only choice, recorded as a choice**: not an
  oversight, and explicitly **not** a tech-debt entry — the boolean is the authority the switch writes,
  and the column is honest about what it shows. Chunk-5 polish if the date is ever wanted.
- **Rode along:** `test.slow()` on spec #20 (failed-login lockout). It is genuinely slow, not flaky —
  eleven sequential sign-in round-trips plus three clock manipulations against `php artisan serve`'s
  single-process model, ~27s standalone on a quiet host and past the default 30s budget when the rest
  of the suite competes for the same cores. It was the only red in a full AH-057 run and passed alone
  immediately after; on the verifying run it took 33.7s, so the budget was the bug, not the
  behaviour. Named because the diagnosis cost several full-suite runs: a timeout under machine load
  and a real regression are indistinguishable from the summary line, and the honest way through was
  to re-run in isolation rather than to guess.
- **Gates:** `apps/main` 139 files / 1287 tests, typecheck clean, lint 0 errors (the same 2
  pre-existing `v-html` warnings), locale parity green across the 48 moved files; **full Playwright
  25/25 in 4.2m** — 24 desktop plus the new mobile leg — with the dev stack down for the run and
  health-checked after (API `/up` 200, both SPAs 200, queue worker up). Backend untouched, so no belt
  re-run: AH-056's six break-reverts and its seven-case §5.34 set stand as verified at that close.
- **Ref:** [`jobs-board-c3-eyeson-fixes.md`](jobs-board-c3-eyeson-fixes.md) — the read-only report
  that preceded the ruling; the dated addendum at the foot of
  [`jobs-board-c3-review.md`](jobs-board-c3-review.md) — the closed review's own record, appended
  without touching its text.

### AH-056 · Jobs Board chunk 3 — the creator job board, apply, and the job-posted fan-out

- **Status:** Landed
- **Commits:** `81df0b5` — `feat(jobs-board): creator job board read + apply backend (AH-056)`;
  `928ccce` — `feat(jobs-board): job-posted notification vocabulary + flag-gated capped fan-out (AH-056)`;
  `0cf6275` — `feat(jobs-board): campaigns:preview-job-notifications operator command (AH-056)`;
  `4e527e7` — `feat(jobs-board): creator job board SPA — routes, nav, pages, apply dialog (AH-056)`;
  `d37d43c` — `test(jobs-board): playwright browse-detail-apply leg + listed-job helper (AH-056)`;
  plus the docs commit carrying this entry and the review. Split by **surface**, not sub-step: the
  read/apply backend, the outbound fan-out (a different risk profile — it sends mail, and deserves to
  be readable alone), the operator command, the SPA, and the E2E leg.
- **Date:** 2026-07-27
- **Why:** Chunk 3 of the five-chunk Jobs Board arc. Chunks 1 and 2 gave campaigns the copy a creator
  reads and the agency-controlled listing switch (AH-053/AH-054), but nothing consumed either. This is
  the creator half: rostered creators of an agency see that agency's listed campaigns, open one, and
  apply. It is the arc's **first creator-visible surface** and its **first mail fan-out to live
  creators**.
- **What:** Three additive migrations (`campaign_applications`, `campaign_job_notifications`,
  `campaigns.listed_at`); one shared six-leg visibility predicate object; three creator endpoints
  (`GET /creators/me/jobs`, `GET /creators/me/jobs/{campaign}`, `POST …/apply`); two narrow job
  resources carrying a three-field brand subset; the `campaign.job_posted` notification/audit pair;
  a Pennant-gated, capped, once-stamped fan-out fired by the listing flip; an operator command with
  `--dry-run` and `--limit`; and the creator SPA's "Job Posts" section — nav item, list, detail, apply
  dialog — behind the `layout: 'creator'` shell.
- **Touched:** `Modules/Campaigns` (models, services, job, mailable, enums), `Modules/Creators`
  (controller, resources, request, feature flag, routes), `Modules/Audit` + `Modules/Notifications`
  (one enum case each), `Modules/Admin` (the flag registry — see below),
  `apps/main` creators module (routes, nav, two pages, api file),
  `packages/api-client` (`types/campaign.ts`), i18n ×24 in `creator.json` / `availability.json` /
  `notifications.json` / backend `campaigns.php`, `docs/security/tenancy.md` (three §4 rows),
  `docs/feature-flags.md`, `docs/tech-debt.md` (three entries).
- **Decisions:** **Applications are a TABLE, not an assignment state** — the arc's biggest fork,
  decided by the finding that a pre-invited assignment state would let `store()`'s idempotency branch
  silently swallow an agency invite. **Visibility is one predicate object, six legs**, composed
  identically by the list, the detail, the apply endpoint and the fan-out's recipient query; the
  detail 404s rather than 403s, so an invisible job is not probeable by ULID. **The brand subset is
  three fields** (`name`, `logo_url`, and `website_url` on detail only), pinned by exact-keyset
  equality — the arc's first AH-005-class boundary crossing. **The fan-out is flag-gated (default
  OFF), capped at 50 per run, and stamped once per (campaign, creator)**, so a re-list never
  re-notifies. **The trigger is the listing flip, not a scheduler** — the production scheduler is
  unverified, so a cron-triggered feature could ship, pass every gate, and never fire.
- **Kickoff reinterpretations (§5.32), recorded:** the flip detector is the **existing** before/after
  audit-snapshot pair in `CampaignController::update()`, not a new campaign event (the single
  write-path grep makes it airtight); and the audit noun is `campaign_application.*`, not
  `application.*` — the house `<subject-keyed-to-table>.<verb>` convention wins, and `application.*`
  would have collided with the creator's ONBOARDING application, a different noun entirely.
- **Added after plan-pause (C5):** a **sixth** predicate leg excluding creators the campaign's brand
  has **hard**-blacklisted, on all four surfaces. The board must never solicit an application the
  invite gate would hard-block. Hard only — soft is warn-at-invite semantics and must not hide jobs.
  Note the two postures sit side by side deliberately: the relation-level leg excludes hard **and**
  soft (stricter, via `permitsMessaging()`), the brand-level leg excludes hard only (mirroring the
  invite gate).
- **Known costs, accepted:** chunk 4's accept becomes a cross-table transaction (application closed +
  assignment created atomically) — named now, solved there. The email has no per-creator opt-out,
  because the email channel has never been wired through preference reads platform-wide. A creator
  stamped before their mail dies at transport is never re-notified for that job (queue-then-stamp:
  for a fan-out, one silent miss beats a double-send). All three are in `tech-debt.md` with triggers.
- **Deliberately NOT shipped:** the `application_submitted` NotificationType (chunk 4 owns it — the
  allowlist is not a dumping ground, and the arc deploys as one, so no production gap exists where
  agencies miss applications) and the dashboard jobs teaser (chunk 5 polish; the slot is documented).
- **In-scope hardening:** `creator-routes-guard.spec.ts`, the sibling the agency shell has had since
  Sprint 6. The `layout: 'creator'` guard invariant stops being unpinned.
- **One gap caught by writing the docs:** the flag worked but was never added to
  `AdminFeatureFlagController::FLAGS`, so the kill switch on the platform's first mail fan-out was
  reachable only from tinker. Fixed with a test that an admin can arm **and disarm** it over HTTP.
  Named because everything else about the flag — default, service check, break-revert, enable
  ritual — was green while the operator control did not exist.
- **Gates:** api `pest` 2234 passed / 1 skipped (8045 assertions), PHPStan level max 0 errors, Pint
  clean; `apps/main` 139 files / 1278 tests, `apps/admin` 53 / 449, `api-client` 9 / 204; all three
  typechecks clean; lint 0 errors (the 2 pre-existing `v-html` warnings); i18n locale parity and the
  notifications parity hand-list green at 15 live types; **full Playwright 24/24 in 3.9m** including
  the new browse-detail-apply leg, with the dev stack down for the run and health-checked after.
  Six break-reverts run and restored (approved leg, roster leg, brand-hard-blacklist leg, flag-OFF
  no-op, `listed_at` non-consultation, and both halves of the architecture spec).
- **Ref:** [`docs/reviews/jobs-board-c3-review.md`](jobs-board-c3-review.md) — **Closed — approved**;
  [`jobs-board-c3-plan.md`](jobs-board-c3-plan.md) and
  [`jobs-board-c3-inventory.md`](jobs-board-c3-inventory.md) for the loop that produced it.

### AH-055 · Brand detail page stops showing the two fields AH-053 made unsettable

- **Status:** Landed
- **Commits:** `fix(brands): drop the unsettable default currency/language rows from brand detail (AH-055)`;
  docs commit (this entry). Pure-UI, so it takes the **AH-007 pattern** — build it, log it, done — not
  the full loop.
- **Date:** 2026-07-27
- **Why:** Found by Pedram in eyes-on, minutes after the AH-053/AH-054 push. AH-053's **D8** removed the
  `default_currency` / `default_language` selects from the brand **form** but left the brand **detail
  page** rendering both rows, so a freshly-created brand displayed "Default currency: EUR" and "Default
  language: en" — values the user could no longer set and had never chosen.
- **What:** Removed the two `v-list-item`s from `BrandDetailPage.vue`, and with them the now-orphaned
  `app.brands.fields.defaultCurrency` / `defaultLanguage` keys across all 24 locales (2 leaves per
  locale, 48 total). **`app.settings.fields.*` is untouched** — the agency-level pair is a different
  thing and is genuinely settable on the Settings page.
- **What is deliberately NOT touched:** the columns, their defaults, their validation, the
  `BrandResource` emission and the `BrandAttributes` type all stay exactly as D8 left them. An API
  client can still send both fields and still reads them back; this narrows the **UI**, not the
  contract. The two D8 contract-preservation tests in `BrandFloorGateTest` continue to pin that.
- **Decision — why the AH-032 precedent was NOT followed:** AH-032 removed `objective` from the
  campaign form and deliberately **kept** its Overview-tab row, which is the obvious precedent for
  "removed from the form, still shown on the read surface". It does not transfer. `objective`
  describes the campaign and is consumed; the brand-level defaults are **inert** — a grep across
  `apps/api/app` finds `default_currency` / `default_language` on the brand only in `Brand.php`
  (attribute defaults + fillable), `BrandFactory`, the two form requests and `BrandResource`. No
  pricing, campaign, mail or export path reads either one. So the row displayed a value that was
  unsettable, unchosen, identical (`EUR` / `en`) on every new brand, and acted on by nothing.
- **Known cost, accepted:** a brand created before AH-053 may carry a currency its owner genuinely
  picked, and that value is now hidden. Since nothing reads it and it was already unsettable from the
  UI, the cost is display-only. The data is still there and still on the wire.
- **Follow-up left open:** whether the brand-level columns should be **deprecated outright** (dropped
  from the resource, the requests, and eventually the schema) is a real question and a bigger one — it
  is an API-contract plus schema change and would need the full loop. Not attempted here.
- **Gates:** `apps/main` 135 files / 1243 tests, typecheck clean, lint 0 errors (the 2 pre-existing
  `v-html` warnings), i18n locale parity green — the removal is symmetric across all 24 bundles, so the
  keyset stays in lockstep. No Playwright re-run: no spec references `brand-detail-currency` or
  `brand-detail-language`, and no backend surface moved.

### AH-054 · Jobs Board chunk 2 — campaign listing fields, the two gates, and the read-time scope

- **Status:** Landed
- **Commits:** `b7ea3e1` — `feat(campaigns): jobs-board listing fields, gates and Settings toggle (AH-054)`; docs
  commit (this entry) — `docs(jobs-board): AH-053/AH-054 review + change-log entries + tech-debt`.
  Built in one pass with **AH-053** because the two halves share the floor-predicate shape and one
  i18n gate; they are separate commits because each half is independently green.
- **Date:** 2026-07-27
- **Why:** The Jobs Board (chunk 3) needs campaigns to carry the copy a creator reads — duration,
  fee, languages, regions, an examples link — and needs an agency-controlled switch that decides
  whether a campaign appears at all. Neither existed.
- **What:** Six additive columns on `campaigns` (`listed_on_jobs_board` boolean default `false`;
  `listing_duration`/`listing_fee` varchar 120; `listing_languages`/`listing_regions` jsonb;
  `listing_examples_url` varchar 2048), the two gates below, a Settings-tab toggle that mirrors both
  gates client-side, and `Campaign::scopeListedOnJobsBoard()` — the read-time predicate chunk 3 will
  consume, shipped now so that chunk binds to a tested contract rather than a promise.
- **Decisions:** **D3 (completeness) is a resulting-state rule** — if the campaign will be listed
  after this write, every floor field must be filled, whether the payload flips the switch or the
  campaign was already listed. This is what makes "refuses to gut a live listing" possible.
  **D5 (terminal status) is a transition rule** — only `false → true` is refused for a `completed` or
  `cancelled` campaign. The asymmetry is deliberate and is the whole of ruling **A1**: a campaign that
  was listed when it ended keeps an inert `true` and stays editable, because auto-clearing it would
  put the write path and the read scope in charge of the same fact, and the two would drift. The read
  scope alone decides visibility. **D4:** create accepts the five content fields and ignores the
  switch entirely, so nothing is ever listed by accident. **Q1 = A + C cap:** region/language values
  are uppercase-normalised, `size:2`, distinct and bounded (60 / 24) rather than validated against a
  registry — registry deferred to tech-debt. **Q4:** Settings-only; no Overview rows until the board
  defines what it reads. **Q6:** the flip rides `campaign.updated` rather than earning a new verb.
- **Root cause of the read-pass catch (F2):** `CampaignController::store()` writes through an explicit
  whitelist, not `$fillable`. Fields added to the model only would have validated, returned 201 and
  silently never persisted. Every create test in this chunk asserts the **persisted** value.
- **Touched:** migration `2026_07_27_100000_add_jobs_board_listing_to_campaigns`;
  `Campaigns/Models/Campaign.php`; `Campaigns/Http/Requests/Concerns/ValidatesJobsBoardListing.php`
  (new, the single source of the floor + rules); `Create/UpdateCampaignRequest.php`;
  `CampaignController.php` (whitelist + audit snapshot); `CampaignResource.php`; `CampaignFactory.php`
  (`jobReady()` / `listed()` states); `packages/api-client/src/types/campaign.ts`;
  `apps/main/src/modules/campaigns/listingFloor.ts` (new FE mirror);
  `campaigns/components/CampaignForm.vue`; `campaigns/pages/CampaignDetailPage.vue`; en + 23 locales.
- **Pin:** `CampaignJobsBoardListingTest` (31 tests / 191 assertions) — the D3 gate naming every
  missing field at once, the gut-a-live-listing refusal, the D5 transition block and its complete
  positive partition, the A1 case (`status → terminal leaves the flag untouched`), the F3 audit
  assertion, preserve-by-omission, and the **disjoint** scope negative set plus a pin that
  `LISTABLE_STATUSES` is exactly the complement of the terminal statuses. FE: a jobs-board-toggle
  block in `CampaignDetailPage.spec.ts` and a source-scan parity spec holding the FE floor to the
  backend constant.
- **Break-revert:** neutering the D3 loop reds 4 tests (BR-1 in the review file, verbatim).
- **§5.40:** **LOW** — the migration is purely additive with an honest inverse; no existing row is
  read or rewritten.
- **Ref:** [`jobs-board-brand-amends-review.md`](jobs-board-brand-amends-review.md)

### AH-053 · Jobs Board chunk 1 — brand completeness floor, logo pipeline, and form relabel

- **Status:** Landed
- **Commits:** `2568a96` — `feat(brands): completeness floor, logo pipeline and form relabel (AH-053)`;
  docs commit shared with AH-054 above.
- **Date:** 2026-07-27
- **Why:** A creator browsing the Jobs Board sees the brand before the job. Brands could be created
  with a name alone — no description, no industry, no site, no logo — so the board would have shipped
  rows that say nothing. The form also asked for the wrong thing: `description` was a generic blurb
  where the board needs the monthly deliverables.
- **What:** (1) A six-field completeness floor (`name`, `slug`, `description`, `industry`,
  `website_url`, `logo_path`) required at create (logo excepted — see below) and enforced on every
  subsequent edit. (2) A brand-logo upload/delete pipeline on the avatar pattern. (3) `description`
  relabelled "Monthly deliverables" with a shape-naming hint, and the `default_currency` /
  `default_language` selects removed from the form. (4) `brands:audit-floor`, a read-only command
  reporting the platform-wide blocked population before the deploy.
- **Decisions:** **A2 — the floor predicate takes MERGED state**, payload value where the payload
  supplies one and stored value otherwise. Full-payload-required was rejected on the **AH-032**
  evidence: forcing clients to echo fields they cannot see is precisely the mechanic that produced
  that brief wipe-bug. `Brand::floorMissingFields()` is the single source, consumed by the request,
  the command and — under a source-scan parity spec — the FE mirror; `isFilled()` treats whitespace
  as empty and the mirror agrees. **Restore is explicitly outside the gate** and pinned there rather
  than left as a routing accident (F6). **F7 — create is honestly non-atomic:** the logo needs a row
  to attach to, so `logo_path` is the one floor field not required at create; the SPA does
  `POST /brands` then `POST …/logo`, names the failure if the second write fails, and the edit gate
  is the backstop. **Q5 — replace does not delete** (avatar precedent), which reduces the chunk's
  destructive surface to the single explicit remove and keeps its over-reach negative meaningful.
  **Q2 = B —** `brand_logo_max_bytes` joins a registry that `UploadLimitChecker::requiredBytes()`
  now maxes over, so the `/health` assertion stays honest as upload surfaces multiply. **D8 is a
  form change only:** both removed selects keep their columns, defaults, validation and API
  emission, and two tests pin that the contract did not narrow.
- **Root cause of the read-pass catch (F1):** `BrandFactory` used `fake()->optional()` for exactly
  the fields the floor now requires, so introducing the gate would have produced a randomly-red suite
  — the failure mode whose usual "fix" is weakening the gate. The factory is now deterministic and
  floor-complete by default, with explicit `incomplete()` and `missingFloorField()` states.
- **Touched:** `Brands/Models/Brand.php` (`FLOOR_FIELDS`, `floorMissingFields()`, `isFilled()`);
  `Create/UpdateBrandRequest.php`; `Brands/Services/BrandLogoUploadService.php` (new);
  `Brands/Http/Controllers/BrandLogoController.php` (new); `Brands/Routes/api.php`;
  `BrandResource.php` (`logo_url`); `BrandFactory.php`; `Console/Commands/AuditBrandFloor.php` (new);
  `config/uploads.php`; `Core/Health/UploadLimitChecker.php`; `docs/security/tenancy.md`;
  `packages/api-client/src/types/agency.ts`; `apps/main/src/modules/brands/brandFloor.ts` (new),
  `api/brands.api.ts`, `components/BrandForm.vue`, `pages/Brand{Create,Edit,Detail,List}Page.vue`;
  en + 23 locales; `playwright/fixtures/brand-logo.png` (new) and `specs/brands.spec.ts`.
- **Pin:** `BrandFloorGateTest` (both directions — block an incomplete edit naming every missing
  field, and refuse to _empty_ a floor field on a complete brand — plus read/list/campaign-carry/
  archive/restore all confirmed ungated, and the two D8 contract-preservation cases);
  `BrandLogoUploadTest` (scoped path, replace-without-delete, delete over-reach negative, content-
  sniffed script refusal at both request and service layer, GIF refusal, size cap, EXIF stripping,
  cross-tenant 404 on all three verbs, role posture, signed-URL emission, audit, and the D6
  interaction in both directions); `AuditBrandFloorCommandTest` (output shape, cross-agency and
  soft-deleted inclusion, and a read-only assertion); `BrandForm.spec.ts` + `brand-floor-parity.spec.ts`.
- **Break-revert:** BR-2 (neuter the predicate) reds 8; BR-3 (make the predicate ignore the payload)
  reds exactly the 5 merged-state cases while the blocking cases stay green — the discriminating
  break that shows A2 is enforced, not incidentally satisfied; BR-4 (pull restore inside the gate)
  reds 1. Verbatim in the review file.
- **⚠ Deploy note:** this is a **behaviour change for existing data**, though not a data change.
  Agencies holding pre-floor brands will meet a 422 on their next brand edit, surfaced inline by the
  form rather than discovered through the API. Run `brands:audit-floor` **before** the deploy — it is
  a pure read and reports the exact blocked population, per field and per lifecycle state.
- **§5.40:** **LOW** — no migration, no backfill, no mutation. D6 is a new refusal, not a new write.
- **Ref:** [`jobs-board-brand-amends-review.md`](jobs-board-brand-amends-review.md)

### AH-052 · Canonical 403 envelope — every `authorize()` denial now speaks the platform's error contract

- **Status:** Landed — **pushed 2026-07-26** (`origin/main` at `d1dc3d2`)
- **Commits:** `dd65868` — `fix(api,main): return a canonical 403 envelope and explain closed threads`.
  Shared commit: its closed-thread and notification-registry halves belong to AH-051 and are recorded
  in that review's post-close addendum; this entry owns the 403 contract change.
- **Date:** 2026-07-26
- **Why:** Surfaced by AH-051 eyes-on — a creator disconnected from an agency opened the chat and got
  a 403 the SPA rendered as **"Unrecognized error response."** The refusal was correct; the platform
  simply could not say so. The blast radius was never AH-051's: **every** 403 in the product behaved
  this way.
- **What:** 403s now return the canonical JSON:API error envelope with code `auth.forbidden`, the same
  shape `ValidationExceptionRenderer` has always produced for 422. A new `ForbiddenExceptionRenderer`
  is registered in `bootstrap/app.php` against `HttpExceptionInterface` filtered to
  `HTTP_FORBIDDEN` — deliberately the interface, not `AuthorizationException`, because Laravel has
  already converted the latter into an `AccessDeniedHttpException` by the time render callbacks run.
  The exception's own message is used as the envelope title when it carries one, falling back to a
  canonical sentence when the gate denied silently. This reaches all **82** `authorize()` call sites
  plus every `abort(403)`.
- **Root cause:** a renderer existed for 422 and never for 403, and **no test anywhere asserted a 403
  body** — the suite asserted status codes only, so a 403 could return any shape at all and stay
  green. That is why a client-visible contract gap survived to eyes-on.
- **Touched:** `apps/api/app/Core/Errors/ForbiddenExceptionRenderer.php` (new),
  `apps/api/bootstrap/app.php`, `apps/api/tests/Unit/Core/Errors/ForbiddenExceptionRendererTest.php`
  (new).
- **Decisions:** Register against `HttpExceptionInterface` + status filter rather than
  `AuthorizationException` (the conversion happens first). Fall back to a canonical sentence rather
  than leaking an empty title. Error titles stay plain literals, matching the established
  `ErrorResponse::single` convention rather than becoming translation keys.
- **Pin:** `ForbiddenExceptionRendererTest` — three cases: a gate denial emits the canonical envelope;
  a message-less exception falls back to the canonical sentence; and the output **is parseable by the
  SPA's `ApiError.fromEnvelope` contract**, which is the case that actually closes the gap. A
  canonical-envelope assertion on a blocked send was also added to `RelationshipMessageApiTest`.
- **⚠ Deploy note:** this is a **client-visible contract change**. Any consumer that pattern-matched
  Laravel's default `{"message": …}` 403 body will now receive the envelope instead. Shipped with the
  AH-051 push (`30116da..d1dc3d2`, 2026-07-26) — **not yet deployed**, so the note still binds at the
  next deploy.
- **Ref:** [`admin-connections-review.md`](admin-connections-review.md) post-close addendum
  (cross-referenced); AH-051 entry below.

### AH-051 · Admin-initiated agency↔creator connections + contact-gate fix + first termination path

- **Status:** Landed — **pushed 2026-07-26** (`origin/main` at `d1dc3d2`; the chunk itself landed at
  `30116da`, the six eyes-on fixes and their docs commit rode the same push)
- **Commits:** `98defa9` — `feat(creators): admin agency-creator connections + roster contact gate + disconnect (AH-051)`; docs commit (this entry) — `docs(creators): AH-051 review + change-log entry + tenancy allowlist + tech-debt` (amended to `30116da`). Rides with Step 0 `c6b6cde` (`fix(identity): allow phone-on-LAN dev access to the admin SPA cookie`).
- **Date:** 2026-07-24
- **Why:** Admins had no way to broker an agency↔creator connection (e.g. an offline
  representation agreement) or to end a relationship as a mediated exit. Separately, the
  AH-005 contact gate was looser than the shipped consent promise: an agency merely holding
  a `pending_request` could see the creator's contact details.
- **What:** Three linked changes. (1) The contact gate TIGHTENS to roster-only —
  `CreatorPolicy::canSeeContactDetails` now requires a non-blacklisted `roster` relation, so
  `pending_request`/`declined`/`prospect`/`ended`/`external` no longer expose contact (a
  read-only `relations:audit-contact-exposure` command reports the pre-deploy blast radius).
  (2) A sixth `RelationshipStatus`, `ended` (severed-after-roster, re-requestable, never
  messageable/contact-visible, excluded from the default roster), reached ONLY via the new
  admin disconnect — the platform's first relation-termination path, which flips `roster →
ended`, deletes the pair's pool memberships, and audits with a mandatory reason, all in one
  transaction (campaign assignments deliberately survive). (3) Admin Creator-detail doors:
  Door 1 (send-request, re-drives the agency flow), Door 2 (direct-connect, mandatory reason
  - immediate creator notification), and a per-relation Disconnect — one mode-switched
    `POST …/connections` + a `…/disconnect` route, all `runAs`-scoped. Accept was also
    re-gated (approved + not hard-blacklisted). New audit verbs + notification types + two
    localized mailables.
- **Touched:** `apps/api` (RelationshipStatus enum, CreatorPolicy contact gate,
  Creator/Agency connection controllers + re-gates, admin connection controller + two form
  requests, AuditAction/NotificationType enums, two mailables + views, count command,
  `lang/**/creators.php` ×24, tenancy.md §4), `packages/api-client` (`ended` union +
  `deriveConnectionState` + spec), `apps/main` discover (chip + derive, `app.json` ×24,
  specs), `apps/admin` creators module (CreatorDetailPage connections section + two dialogs
  - api + `creators.json` ×24 + specs).
- **Decisions:** No `ended` migration (plain varchar, no CHECK — enum + tripwire are the doc).
  `is_discoverable` bypassed for admin (browsing preference, not eligibility); `approved`
  binds both doors. Single `POST …/connections` with `mode`; 422 codes mode-distinct. One
  direction-agnostic `RelationDisconnected` type. `ended` derives to a truthful "Previously
  connected" (never silently to `none`). Pool-posture reversal recorded: blacklist =
  warn-don't-remove vs disconnect = remove (coherent together). No D-8 marker column; D-10
  `runAs` per §5.1. Creator-/agency-side disconnect deferred (tech-debt).
- **Eyes-on fixes (post-close):** Pedram drove the shipped feature by hand after close and found
  six defects; all six are fixed, pinned, and held with this chunk's push. `4af63b2` (admin dialog:
  raw 422 code shown as copy, agency ULID replacing the name in the picker, no upfront `approved`
  gate), `046d26c` (the roster "All" chip promised a total the backend never returns — renamed
  "Active", `ended` chip added), `530d7d8` + `bdc957b` (admin-connected mail body trimmed: the
  outside-agreement rationale and the mechanism narration both removed, ×24), `dd65868` (canonical
  403 envelope — see **AH-052** — plus the closed-thread composer state and the two AH-051
  notification types registered in the FE `LIVE_TYPES`), `d381a77` (an `ended` relation could be
  re-added to a talent pool, undoing D-6's membership deletion). Only the last is a genuine gap in
  D-3's status sweep; the rest are presentation seams the suite never asserted, or copy judgments.
  Per-fix bug/root-cause/pin, the risk answers (**zero diffs on all three break-revert subjects**),
  three fresh break-reverts on the new guards, and two accept-as-untestable notes are in the
  review's **Post-close addendum**. Found by Pedram in eyes-on, fixed by Cursor.
- **Ref:** kickoff "Admin-initiated connections + contact-gate fix + first termination path";
  review file [`admin-connections-review.md`](admin-connections-review.md) (incl. the post-close
  addendum); commit-pair (this entry's landing commit) + the six fix commits above.

### AH-050 · "Who appears in your content?" — optional companion multi-select on the creator profile

- **Status:** Landed (push HELD)
- **Commits:** `84521e7` — `feat(creators): "Who appears in your content?" companion multi-select (AH-050)`; docs commit (this commit) — `docs(creators): content_companions — AH-050 entry, review, tech-debt extension`.
- **Date:** 2026-07-19
- **Why:** Agencies casting campaign briefs need to know who regularly appears in a
  creator's content (partner, kids, pets, roommates…) — e.g. a dog-food brand wants
  creators whose content features dogs. Today that signal doesn't exist anywhere on the
  profile.
- **What:** One additive-nullable `jsonb` column `creators.content_companions` holding
  a subset of a fixed 11-key registry (partner, baby_toddler 0–3, young_kids 4–12,
  teens 13–17, adult_children, parents_grandparents, extended_family_friends, pets_dogs,
  pets_cats, pets_other, roommates). Self-declared, optional, empty (null OR `[]`) =
  undisclosed; **completeness-inert**; **admin read-only**; same visibility class as
  accent (AH-022): creator-self, discover (card payload + detail render), roster
  (list + detail), admin detail. Wizard Step 2 gains a chip group (categories pattern
  minus select-all, `data-testid="profile-companions-chip-<key>"`); display surfaces
  render via the shared `CategoryChips`. Display-only in v1 — no filtering (logged
  follow-up). i18n ×24 in both apps with real MT baselines incl. the flaky 10.
- **Touched:** migration `2026_07_19_100000`; `Creator` model; `UpdateProfileRequest`
  (SOT const `CONTENT_COMPANION_KEYS` + rules); `CreatorResource`,
  `CreatorDiscoveryResource`, `CreatorPublicProfileResource`,
  `AgencyCreatorDetailResource`, roster `toRow` + both column projections;
  `ProfileBasicsForm.vue`; discover-profile + roster-detail + admin-detail pages;
  api-client types (6 declarations); Playwright happy-path; 48 locale files; 14 new
  backend test cases (`ContentCompanionsTest`) + extended keyset/value/parity tests +
  9 new frontend spec cases.
- **Decisions:** D1 naming `content_companions` (casting frame — "household" rejected);
  D2/Q2 FE-registry parity inherited as debt (AH-019 tech-debt entry extended to cover
  both fields, one resolution closes both); Q3 admin read-only row via the
  account-details pattern (no `EditFieldRow` change); Q4 card-payload cost accepted and
  recorded; Q5 `[]` persists as-is, null and `[]` both = undisclosed (round-trip
  pinned); D6 inertness + D7 admin-rejection both §5.34-pinned **and** §5.35
  break-revert-proven; GDPR purpose section (no counts/ages, no Art. 9 inference,
  casting-framed helper text, not audited) in the review file.
- **Ref:** review file [`content-companions-review.md`](content-companions-review.md)
  (Production posture §5.40, D1–D11 evidence, both break-reverts verbatim, gate table);
  I1–I7 inventory + kickoff in the session record.

### AH-049 · Master agreement content refresh + version bump to v1.1

- **Status:** Landed (push HELD)
- **Commits:** `77ef15b` — `feat(creators): master agreement content refresh + version bump to v1.1`; docs commit — `docs(creators): master agreement v1.1 — AH-049 entry, tech-debt update, review`.
- **Date:** 2026-07-17
- **Why:** Pedram supplied the finalized Catalyst Creator Terms & Conditions PDF. The
  live click-through agreement predated it (missing the revision-rounds and 30-day
  payment clauses, and the older shorter portfolio wording). Swap the content in and — the
  deliberate part — **bump the version label 1.0 → 1.1**, resolving the AH-029 tech-debt
  _direction_: "1.0" already labelled two historical documents, so from now on every content
  change bumps the label.
- **What:** Full rewrite of the server-rendered `master-agreement.en.md` to the supplied
  PDF — **adds clause 2.4** (revision rounds: up to three per Deliverable unless the Brief
  says otherwise; further amendments are a Change under 2.3 only on written confirmation),
  **adds clause 4.3** (Fees payable within 30 days of Catalyst's wave sign-off), and
  **expands clause 7.3** (restores the portfolio-consent request mechanism). Entity
  (Catalyst Performance Ltd, 13632394) and governing law (England & Wales) unchanged; H1
  and the `## 2. Services` heading kept byte-identical (test contract). `CURRENT_VERSION`
  bumped `1.0 → 1.1`; the in-file `**Version:**` line bumped to `1.1 — Effective 2026-07-17`.
  New acceptances now snapshot the new text + precise `'1.1'`; existing signed contracts are
  untouched (immutable accept-time snapshots) and keep their `'1.0'` string. **No re-consent
  flow** — pre-swap signees are not re-prompted (a conscious product/legal deferral, still
  tied to the open AH-029 counsel thread).
- **Touched:** `apps/api/resources/contracts/master-agreement.en.md`,
  `apps/api/app/Modules/Creators/Services/ContractTermsRenderer.php` (`CURRENT_VERSION`),
  `apps/api/tests/Feature/Modules/Creators/ContractTermsEndpointTest.php`,
  `apps/api/tests/Feature/Modules/Creators/ClickThroughContractRecordTest.php`,
  `apps/main/src/modules/onboarding/components/ClickThroughAccept.vue` (a11y docstring
  example only). Docs: this entry, `tech-debt.md` (version-label entry updated, not deleted),
  `docs/reviews/master-agreement-v1-1-review.md`, `RESUMPTION-TEMPLATE.md`. **No i18n, no
  migration, no schema change** — the SPA label `"Master Creator Agreement v{version}"`
  interpolates the version at runtime, so no locale file changed.
- **Decisions:**
  - **Version mechanism:** the single owner `CURRENT_VERSION` drives the endpoint, the
    snapshot write, and the SPA label. The integer column `contracts.version` stays `1`
    (`(int)'1.1' === 1` — the documented lossy major-version mapping); the **precise string**
    in `signed_signature_data.version` and the **body snapshot** are the authority. Nothing in
    the codebase compares version labels (re-verified post-AH-042/048; the only other
    `.version` references are the unrelated `CampaignDraft` revision counter).
  - **Snapshot immutability (§5.34):** a new Pest case pins that a pre-swap `'1.0'` row is
    byte-untouched (old body, `version === 1`, string `'1.0'`) after the source + constant
    bump, and that re-entering the accept path is an idempotent no-op that never re-snapshots.
  - **Test strengthening + break-revert (§5.35):** I3 proved the pre-swap pins were
    content-blind (they only pinned the unchanged H1 / `2. Services` heading, and version via
    constant). New pins assert the presence of clauses 2.4 / 4.3 and the precise `'1.1'`
    string; verified by deleting clause 2.4 from the markdown → the three content pins red →
    revert → clean `git diff` → re-green.
  - **Transcription fidelity:** faithful to the PDF with four recorded deviations (privacy
    URL substituted for the placeholder; "Deliveable" typo corrected to "Deliverable";
    dash/apostrophe normalization; page-marker stripping) — full list in the review file's
    Transcription-deviations block.
- **Verification:** full backend Pest (1870 passed / 1 skipped / 6604 assertions), Pint
  `--all`, PHPStan, apps/main Vitest (1187 green; 3 concurrent-load timeout flakes re-run
  green in isolation), vue-tsc, ESLint (0 errors), api-client (196), 23-locale parity, and the
  Playwright `creator-wizard-happy-path` contract-step run (green — new longer content
  traverses the AH-028 scroll gate).
- **Ref:** `77ef15b` (feat) + docs commit (this entry); review file
  `docs/reviews/master-agreement-v1-1-review.md`. Engineering review only — whether holding
  existing signees on their old snapshots without re-consent is legally sound remains a
  question for counsel (AH-029 thread), **not** blessed here.

### AH-048 · Incomplete-creator email nudge (scheduled, flag-gated, once-only)

- **Status:** Landed (push HELD)
- **Commits:** `3d37f21` — `feat(creators): incomplete-creator email nudge (flag-gated, once-only, capped)`; `1870d1f` — `docs(creators): incomplete-creator nudge — flags, runbook, tech-debt, review`
- **Date:** 2026-07-16
- **Why:** Self-serve creators who sign up but stall in `application_status = incomplete` get no follow-up — they simply never finish. A one-time nudge recovers a slice of that abandoned-onboarding cohort, split by whether the blocker is an unconfirmed email (verify link) or an unfinished profile (finish-profile deep link).
- **What:** A daily `creators:send-incomplete-nudges` command (registered `->daily()` in `withSchedule()`) delegating to `IncompleteCreatorNudgeService`. It emails self-serve creators sitting incomplete for **48h+**, in two variants — verify-email (`email_verified_at IS NULL`, fresh `EmailVerificationToken` + `/auth/verify-email?token=`) and finish-profile (`/onboarding`). Gated by a new default-OFF Pennant flag toggled from the admin Feature-flags page. Once-only via a new nullable `creators.incomplete_nudge_sent_at` stamp; per-run cap (default 50, oldest-first) via `--limit`; `--dry-run` previews counts and mutates nothing. Strings localized across all 24 `lang/*/creators.php`; full loop → detailed review file.
- **Touched:** `Modules/Creators` (Features/`IncompleteCreatorNudgeEnabled`, Enums/`IncompleteCreatorNudgeVariant`, Services/`IncompleteCreatorNudgeEligibility` + `IncompleteCreatorNudgeService`, Mail/`IncompleteCreatorNudgeMail` + 2 Blade views, Support/`IncompleteNudgeReport`, `CreatorsServiceProvider`, `Creator` model), `Console/Commands/SendIncompleteCreatorNudges`, `bootstrap/app.php`, `Modules/Admin` `AdminFeatureFlagController`, migration `2026_07_16_100000_*`, `lang/*/creators.php` ×24, tests (eligibility, mail render, command); docs (`feature-flags.md`, `runbooks/production-queue-worker.md` §7, `tech-debt.md`, `RESUMPTION-TEMPLATE.md`, review file). **No frontend/`packages` changes** — the admin flags page lists the flag from the API.
- **Decisions:**
  - **Flag default-OFF + admin registry (D1).** New `incomplete_creator_nudge_enabled` (default-OFF Closure, the `KycVerificationEnabled` shape) added to `AdminFeatureFlagController::FLAGS` (English label, the house non-i18n admin pattern) — the flip inherits the reason-required `feature_flag.toggled` audit flow. `CreatorResource.wizard.flags` (the exact-3-key pin) untouched.
  - **Self-serve origin only, Q1 exclusion + rejected alternatives (D2).** Excludes any creator with an `agency_creator_relations` row bearing `invitation_sent_at IS NOT NULL` (bulk-invite / connection-request) — a conservative over-exclusion so nobody whose correct next step is _accept-invite_ gets a verify-email link. **Rejected:** `users.password` presence (every row is hashed regardless of origin) and `users.last_name` nullability (nullable + added late in AH-023, never an origin signal) — recorded so the predicate is not "simplified" into something broken. Plus a plan-pause extension: `is_suspended = false` (a suspended user hits the login wall).
  - **D3 lossiness (anchor = `creators.created_at`).** The 48h floor is measured off `creators.created_at`, not a `became_incomplete_at` column (not built — v2 territory). A reopened rejected→incomplete row becomes eligible on reopen if never nudged — accepted (genuinely old + incomplete), and the once-only stamp caps it at one.
  - **50-cap, oldest-first (production-safety).** `--limit=N` (default 50), oldest-first (`created_at, id`), a per-run total across both variants — a backlog drains deterministically over successive days; only the capped set is stamped (no over-stamping); `--limit=0`/non-numeric fails loudly.
  - **Token safety = the resend path.** The verify variant mints via `EmailVerificationToken::mint()` — the same call the resend endpoint uses (`EmailVerificationService::resend()` → `SignUpService::sendVerificationMail()`). `mint()` is a pure HMAC with no store, so a fresh mint neither invalidates an older outstanding token nor risks a uniqueness/collision; single-use stays carried by `users.email_verified_at`.
  - **GDPR Contract framing (D5).** Transactional, not marketing — lawful basis is Contract (completing a registration the creator started), so the copy is service-framed with zero promotional language and **no unsubscribe link**; the `onboarding-nudge` envelope tag keeps it out of any future marketing stream.
  - **Deploy obligations (D8).** Production now needs the `schedule:run` cron/timer (documented for the first time in `production-queue-worker.md` §7) or nothing fires; first-enable ritual is dry-run → read counts → flip the flag in admin. Migration is additive-nullable only; flag ships OFF; the command's only write is the `incomplete_nudge_sent_at` stamp.
- **Ref:** [`docs/reviews/incomplete-creator-nudge-review.md`](incomplete-creator-nudge-review.md); kickoff decisions D1–D8 + Q1–Q3 + the production-safety addendum.

---

### AH-047 · Creator sees a green "post verified" closure banner

- **Status:** Landed (push HELD)
- **Commit:** `aca03b0` — `feat(creators): show verified-by-agency success banner`
- **Date:** 2026-07-13
- **Why:** After a post was verified — automatically (`live_verified`) or by the agency's manual
  override (`manually_verified`) — the creator detail page showed nothing new: the status chip
  changed but no surface said "you're done." (Pedram's report: the page just goes quiet.)
- **What:** `CreatorAssignmentDetailPage.vue` gains an `isVerified` computed
  (`live_verified || manually_verified`) and a green success `v-alert`
  (`assignment-verified-notice`) in the state-dependent action slot: "Your post has been verified
  by the agency. This assignment is complete — no further action is needed." New i18n key
  `creator.ui.assignments.detail.verifiedNotice` across all 24 `creator.json` locales.
- **Touched:** `CreatorAssignmentDetailPage.vue` (+spec: manual + live variants),
  `locales/*/creator.json` ×24.
- **Decisions:**
  - **One message for both verified states** — the creator doesn't need to know whether a human
    or the checker confirmed it; "verified by the agency" covers both truthfully.
  - The Posted-content history line keeps the row's factual last automatic result (e.g. "Not
    found" under a manual override) — the banner above carries the assignment-level truth.
  - **Ruling (with AH-046):** the initial pass left this key in English for the flaky 10, matching
    surrounding placeholder strings — rejected on review; no English fallback for new
    creator-facing copy, all 24 locales (including the flaky 10 — `bg, el, et, fi, ga, hu, lt, lv,
mt, ro`) ship a real MT baseline at merge time, same standard as AH-028.
- **Ref:** report "when we manually verified, or automatically verified, we should show a message
  in green … so the creator knows that the process is done".

---

### AH-046 · Failed-verification copy tells the creator the agency can resolve it too

- **Status:** Landed (push HELD)
- **Commit:** `48f7afc` — `fix(creators): clarify failed-verification copy mentions manual agency review`
- **Date:** 2026-07-13
- **Why:** The creator-side "We couldn't verify your post" alert only said "check the link and
  resubmit" — implying the creator MUST act even when their link is fine (a false verification
  failure). With AH-045 giving the agency the Resolve action everywhere, the copy should say so.
- **What:** Rewrote `creator.ui.assignments.detail.resubmitInPlace.intro` to a two-branch
  instruction: link wrong → correct and resubmit below; link already correct → no action needed,
  the agency will review and can verify manually. Translated across all **24 SPA `creator.json`**
  locales — also fixed the pre-existing garbled Czech/Slovenian-mix text in this exact line for
  `hr`, `sk`, `sl`, and a stray mixed-language word in `bg` (incidental to the rewrite; the
  broader corruption in those three files beyond this one line is unrelated and tracked in
  `tech-debt.md`).
- **Touched:** `locales/*/creator.json` ×24. Copy-only — no logic or key changes.
- **Decisions:**
  - Keep the same single `intro` key (no new keys); parity gate stays green.
  - **Flaky-10 MT baseline (ruling, applies retroactively to this key too):** the initial pass
    left the 10 flaky locales (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) on English copy, reasoning
    that it matched already-English surrounding strings in those files. **Rejected on review** —
    "match the surrounding English" just inherits pre-existing debt rather than fixing it. All 10
    now carry a real MT-baseline translation of this key, same standard as AH-028 and AH-047.
- **Ref:** report "we should tell the creator … if the link is correct wait for the agency to
  verify the link manually".

---

### AH-045 · Resolve action surfaced on the board card drawer + Drafts tab rows

- **Status:** Landed (push HELD)
- **Commit:** `55fc474` — `feat(campaigns): surface manual Resolve action for failed verifications`
- **Date:** 2026-07-13
- **Why:** After a failed post verification, the **Resolve** action lived only on the Creators-tab
  row — the agency operator looking at the board card drawer's Detail timeline (the "Live verified —"
  row) or at the Drafts tab had no way to act (Pedram's report + explicit request for both spots).
- **What:**
  - **Board card drawer** (`BoardCardDrawer.vue`): a `Resolve` button now sits inline on the
    **Live verified** timeline row when the assignment is `posted` and the LATEST posted-content
    row's verification is `not_found`/`mismatch` (the D-7 detail already carried the data —
    `posted_content` is newest-first). The drawer emits a `CampaignAssignmentResource` stub;
    `BoardView` closes the card drawer and bubbles it to `CampaignDetailPage`, which opens the
    existing page-level `ResolveVerificationDrawer`. New `canResolve` prop threads the `review`
    ability down (`:can-resolve="canReview"`).
  - **Drafts tab** (`DraftsTab.vue`): a warning-colored `Resolve` button renders next to `Review`
    on rows meeting the same gate, emitting `open-resolve` with the same assignment stub the Review
    flow uses. `onResolved` now also reloads the Drafts tab (mirrors `onReviewed`).
  - **Backend** (`CampaignDraftListItemResource` + `CampaignDraftController`): the draft-list
    assignment stub now emits `verification_status` (the latest posted row's status, D-7 mirror),
    with `assignment.latestPostedContent` eager-loaded. api-client type extended (optional field —
    back-compat).
- **Touched:** `BoardCardDrawer.vue` (+spec), `BoardView.vue`, `DraftsTab.vue` (+spec),
  `CampaignDetailPage.vue`, `packages/api-client` `campaign.ts`, `CampaignDraftListItemResource`,
  `CampaignDraftController`, `CampaignDraftListTest` (latest-post status + null cases).
- **Decisions:**
  - **One drawer, three doors, zero new backend surface:** all three UI entry points (Creators
    tab, Drafts tab, board drawer) open the SAME pre-existing page-level `ResolveVerificationDrawer`
    with the same `CampaignAssignmentResource` stub shape, calling the same pre-existing
    `manuallyVerify` / `requestResubmitFresh` / `requestResubmitInPlace` endpoints and the same
    authorization gate (`canReview`) — no new backend action, route, or authorization path; this
    chunk is UI wiring only (confirmed: `ResolveVerificationDrawer.vue` has zero diff in this batch).
  - **`verification_status` is additive and back-compat:** a new, optional field on the existing
    `CampaignDraftListItemResource` assignment stub (agency-only resource — the route sits under
    `auth:web + tenancy.agency`), null when `latestPostedContent` isn't eager-loaded; the
    `packages/api-client` type change is optional (`?:`), so no existing consumer breaks.
  - **Same gate everywhere:** `canReview && status === 'posted' && verification ∈ {not_found,
mismatch}` — copied verbatim from the Creators-tab `canResolveVerification`.
  - No new i18n keys — reuses `app.campaigns.resolution.action` ("Resolve").
- **Ref:** report "no place to verify it or manually verify it" → request "add the resolve button
  on the card details … and on the draft tab next to review".

---

### AH-044 · Draft submit — a link alone is a valid draft (media no longer mandatory)

- **Status:** Landed (push HELD)
- **Commit:** `ebf736f` — `feat(creators): allow link-only draft submissions`
- **Date:** 2026-07-13
- **Why:** A creator added an external link to a draft but the **Submit/Resubmit** button stayed dead
  with no explanation (Pedram's report). The draft composer required at least one uploaded **media**
  file — a link alone couldn't carry a draft — and nothing told the creator why the button was disabled.
- **What:**
  - **Backend** (`CreatorAssignmentDraftController::submitDraft`): `media` relaxed from
    `required|array|min:1` to `nullable|array`; a new **"at least one of {media, links}"** invariant is
    enforced after validation, returning `422 draft.empty` when both are empty. Empty media now persists
    as `null` (mirrors the `links` normalisation).
  - **Frontend** (`CreatorAssignmentDetailPage.vue`): the submit gate is now
    `(readyMedia > 0 || draftLinks > 0) && !mediaUploading`, so a link alone enables submit. Added an
    `emptyHint` caption next to the button explaining the requirement while the draft is empty.
  - New i18n key `creator.ui.assignments.detail.draft.emptyHint` across all **24 SPA `creator.json`**
    locales (full parity gate).
- **Touched:** `CreatorAssignmentDraftController`; `CreatorAssignmentDetailPage.vue`;
  `locales/*/creator.json` ×24; `CreatorAssignmentDraftTest` (link-only success + empty-draft 422),
  `CreatorAssignmentDetailPage.spec.ts` (gate + hint).
- **Decisions:**
  - **Media OR links** (not media-mandatory): a draft hosted entirely on an external link is a
    first-class draft. The only hard rule — "at least one of {media, links}" — is enforced once,
    after validation, in `submitDraft()`; submit and resubmit are the **same endpoint/method**
    (producing / contracted / revision_requested all route through it), so the rule applies
    identically to both.
  - **`media: null` is safe downstream:** the only reader of `media_attachments` outside the model
    is `CampaignDraftResource::mapMedia()`, which already null-coalesces to `[]` before
    serialization — so every consumer (`ReviewDraftDrawer`'s `.media.map(...)`, the board drawer's
    latest-draft row, which doesn't render media at all) sees a plain array, never `null`. The
    creator's own detail page doesn't render past-draft media either — no null-safety change was
    needed anywhere.
  - **Silent-disabled is a bug:** a disabled primary action always states its precondition (the
    `emptyHint`).
- **Ref:** report "added a link but couldn't resubmit, or submit".

---

### AH-043 · Toggle-OFF: the thread system message stops claiming a signed contract

- **Status:** Landed (push HELD)
- **Commit:** `b99ac31` — `fix(messaging): fork system-message copy for contract-less advances`
- **Date:** 2026-07-13
- **Why:** Direct follow-on to AH-042. With the per-campaign contract toggle OFF, the assignment
  **Messages** tab still showed the lifecycle system line _"The contract was signed — production can
  begin."_ (Pedram's report). AH-042 gated the _notification_ surface (Q1) but missed the **in-thread
  system message** — a third contract-announcement surface written by a separate listener. The false
  line also fired on the agency's manual proceed-without-contract path (contract-less since the
  decouple chunk).
- **What:**
  - `WriteSystemMessage` now forks the rendered copy on `contract_id`: a **contract-less**
    `AssignmentContracted` (`contract_id === null`) writes the new key
    `assignment.contracted_without_contract` → _"Production can begin."_; a real contract keeps
    `assignment.contracted` → _"The contract was signed — production can begin."_ Same Q1 discriminator
    (`contract_id === null`), so it covers **both** the requires=false auto-advance and the agency
    manual proceed-without-contract.
  - New i18n key `messaging.system.assignment.contracted_without_contract` added across **all 24 SPA
    locales** and `lang/*/messages.php`, reusing each locale's own "production can begin" clause
    (full 24-locale parity gate applied).
- **Touched:** `WriteSystemMessage` (listener); `lang/*/messages.php` ×24,
  `apps/main/.../locales/*/app.json` ×24; `SystemMessageTest` (split real vs. contract-less),
  `ChatPanel.spec.ts` (truthful no-contract render).
- **Decisions:**
  - **Neutral copy, not suppression:** `contracted` on an OFF campaign genuinely _does_ mean
    production can begin — only the "contract was signed" clause is false. A distinct, truthful key
    preserves the production-start milestone rather than dropping it.
  - **Same invariant as AH-042 Q1:** a contract-less advance never announces a contract, on any
    surface (notification, and now the thread system message + digest render).
  - **This closes a gap the AH-042 review itself missed:** that review's coverage table enumerated
    only the notification listener; `WriteSystemMessage` is a distinct `AssignmentTransitioned`
    consumer with the identical invariant and was never swept. Recorded as a dated,
    clearly-marked **Post-close addendum (AH-043, 2026-07-13)** appended to
    `docs/reviews/contract-toggle-off-flow-review.md`, with the review's original closed text left
    verbatim and unmodified above it.
- **Ref:** report "toggle off … in the messages i can still see the signing contract phase";
  see the Post-close addendum in `docs/reviews/contract-toggle-off-flow-review.md`.

---

### AH-042 · Toggle-OFF campaigns flow without contract involvement

- **Status:** Landed (push HELD)
- **Date:** 2026-07-13
- **Why:** A campaign's "Require a per-campaign contract" toggle (`requires_per_campaign_contract`)
  was set OFF but the assignment pipeline still dead-ended the creator at `accepted` with no step
  forward — identical to the ON behaviour. The decouple chunk had added an _agency_ escape button but
  the creator remained stuck. OFF must flow with **zero** contract involvement; ON stays as-is.
- **What:**
  - The state-machine `contract()` gate now reads `$contract !== null` — a **contract-less advance is
    permitted regardless of the `per_campaign_contract_enabled` flag** (the flag gates the contract
    _feature_, irrelevant when no contract is involved).
  - `CreatorAssignmentController::accept` **auto-advances** `accepted → contracted` (contract-less, one
    outer transaction) when the campaign toggle is OFF, landing the creator straight on the draft form.
  - The creator detail copy ("the agency will send a contract" / signing-disabled) now consults the
    campaign toggle via a new `requires_per_campaign_contract` meta key (belt-and-suspenders).
  - New one-shot idempotent command `campaigns:advance-contractless-accepted` (`--dry-run`,
    accepted-only + requires=false-only) to advance rows stuck before this shipped.
  - **Pre-existing false-fire fixed:** the agency proceed-without-contract path had been announcing
    "the creator accepted the contract" for contracts that never existed — the contract-acceptance
    notification is now gated on `contract_id !== null` everywhere.
- **Touched:** `CampaignAssignmentStateMachine`, `CreatorAssignmentController`,
  `CreatorAssignmentDraftController` (meta), `SendAssignmentNotifications`,
  `AdvanceContractlessAcceptedAssignments` (new command); `campaign.ts` type,
  `CreatorAssignmentDetailPage.vue`; backend + FE + console tests.
- **Decisions:**
  - **D1 (flag vs. toggle):** the toggle is the single source of "does this campaign need a contract";
    the flag is the single source of "is the contract feature operational." The machine permits
    `contract(null)` irrespective of the flag; the flag stays load-bearing for `contract !== null`.
  - **D5 (accept-time snapshot posture):** flipping the toggle ON after acceptance does **not**
    retroactively demand a contract (contracted rows stay contracted); flipping OFF advances stuck rows
    **only** via the D4 command or the agency button, never automatically on campaign edit.
  - **Q2 asymmetry (recorded):** the machine permits `contract(null)` while the agency
    proceed-without-contract _endpoint_ keeps its `flagGate` — the endpoint is part of the contract
    feature's surface (flag territory), the auto-advance is the absence of the feature (toggle
    territory). Manifests only when the flag is manually OFF; the D4 command drives the machine
    directly so remediation is never blocked.
  - **Uniform notification gate (incl. the pre-existing false-fire):** a contract-less advance never
    announces a contract acceptance, regardless of path (auto-advance, backfill, or agency button);
    the agency still learns of the accept itself.
  - **D6 (audit distinguishability):** three contract-less paths carry distinct audit signatures —
    accept-chained (`auto_advanced: true`), backfill (`auto_advanced: true, source: backfill`), agency
    manual (neither key).
  - **D4 post-deploy step:** run `php artisan campaigns:advance-contractless-accepted` once after
    deploy — **joins the AH-026 `creators:recompute-completeness` in the pending-deploy list**
    (see `RESUMPTION-TEMPLATE.md` Part 2). Idempotent; no scheduler.
- **Ref:** kickoff "Toggle-OFF campaigns flow without contract involvement" (investigation I1–I6);
  review `docs/reviews/contract-toggle-off-flow-review.md`.

---

> **AH-033 → AH-041 are one direct-iteration fix batch** (the AH-007 pattern: Pedram
> directs each change interactively, no per-item kickoff; one independent review + one
> close-out at the end). Nine themes, committed as small conventional commits
> (`cc86bb8 … fdbec40`, atop the AH-032 baseline `7051123`). Stop-gate exceptions taken
> mid-batch on Pedram's explicit call are recorded per entry. Close-out Steps 1–2 ran
> the full backend Pest suite, both SPA Vitest suites, and the **entire Playwright E2E
> suite (24/24 green — 22 main + 2 admin)** against all four new migrations. **This batch
> adds three schema migrations + one data backfill to the next deploy** (see the deploy
> note in `RESUMPTION-TEMPLATE.md` Part 2). Push HELD at close-out.

### AH-041 · Reject guard + board wiring (Cancelled / Rejected)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Rejecting a draft is a **terminal** action (the assignment ends, the creator
  cannot resubmit, the thread closes) but the agency got no warning before clicking it;
  and a rejected assignment's card stayed wherever it was — no board column reflected
  "rejected".
- **What:**
  - A confirmation dialog guards the terminal draft-reject action in `ReviewDraftDrawer`
    ("Rejecting is final… use Request changes instead"), with a "Keep reviewing" escape.
  - The default **"Cancelled"** board column is renamed **"Cancelled / Rejected"**, and the
    `assignment.draft_rejected` audit event is wired as a **10th default automation** that
    auto-moves the card to that column.
  - A **data backfill** migration renames existing default-named terminal-failure columns
    and inserts the new automation for boards that lack it.
  - Column name forced onto one line (`text-no-wrap` + `text-truncate`); the
    closed-conversation chat notice restyled from `info` to **`error`** (red).
- **Touched:** `apps/api/app/Modules/Boards/Support/BoardDefaults.php`,
  `apps/api/database/migrations/2026_07_13_110000_backfill_cancelled_rejected_board_column.php`,
  `apps/api/tests/Feature/Modules/Boards/{BoardApiTest,BoardAutomationServiceTest,BoardLazyHealTest,BoardProvisioningServiceTest,OverdueScanTest}.php`,
  `apps/main/src/modules/boards/components/BoardColumn.vue`,
  `apps/main/src/modules/campaigns/components/ReviewDraftDrawer.{vue,spec.ts}`,
  `apps/main/src/modules/messaging/components/ChatPanel.vue`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, `rejectConfirm` block).
- **Decisions:** **reject now has a cross-module side effect** — Campaigns' draft-reject
  fires an audit event that the Boards automation engine consumes (a new Campaigns→Boards
  coupling, recorded here). The backfill **renames only default-named terminal-failure
  columns** (`name = 'Cancelled' AND is_terminal_failure = true`), so an agency that
  renamed the column keeps its name — **test-pinned** in `BoardProvisioningServiceTest`.
  Automation insert is **idempotent on `(board_id, event_key)`**. `down()` is deliberately
  **blunt** in the opposite direction: it **deletes ALL `assignment.draft_rejected`
  automations** (including any later seeded by provisioning — the default is now part of
  provisioning) and **conditionally renames back** any `Cancelled / Rejected` terminal-failure
  column to `Cancelled` (which would also catch a legitimately custom column of that exact
  name — low risk, since that name is now the default). The `is_terminal_*` flags stay
  semantic labels, not gating logic.
- **Ref:** `18d9845` (confirm dialog), `30bdcd8` (rename + automation + backfill),
  `1f16fe8` (one-line column + red notice). **Stop-gate exceptions** (Pedram's explicit
  call): the `rejectConfirm` i18n keys ×24 and the rename + data-backfill migration.

### AH-040 · Draft submissions — external links + chat-style composer

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The draft form asked for **hashtags/mentions** that nothing consumed, the media
  input was a bare `v-file-input`, and creators needed to attach **external links**
  (hosted video, doc, reference) alongside files.
- **What:** Hid the hashtags/mentions inputs on **both** the creator draft form and the
  agency `ReviewDraftDrawer`; replaced the file input with a **chat-style two-icon
  composer** (paperclip = file, link = link dialog, mirroring the messaging composer);
  added **real external-link support** persisted on the draft (`links` jsonb) and rendered
  back on the review side.
- **Touched:** `apps/api/database/migrations/2026_07_13_100000_add_links_to_campaign_drafts.php`,
  `apps/api/app/Modules/Campaigns/Models/CampaignDraft.php`,
  `apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftResource.php`,
  `apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php`,
  `apps/api/tests/Feature/Modules/Creators/CreatorAssignmentDraftTest.php`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentDetailPage.{vue,spec.ts}`,
  `apps/main/src/modules/campaigns/components/ReviewDraftDrawer.{vue,spec.ts}`,
  `packages/api-client/src/types/campaign.ts`.
- **Decisions:** URL validation is a **`url:http,https` scheme allowlist**, **max 10 links
  / 2048-char url / 255-char name**. Links render as **plain anchors with
  `rel="noopener noreferrer"` + `target="_blank"`** (no preview fetch, no unfurl — a link
  is inert text). Hashtags/mentions follow the **AH-032 retained-and-preserved-by-omission
  pattern**: the columns, validation, and Resource emission stay; only the UI is dropped,
  so nothing is lost and re-surfacing them is a pure front-end change.
- **Ref:** `832f9ca` (links backend + migration + resource), `44afe5c` (composer + drop
  hashtags/mentions), `e1ee4b2` (Media label above the icons). **Stop-gate exceptions**
  (Pedram's explicit call, "do both a and b"): the `links` migration + api-client shape +
  validation rules (real link persistence, not just a visual affordance).

### AH-039 · Board card facelift + drawer Detail-tab redesign

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The board card face and the drawer's Detail tab were sparse — no photo, no fee
  context, no deliverables, no progress at a glance.
- **What:**
  - **Card face:** creator avatar, **bold** name (matching the drawer header), deliverable
    chips, fee-per line, and the brand **aurora gradient** as the accent strip (replacing
    the per-column color token).
  - **Drawer Detail tab:** redesigned into an identity header (avatar + name + status +
    campaign · brand), **invite-offer terms** (fee / per / description / attachment),
    deliverable chips, a **five-step progress timeline**, and latest-draft / posted-link
    rows, with locale-aware date-format fixes.
  - Card face is **preserved across a move** (the `move` response re-selects avatar, fee,
    and decline-history so the face doesn't degrade after a drag).
- **Touched:** `apps/api/app/Modules/Boards/Http/Controllers/{BoardController,BoardCardController}.php`,
  `apps/api/app/Modules/Boards/Http/Resources/BoardCardResource.php`,
  `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentReviewController.php`,
  `apps/api/tests/Feature/Modules/Boards/{BoardApiTest,BoardManualMoveTest}.php`,
  `apps/api/tests/Feature/Modules/Campaigns/CampaignAssignmentReviewTest.php`,
  `apps/main/src/modules/boards/components/{BoardCard,BoardCardDrawer}.{vue,spec.ts}`,
  `apps/main/src/modules/boards/components/BoardColumn.vue`,
  `apps/main/tests/unit/architecture/form-error-pattern.spec.ts`,
  `packages/api-client/src/types/{board,campaign}.ts`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, `board.drawer.detail` block).
- **Decisions:** **API-resource-shape stop-gate exception** — `BoardCardResource` now emits
  `avatar_url` + fee fields, and the review `show` emits `fee_per` / `offer_description` /
  `offer_attachment` (signed) / `invited_at`. Signed attachment URL is emission-scoped
  (60-min, AH-004). The aurora strip uses `var(--brand-aurora-gradient)` (an allowed
  surface per the architectural CSS-token lint); the `colorToken` prop was removed.
- **Ref:** `32e21f6` (concept card), `ec1596d` (keep face on move), `0930db1` (drawer
  Detail redesign), `3ebbfb4` (bold name). **Stop-gate exceptions**: API resource shape +
  the `board.drawer.detail` i18n keys ×24 (approved as items 1–5 of the proposal).

### AH-038 · Discover card redesign (Phase A — front-end only)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The discover card was a plain list tile; Pedram wanted a **photo-forward,
  concept-inspired** card (from an uploaded reference), explicitly **Phase A only** —
  pure restyle on existing data.
- **What:** Full-width hero avatar block, name with a connection-state indicator, an
  icon-based meta row (country + language/accent), ≤3 category chips with a **"+N"
  overflow** chip, and a footer row (connection state + view-profile icon). Cards made
  **~30% smaller** via tighter grid breakpoints, a **5:4 landscape** hero to match the
  concept ratio, and **CSS container queries** so text/chips/icons scale with the card
  (the font-to-card ratio holds as the viewport shrinks).
- **Touched:** `apps/main/src/modules/discover/pages/DiscoverPage.vue`.
- **Decisions:** **Phase A = pure front-end** — no backend, resource, gate, or i18n change
  (all data already on the wire). `container-type: inline-size` + `cqi`/`clamp()` units for
  proportional scaling. No stop-gate triggered.
- **Ref:** `7b49e54` (photo-forward restyle), `71800a0` (~30% smaller), `3e96a53` (5:4
  ratio), `fd3630e` (one-line categories + `+N`), `677cd64` (container-query scaling).

### AH-037 · Board card drawer — Campaign messages tab

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Agencies wanted to read and reply to the per-assignment chat **from the board
  card** rather than navigating away to the campaign messaging surface.
- **What:** A **"Messages"** tab added as the **first and default** tab in the board card
  drawer, mounting the existing `ChatPanel` keyed per assignment; a "no conversation" note
  when the card has no assignment data.
- **Touched:** `apps/main/src/modules/boards/components/BoardCardDrawer.{vue,spec.ts}`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, tab label + `none` note).
- **Decisions:** **`ChatPanel` reuses `agencyChatTransport` with ZERO new provisioning**
  — the AH-012 lesson held. The drawer is a **read/reply mount of the existing
  campaign-messaging surface**; it introduces no new thread-creation path and inherits the
  same Sprint-11 campaign-messaging gate. The Messages tab is **independent of the
  detail/movements fetch**, so it renders even if the Detail tab's data errors.
- **Ref:** `79298f8`. **Stop-gate exception**: the Messages-tab i18n keys ×24 (label +
  "no conversation" note).

### AH-036 · Invitation + admin readability fixes

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Three small visibility problems: the admin **"Pending approval"** nav item was
  truncated, the creator invitation **fee + start/end dates** were crammed on one line, and
  the **"View post"** button was near-invisible in dark mode.
- **What:** Widened the admin sidebar `280px → 304px`; put fee and posting window on
  separate lines in the creator invitation list; brightened the View-post button
  (`secondary` → `primary`).
- **Touched:** `apps/admin/src/core/layouts/AdminLayout.vue`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentsPage.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`.
- **Decisions:** Pure styling — no shape, prop, or i18n change. No stop-gate.
- **Ref:** `a4c778b` (sidebar width), `a073b47` (fee/date lines), `3286590` (View-post
  brightness).

### AH-035 · Re-offer after decline (declined → invited)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Re-inviting a **declined** creator silently no-op'd — the invite endpoint's
  idempotency returned the existing declined row with a `200`, so the agency saw a success
  toast but no new invitation and no updated offer ever reached the creator.
- **What:** A new state-machine edge `reofferAfterDecline` (`declined → invited`); the
  invite controller routes a declined existing row through it (other statuses keep the
  idempotent no-op); a muted **"Declined"** history tag surfaces on the Creators tab, the
  board card face, and the drawer; the creator-side **counter UI is removed entirely**.
- **Touched:** `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php`,
  `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php`,
  `apps/api/app/Modules/Campaigns/Http/Resources/CampaignAssignmentResource.php`,
  `apps/api/app/Modules/Campaigns/Models/CampaignAssignment.php`,
  `apps/api/database/migrations/2026_07_12_110000_add_previously_declined_to_campaign_assignments.php`,
  `apps/api/app/Modules/Boards/Http/{Controllers/BoardController.php,Resources/BoardCardResource.php}`,
  `apps/api/tests/Feature/Modules/{Campaigns/CampaignAssignmentStateMachineTest,Campaigns/CampaignAssignmentInviteTest,Boards/BoardApiTest}.php`,
  `apps/main/src/modules/boards/components/{BoardCard,BoardCardDrawer}.{vue,spec.ts}`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.{vue,spec.ts}`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentsPage.{vue,spec.ts}`,
  `packages/api-client/src/types/{campaign,board}.ts`.
- **Decisions:**
  - `declined → invited` **overwrites the full offer** (fee / currency / per / description /
    attachment) + **clears `responded_at`** + **raises `previously_declined`**.
  - **Fail-closed from any non-declined source** (`assertSource([Declined])`) — the batch's
    only state-machine change, so a **break-revert was executed at close-out** (Part A3):
    widening the guard to include `Accepted` turned the fail-closed unit test red
    (`reofferAfterDecline: a non-declined source throws invalid_transition`), then reverted
    to green — proving the guard is load-bearing.
  - **Idempotent no-op preserved** on non-declined existing rows (invited/accepted/etc.
    still return the existing row unchanged).
  - Audit verb **reuses `assignment.re_invited`** (`AssignmentReInvited`) — no new action.
  - The **creator counter UI is removed** while the **counter API remains fail-closed**
    (`invited`-only) — recorded as **API-without-UI tech-debt**.
  - `previously_declined` is **agency-side only** (`CampaignAssignmentResource` +
    `BoardCardResource`), **never creator-visible** (verified, S7).
- **Ref:** `34f5e84` (machine edge + migration), `64222b5` (api-client),
  `5626ddf` (history tag + drop counter), `2d56cbd` (board resource),
  `c9cba2a` (api-client board), `edfc56e` (card + drawer tag). **Stop-gate exception**
  (Option 2, Pedram's explicit call): the `previously_declined` migration + state-machine
  edge + resource shape.

### AH-034 · Invite-offer context — fee-per, description, attachment, roster avatars

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** An invite carried only a bare fee. Agencies wanted to say what the fee is **per**
  (e.g. "per script"), add a free-text **description** of expectations, attach a **file**,
  and see **real creator photos** (not initial avatars) in the invite modal.
- **What:** Added `fee_per` + `offer_description` free-text to the invite payload and the
  assignment; a **presigned-S3 offer attachment**, **campaign-keyed** (uploaded once per
  invite batch, before any assignment row); surfaced the offer context on the invitation
  card and the creator's assignment surfaces; showed real `avatar_url` in the invite
  roster.
- **Touched:** `apps/api/app/Modules/Campaigns/Http/{Controllers/CampaignAssignmentController.php,Requests/InviteAssignmentRequest.php,Resources/CampaignAssignmentResource.php}`,
  `apps/api/app/Modules/Campaigns/Models/CampaignAssignment.php`,
  `apps/api/app/Modules/Campaigns/Routes/api.php`,
  `apps/api/app/Modules/Campaigns/Services/AssignmentOfferAttachmentUploadService.php`,
  `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`,
  `apps/api/app/Modules/Creators/Http/Controllers/{CreatorAssignmentController,CreatorAssignmentDraftController}.php`,
  `apps/api/database/migrations/2026_07_12_100000_add_offer_fields_to_campaign_assignments.php`,
  `apps/api/tests/Feature/Modules/{Campaigns/CampaignAssignmentInviteTest,Creators/CreatorAssignmentTest,Agencies/AgencyCreatorRosterTest}.php`,
  `apps/main/src/modules/campaigns/{api/campaigns.api.ts,components/InviteCreatorsDialog.vue,pages/CampaignDetailPage.vue}`,
  `apps/main/src/modules/creators/pages/{CreatorAssignmentDetailPage.vue,CreatorAssignmentsPage.{vue,spec.ts}}`,
  `packages/api-client/src/types/{campaign,agency}.ts`,
  `apps/main/src/core/i18n/locales/*/app.json` (24).
- **Decisions:**
  - The presigned flow **mirrors the messaging-attachment posture**: supported raster
    images are re-encoded/EXIF-stripped at complete time (`PortfolioImageProcessor`, 50 MP
    decompression-bomb guard); **non-image types are stored without content sniffing** —
    recorded as **tech-debt** (extends the platform-wide AV gap).
  - **Emission-scoped signed URLs** (60-min TTL, AH-004 posture), minted only inside an
    already-authorized resource emission.
  - **Cross-campaign prefix isolation pinned** — `assertUploadBelongs` requires the
    `upload_id` to sit under `agencies/{agency}/campaigns/{campaign}/offer-attachments/`;
    cross-campaign / cross-agency paths are rejected.
  - **`tenancy.md §4` updated in the closure commit** (`fdbec40`) with the two attachment
    routes, annotated as full-standard-tenant-stack (NOT scope bypasses).
  - Roster `avatar_url` added on a **bounded, paginated** list (the AH-013 precedent — not
    an N+1 concern).
- **Ref:** `ac76e0f` (backend + migration + service + routes), `eb901db` (api-client),
  `6b2dc5a` (invite dialog: Per + description + attachment + real avatars),
  `8e9093b` (creator sees the offer context). **Stop-gate exception** (Items 1 + 3,
  Pedram's explicit call): the offer-fields migration + resource shape + validation +
  two new routes + i18n keys.

### AH-033 · Campaign overview — name, duration, full description, contract requirement

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The campaign overview should show the campaign **name**, its **duration**, and
  the **full description**, drop the **Objective (UGC)** row, and state whether the campaign
  **requires a per-campaign contract**.
- **What:** Show campaign name + start/end duration; removed the objective row; render the
  full description **without** Vuetify's subtitle truncation; added a "Requires a
  per-campaign contract" row as the **last** item.
- **Touched:** `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`.
- **Decisions:** **No new i18n key** — reused existing keys and represented the
  contract-requirement boolean with an **icon** (Required / Not required) rather than new
  text keys, keeping the item inside the fast batch. A scoped style overrides
  `v-list-item-subtitle` truncation (`white-space: pre-wrap; word-break: break-word;`). No
  backend or resource change — everything reads from existing `CampaignResource`
  attributes.
- **Ref:** `cc86bb8` (name + duration + full description, drop objective), `9805b3b`
  (contract-requirement row), `0ae30d9` (row moved to last). No stop-gate (existing i18n +
  icon for the boolean).

### AH-032 · Campaign-creation form simplification

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The campaign create/edit form asked for more than agencies needed: an `objective`
  select (rarely meaningful — most work is UGC), a write-only `target_creator_count`, and a
  structured brief block (`deliverables` / `hashtags` / `usage_rights`) that nothing in the product
  ever rendered back. The brief inputs also carried a latent data-loss bug (see Decisions).
- **What:** Removed three things from the form, relaxing (never breaking) the API contract:
  - **Objective (D-1):** dropped the select. `CreateCampaignRequest` now validates `objective` as
    `['sometimes', Enum]` and `prepareForValidation()` defaults it to `ugc` when absent. The enum,
    column, `CampaignResource` emission, and Overview-tab display row are untouched — existing
    campaigns keep and display their objective; new ones default to UGC. An explicit objective in a
    payload is still honored.
  - **Target creator count (D-2):** dropped the input. Column, `sometimes|nullable` validation, and
    Resource emission stay — write-only became API-only. No backend change (omission preserved by
    `sometimes`).
  - **Brief (D-3):** removed the three inputs and `assembleBrief()`; the form no longer sends `brief`
    at all. Backend brief validation and Resource emission are untouched. On edit, omission +
    `sometimes` preserves the stored brief blob byte-identical.
  - **Description (D-4):** absorbs the prose role via a new persistent hint
    (`app.campaigns.fields.descriptionHint`) inviting deliverables and usage terms as free text.
    `max:5000` unchanged.
  - **i18n (D-5):** removed the orphaned `fields.{targetCreatorCount,deliverables,deliverablesHint,`
    `hashtags,hashtagsHint,usageRights}` and `board.drawer.detail.deliverables` across all 24
    locales; added `fields.descriptionHint` ×24 (real MT baseline for the 10 flaky locales, not
    English fallback). `fields.objective` + the `objective.*` block are **kept** — the Overview tab
    consumes them. Parity green.
- **Wipe-bug fix (by omission, not by design):** the shipped form rebuilt the entire `brief` jsonb
  from only its three visible inputs on every save, silently wiping any other stored sub-keys
  (`dos`/`donts`/`mentions`/`links`/`attachments`) written by any other path. Removing the inputs so
  the form stops sending `brief` eliminates the wipe as a side effect of the simplification — it is
  fixed **by omission, not by a deliberate merge fix**. A named regression test
  (`preserves the stored brief byte-identical when the edit omits it`) pins this as an invariant, and
  a forward-guard is recorded in `tech-debt.md` so a future brief editor can't reintroduce the class.
- **Touched:** `apps/api/app/Modules/Campaigns/Http/Requests/CreateCampaignRequest.php`,
  `apps/api/tests/Feature/Modules/Campaigns/CampaignCrudTest.php`,
  `packages/api-client/src/types/campaign.ts`,
  `apps/main/src/modules/campaigns/components/CampaignForm.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignCreatePage.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`,
  `apps/main/src/core/i18n/locales/*/app.json` (24), `docs/tech-debt.md`.
- **Decisions:** contract only relaxes (`objective` optional at edge + in the TS mirror); the form
  never sends `brief`/`target_creator_count`, preserving stored values by omission via backend
  `sometimes` rules; `seedEditForm()` deliberately does NOT re-seed the removed fields (re-seeding
  would revive the overwrite path and make the preservation test theatre). Out of scope, logged not
  built: creator-visible campaign description/brief (product gap — `tech-debt.md`); the vestigial
  `posting_window_*` fields absent from the form (validated backend, no input); admin campaign
  surfaces. No Playwright exposure exists for campaigns — none created this chunk.
- **Gates:** backend Campaigns suite 167 passed; `CampaignCrudTest` 16 passed (3 new); FE campaigns
  vitest 68 passed (no spec edits needed); api-client + main typecheck clean; ESLint clean; Pint +
  PHPStan clean; locale parity 23/23. Break-revert on the brief-preservation invariant confirmed it
  bites (forced `brief = null` in the controller → test red → reverted, empty diff).
- **Ref:** `d1f2608` (feat) + `797ba05` (docs); independent review approved and closed in the AH-032
  close-out commit (`docs/reviews/campaign-form-simplification-review.md`, Status: Closed). Pushed on
  Pedram's call at close-out.

---

### AH-031 · Platform rebrand: Engine C → Catalyst Engine

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The platform name was still "Engine C" across emails, SPA titles, and backend strings.
- **What:** Two-lever rollout — `APP_NAME` first (cascades through every mail surface), then
  everything else: both SPA titles/`VITE_APP_NAME`, 48× `app.json` titles, 24× `lang/app.php`, the
  API root JSON response, the seeded admin display name, and brand-layer code comments
  (`packages/design-tokens`, `packages/ui` comment-only, fan-out diff-verified against both SPA
  consumers).
- **Touched:** `apps/api/.env(.example)`, `apps/main/.env(.example)`, `apps/admin/.env(.example)`,
  `apps/api/lang/*/app.php` (24), `apps/main/src/core/i18n/locales/*/app.json` (24),
  `apps/admin/src/core/i18n/locales/*/app.json` (24), `apps/api/routes/web.php`,
  `apps/api/database/seeders/Sprint1IdentitySeeder.php`, `apps/main/index.html`,
  `apps/admin/index.html`, comment-only touches in `packages/design-tokens`, `packages/ui`,
  `apps/api/config/mail.php`, `apps/api/.gitignore`,
  `apps/api/app/Modules/Creators/Enums/ContractKind.php`,
  `apps/api/app/Modules/Creators/Services/CreatorWizardService.php`, a contracts-table migration
  docblock, and `apps/api/resources/views/vendor/mail/html/themes/catalyst.css`. Test assertions
  updated in `apps/main`/`apps/admin` unit specs and `apps/main/playwright/specs/smoke.spec.ts`.
- **Decisions:** value-only swaps on existing keys — zero keyset change, i18n parity green; the
  brand name is a proper noun, correctly left untranslated in all 24 locales (the 10
  historically-flaky locales were explicitly checked, not assumed). The `catalyst.css` comment edit
  is accepted as non-durable — it's a published vendor asset that may be regenerated by a future
  `mail:publish`, at which point the comment (not the styling) reverts; harmless. Backend files were
  touched under a UI-batch label — recorded as an accepted exception (every change is an inert
  string value, no behavior/shape change) rather than pretending the batch stayed UI-only.
- **Ref:** `a32c042` (APP_NAME lever), `9f37609` (everything else). Playwright
  `creator-wizard-happy-path` and `smoke` re-run green post-batch (smoke's own assertions changed in
  `9f37609`) — see close-out Step 2.

---

### AH-030 · Contract step: duplicate heading removed

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** "Sign the master agreement" rendered twice on the same screen — the page-level title and a
  redundant component-level heading inside `ClickThroughAccept`.
- **What:** Removed the component's own `<h2>` (the page-level title is retained as the single
  heading), demoted the explanation paragraph to sub-text, dropped the now-orphaned CSS rule.
- **Touched:** `apps/main/src/modules/onboarding/components/ClickThroughAccept.vue`.
- **Decisions:** none beyond the obvious dedupe — no i18n key change, no gate/behavior change.
- **Ref:** `27d6017`.

---

### AH-029 · Master agreement replaced with Catalyst Creator T&Cs

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The click-through agreement still named the old entity and governing law; replaced with
  the real contracting terms (Catalyst Performance Ltd, England & Wales) per the supplied PDF.
- **What:** Full rewrite of the server-rendered `master-agreement.en.md` — new contracting entity,
  new governing law, restructured to a 10-clause layout, real privacy-notice URL. Two
  content-coupled Pest tests updated to match the new title/section headings. The AH-028 scroll gate
  and the accept endpoint are untouched by this change.
- **Touched:** `apps/api/resources/contracts/master-agreement.en.md`,
  `apps/api/tests/Feature/Modules/Creators/ContractTermsEndpointTest.php`,
  `apps/api/tests/Feature/Modules/Creators/ClickThroughContractRecordTest.php`.
- **Decisions:** version deliberately held at `1.0` (Pedram's call) — new acceptances snapshot the
  new text; existing signed contracts keep their immutable Engine C/Ireland snapshot untouched
  (the DB snapshot, not the version label, is the authority — no code compares version strings
  today). Consequence, logged as mandatory tech-debt: the label `"1.0"` now denotes two distinct
  legal documents, and there is no re-consent flow — pre-swap signees will never see the new terms
  unless a future feature prompts them. This review covers the **engineering** (snapshots immutable,
  tests updated, gate intact, Playwright happy-path re-verified green); whether holding the version
  at 1.0 and skipping re-consent for existing signees is legally sound is explicitly **a question for
  counsel**, not something this review blesses.
- **Ref:** `7eb5f20`.

---

### AH-028 · Scroll-to-end gate on the click-through master agreement

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** A creator could accept the master agreement without scrolling past the visible fold —
  a weak attestation for a binding e-sign-equivalent acceptance.
- **What:** The acceptance checkbox disables until the terms region is scrolled to within 4px of
  the bottom (`SCROLL_END_THRESHOLD`, zoom-tolerant); content that doesn't overflow auto-satisfies
  on mount (a mis-measure can never permanently block onboarding — branch spec-pinned). Help text
  swaps keys by gate state. Client-side only — the accept endpoint and backend are untouched and
  unaware.
- **Touched:** `apps/main` `ClickThroughAccept.vue` + spec, 24× `creator.json`
  (`click_through_scroll_hint`), parity green. Closure commit also touched
  `creator-wizard-happy-path.spec.ts` (E2E now genuinely scrolls the terms region — the real
  master-agreement markdown overflows the region, so the happy-path exercises the actual gate, not
  the auto-satisfy branch).
- **Decisions:** shipped as a UI batch despite the one additive i18n key — retroactively accepted
  exception (parity green, single key), recorded rather than normalized: new keys still flag
  mid-batch per the mode guidance. The key initially carried English fallback in 10 locales (`bg`,
  `el`, `et`, `fi`, `ga`, `hu`, `lt`, `lv`, `mt`, `ro` — the AH-001 debt class propagating via the
  same generation path) — fixed in the closure commit with a machine-translation baseline; the
  pre-existing neighboring fallbacks in those same files remain AH-001 debt, untouched.
- **Ref:** `9fce489` (feat) + `ddeed88` (closure: auto-satisfy branch spec, MT fill, Playwright
  scroll fix) + this docs commit.

### AH-027 · Creator completeness % on the agency discover detail

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** Agencies evaluating a creator on discover couldn't see the completeness signal the
  platform already computes and exposes.
- **What:** The discover-detail page renders the creator's `profile_completeness_score` as a `%`
  bar. Read-only display of an already-on-the-wire field (`CreatorPublicProfileResource` has exposed
  it since the AH-009-era) — no resource, gate, or score-formula change. Bar colour keys `>= 100`
  cosmetically (cleared in the AH-026 sub-100 sweep).
- **Touched:** `apps/main` discover detail page + spec (`modules/discover/pages/DiscoverProfilePage.vue`
  - `DiscoverProfilePage.spec.ts`), 24× `app.json` (`app.discover.detail.completeness`), parity green.
- **Decisions:** rode the AH-026 session by explicit go-ahead but logged separately (separate surface,
  separate entry — the house rule). No BE diff.
- **Ref:** `ffe4ab9`.

### AH-026 · Onboarding floor + score reweight + wizard % display

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** Region wasn't in the profile floor (so a creator could reach submit and only then
  discover it was missing), optional profile fields earned nothing (the completeness meter didn't
  move as they filled bio/accent/contact), and the wizard chrome showed only "Step X of N" — never
  the completeness % that agencies actually see on discovery.
- **What:** Six-field profile floor (region joined `display_name`/`country`/`primary_language`/
  `categories`/`avatar`), mirrored 1:1 FE↔BE. The profile unit's 25 points split into an
  all-or-nothing **floor (13)** + per-optional **credit (12)**: bio 4, accent 2, phone 2, whatsapp
  2, street 1, postal 1 — the gate boolean stays floor-only, the score numerator goes partial
  (`profileEarned()`). Step-2 forward gate aligned to the full floor. Both wizard chromes + the rail
  now surface `profile_completeness_score` as a `%` alongside "Step X of N" (static prop threaded
  past the animation state machines — no competing calculation). Review-step copy rewritten to the
  explicit two-signal model ("everything required is done; add more to strengthen"). Mandatory fields
  marked with `*`; bio/accent gained an "Optional" hint. One-shot `creators:recompute-completeness`
  artisan command (idempotent, `--dry-run`, count summary) for the cohort. New source-scan
  floor-mirror parity spec pins the six tokens once and asserts both `isProfileComplete` (BE) and
  `floorMet` (FE) reference exactly that set.
- **Touched:** `apps/api` (`CompletenessScoreCalculator` floor+`profileEarned`, `RecomputeCreatorCompleteness`
  command + test, calculator/endpoint/flag-off/reopen fixtures gain region+optionals), `apps/main`
  (`ProfileBasicsForm` floor+required markers, `Step2ProfileBasicsPage` full-floor gate,
  `CreatorProfilePage`, both `AnimatedWizardChrome*` + `OnboardingProgress` + `OnboardingLayout` %
  display, `Step9ReviewPage` two-signal copy + submit-ready colour re-key, `WelcomeBackPage` docblock,
  floor-mirror parity spec, FE specs, Playwright happy-path region fill), 25 locale files (i18n
  done-gate, parity green).
- **Decisions:**
  - **D1/D2 (floor):** region is a floor field on both sides (FE trimmed-non-empty; BE `!== null`,
    and the SPA already maps empty→null so the two agree). Validation requests stay
    `sometimes|nullable` — the floor gates, validation doesn't, so partial saves keep working.
  - **D3 (backfill-on-next-edit, no grandfather clause):** a `pending`/`rejected` creator with
    `region = null` hard-blocks on their next profile edit until region is filled (deliberate forced
    backfill — one field, self-healing, the block always names the fillable field). Approved creators
    stay soft-warn (unchanged). No creator is permanently stranded.
  - **D4 (gate/score separation):** `stepCompletion['profile']` stays floor-only; the score awards
    partial optional credit. **Q2 = award-regardless:** optional credit is granted independently of
    floor state (the meter must never lie by refusing to move). Denominator, hidden-step exclusion,
    and every other unit's ratio are untouched — a fully-complete creator still scores 100, pinned by
    the sum-to-25 sub-split assertion + the sum-to-100 weights pin.
  - **Q1 (WelcomeBack drift, accepted):** under D4, `score > 0` now means "any engagement, including
    optionals" — a creator who typed only a bio gets "Welcome back / resume", which is the correct
    experience. Docblock updated; the alternative (re-deriving first-time-ness from structural
    signals) was rejected as fragile.
  - **Q3 (durable parity):** source-scan spec pins the six floor tokens once; both sides must contain
    exactly that set — a legitimate floor change is a one-line fixture edit, a silent one-sided edit
    is a red. Break-revert verified both directions.
  - **Sub-100-submit sweep (negative):** no gate anywhere reads the completeness score; the review
    submit gate is `incompleteSteps.length === 0`. The `Step9ReviewPage` bar colour was re-keyed from
    `score >= 100` to submit-readiness so "success" tracks done-ness, not perfection. Dashboard bar
    left as-is (genuinely just a progress bar). Recorded in `tech-debt.md`.
  - **D7/D8:** submit-gate unit membership (social ≥1, portfolio ≥1, contract) and the admin
    approval path are untouched — approval is never gated on completeness (the existing recorded
    decision, now reinforced in `tech-debt.md`).
- **Post-deploy step (D5):** after this ships, run `php artisan creators:recompute-completeness` once
  (optionally `--dry-run` first) so every existing creator's persisted `profile_completeness_score`
  moves to the new formula. Idempotent — safe to re-run; a second run reports 0 changes. Recorded as
  an operational obligation in `tech-debt.md` (there is no scheduled recompute).
- **Ref:** AH-026 feat+docs pair (push HELD).

### AH-025 · Production admin bootstrap command (admin:create)

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** No safe way to mint a production platform admin (the seeder is dev-guarded).
- **What:** New `admin:create` artisan command — email as argument, names prompted or passed,
  password generated server-side (`Str::password(24)`) and printed once, never accepted as an
  argument. Minted admin gets `mfa_required => true` (first sign-in forces TOTP enrollment —
  flagged INTO the Sprint-13 admin MFA posture, not around it). Deliberately NOT idempotent:
  an existing email (incl. soft-deleted, via `withTrashed()`) is refused — the command can
  never rotate a live password or escalate an existing account. No HTTP invocation path
  exists. User-creation audit rides the `Audited` trait (`actor_type='system'` in console
  context).
- **Touched:** `apps/api` `Console/Commands/CreateAdminUser.php` + `CreateAdminUserCommandTest.php`.
- **Decisions:** refuse-don't-upsert on duplicate email (§5.6 posture inverted deliberately —
  refusal IS the safe idempotency here); generated-not-supplied password (no shell-history
  leak); `AdminProfile`/`AgencyMembership` rows not independently audited — pre-existing trait
  coverage posture, recorded in tech-debt.
- **Ref:** `2e197a7`.

### AH-024 · Reset-password route moved to match the emailed link

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Emailed reset links pointed at `/auth/reset-password`; the SPA registered
  `/reset-password` — links landed on an unmatched route.
- **What:** SPA route moved to `/auth/reset-password`.
- **Touched:** `apps/main` `auth/routes.ts` + `ResetPasswordPage.spec.ts`.
- **Decisions:** second instance of the emailed-URL↔SPA-route mismatch class (after
  verify-email) — a backend-minted-URL↔registered-route parity test is now logged as
  tech-debt (the two-strikes ratchet).
- **Ref:** `1d9a85c`.

### AH-023 · Surname at sign-up + account-creation details on three surfaces

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Sign-up collected no surname, and the account-creation identity (name/email)
  wasn't visible anywhere post-signup.
- **What:** `users.last_name` (nullable varchar(160)); `last_name` required at sign-up
  (`min:1,max:120`); read-only account-details sections on creator self-profile, admin
  creator detail (`admin_attributes` block), and connected-agency roster detail
  (`account_name`/`account_last_name` beside the email, same relation-exists privacy basis,
  in-source "NEVER on discover" comment). Discover surfaces got nothing — proven by the
  exact-keyset discovery assertion + the untouched AH-005 negative assertions.
- **Touched:** `apps/api` (migration, `SignUpRequest`, `SignUpService`, `User`, 3 resources,
  tests), `packages/api-client` (4 type files), `apps/main` (sign-up, profile, roster +
  specs), `apps/admin` (detail + spec), 96 locale files (i18n done-gate, parity green).
- **Decisions:** column nullable for pre-existing accounts (render "—", no backfill possible —
  tech-debt); sign-up contract change is safe because the SPA form ships in the same deploy;
  column width 160 vs validation max 120 is a recorded cosmetic inconsistency (validation is
  the effective bound).
- **Ref:** `ce3bbda`.

### AH-022 · Full ISO country/language pickers + creator accent field

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** 58 hand-picked countries and 24 languages were too narrow for a worldwide creator
  base.
- **What:** Full ISO 3166-1 (250) country and ISO 639-1 (174) language pickers; new nullable
  `creators.accent` (free text, `max:80`, deliberately not an enum) shown on discover
  cards/profile, roster list/detail, and admin — an explicit product ask, same sensitivity
  class as `primary_language`. Completeness-inert.
- **Touched:** 86 files — `packages/api-client` (`countries.ts` new, `locales.ts`, types),
  `apps/api` (migration, `Locale` enum, 2 requests, 5 resources, 2 controllers, model,
  tests), `apps/main`, `apps/admin`, 48 locale files (parity green).
- **Decisions:** AH-001 reinterpretation, structural intent preserved: the two-concept locale
  split becomes three — enum cases stay the 24 EU languages (agency/brand content validation,
  unchanged), `UI_LOCALES` stays 24 (render set, unchanged), new `WORLD_LANGUAGES` (174)
  validates creator spoken-language metadata only, pinned by a §5.25 parity spec
  (`locales.spec.ts` + `LocaleEnumTest`). `00-MASTER-ARCHITECTURE.md` §13 updated to the
  three-concept model in this pass. Accent sits outside the AH-005 contact block (profile
  data, not contact data).
- **Ref:** `7faeff8`.

### AH-021 · Review page numbering + account step surfaced

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** The wizard sidebar numbered steps; the review page didn't match.
- **What:** Numbered review rows incl. "Account created". UI-only.
- **Touched:** `apps/main` `Step9ReviewPage.vue` + spec.
- **Ref:** `b6f49eb`.

### AH-020 · Verify-email pending page — email carry on the unverified bounce

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** After an unverified sign-in bounce, the pending page showed no address and resend
  failed.
- **What:** Sign-in/TOTP redirects carry `?email=`; the page uses it as display/prefill only
  (auth-store fallback), grants nothing. Resend endpoint untouched — the §5.9 silent-204
  enumeration posture stands.
- **Touched:** `apps/main` `SignInPage.vue`, `VerifyTotpPage.vue`,
  `EmailVerificationPendingPage.vue` + 2 specs.
- **Ref:** `80ac4c0`.

### AH-019 · Category taxonomy 16→28 + chip-grid picker with select-all

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** The 8-category cap and cramped dropdown didn't fit the taxonomy.
- **What:** 12 new categories (28 total), backend cap `max:8`→`max:28` with the enumerated
  whitelist in both requests, dropdown→chip grid + "Select all" (selects exactly the 28-key
  registry).
- **Touched:** `apps/main` (`ProfileBasicsForm.vue`, roster page), `apps/api` (2 requests),
  `apps/admin` field-edit config, 48 locale files (parity green), category specs.
- **Decisions:** FE has no numeric cap — structurally bounded by the 28-chip registry (no
  free entry); per §5.25 honesty the number 28 is enforced backend-only. Admin↔backend
  registry parity is spec-pinned; main↔backend is NOT — logged as tech-debt, and the
  overclaiming in-code comment corrected in this batch's closure commit.
- **Ref:** `6cf26cb` + `d0462a2`.

### AH-018 · Verify-email :app placeholder fix

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Verification emails rendered a literal `:app` in the greeting/ignore lines.
- **What:** Passed the `app` parameter to the two `trans()` calls; regression now pinned by a
  `not->toContain(':app')` assertion in the existing §5.3 real-rendering test (closure
  commit, break-revert verified).
- **Touched:** `apps/api` verify-email Blade template; rendering test (closure commit).
- **Ref:** `be87dc0` + `10ac480` (closure commit).

### AH-017 · Creator assignments mobile card redesign

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The creator assignments list was cramped on mobile.
- **What:** Restructured the assignment cards for mobile; View action → outlined, Decline → red
  outlined.
- **Touched:** `apps/main` `modules/creators/pages/CreatorAssignmentsPage.vue`.
- **Decisions:** UX polish only.
- **Ref:** commit-pair (this entry's landing commit).

### AH-016 · Creator mobile Profile-nav bootstrap fix

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** On a deep-link or hard refresh into a creator route, the Profile nav item could be
  missing because the onboarding store hadn't bootstrapped (nav visibility depends on it).
- **What:** `CreatorDashboardLayout` now bootstraps the onboarding store on mount, so the
  Profile nav item renders reliably on deep-link/refresh. Bugfix, not polish.
- **Touched:** `apps/main` `modules/creators/layouts/CreatorDashboardLayout.vue` + spec.
- **Decisions:** a nav-visibility correctness fix — not an auth gate (nav visibility ≠ route
  authorization, which the guards still enforce independently).
- **Ref:** commit-pair (this entry's landing commit).

### AH-015 · Portfolio inline collapsible drawer + preview download

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The portfolio "View all" opened a popup; a download affordance was missing from the
  preview lightbox.
- **What:** Replaced the View-all popup with an inline collapsible drawer on the roster + discover
  profile surfaces, and added a download icon to the top-left of the `PortfolioGallery` preview
  lightbox. Unrelated to messaging.
- **Touched:** `packages/ui` `PortfolioGallery.vue`, `apps/main` `roster/CreatorDetailPage.vue` +
  `discover/DiscoverProfilePage.vue`.
- **Decisions:** UX presentation only — no resource-shape or authz change (download inherits the
  existing AH-004 presigned-GET path).
- **Ref:** commit-pair (this entry's landing commit).

### AH-014 · Campaign ChatPanel parity with relationship chat

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** After the relationship-chat redesign (AH-013), the campaign `ChatPanel` looked
  inconsistent — older bubbles, no composer parity.
- **What:** Restyled campaign chat bubbles + timestamps and brought the composer to parity
  (inline send, `+` file menu, auto-scroll, desktop-only Enter-to-send). Campaign messaging
  surface only — the relationship spine is unaffected.
- **Touched:** `apps/main` `modules/messaging/components/ChatPanel.vue`.
- **Decisions:** styling/composer parity only — no change to campaign messaging behavior, data, or gate.
- **Ref:** commit-pair (this entry's landing commit).

### AH-013 · Two-pane (WhatsApp Web) messaging + real contact avatars

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** Relationship messaging was a single-column inbox→thread navigation; on
  desktop it didn't read like WhatsApp Web, and contact/inbox rows showed initials only.
- **What:**
  - **Two-pane shell** on both inboxes (list left, active thread right) via route nesting —
    `messages.thread` / `creator.messages.thread` are now children of their inbox routes
    (same URLs, full guard chain preserved) + a `meta.wide` flag driving a fluid container.
  - **Active-row highlight** (`RelationshipInbox.activeId`) for the two-pane selection.
  - **Real contact avatars** (resolves the AH-012 D5 deferral): new shared `ContactMediaUrl`
    resolver (passthrough absolute URL / sign a bare S3 key / null on non-S3 disk); both
    inboxes gain `creator.avatar_url` / `agency.logo_url` and both picker endpoints gain the
    same — additive response-shape change, api-client types updated, backend assertions added.
  - **Thread-view redesign** (`RelationshipThreadView`): back-chevron header, inline send,
    `+` attach menu (file picker + link dialog), 100dvh, desktop-only Enter-to-send, auto-scroll.
  - New i18n key `app.messaging.relationship.selectConversation` across all 24 locales (parity green).
- **Touched:** `apps/api` (both relationship message controllers, `MessageableContactsController`,
  `MessageableContactsFinder` eager-load, new `Support/ContactMediaUrl`, tests), `packages/api-client`
  (`messaging.ts` row types), `apps/main` (auth/creators routes nesting + `wide`, both `*MessagesPage`,
  both thread pages, `RelationshipInbox`, `RelationshipThreadView`, `CreatorDashboardLayout` wide
  container, locales + specs).
- **Decisions:** route-nesting (not new URLs) for two-pane so guards/URLs are unchanged; avatar URLs
  additive (no field removed); `ContactMediaUrl` is the single shared resolver (passthrough/sign/null).
  Gate untouched — `MessageableContactsFinder` changed only its eager-load, not `scopePermitsMessaging`.
- **Ref:** commit-pair (this entry's landing commit).

### AH-012 · WhatsApp-style new-conversation flow (symmetric contact picker, both sides) + provisioning fix

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** AH-010b shipped the messaging inbox but left **no way to start a
  conversation** — the agency had only a roster-detail "Message" shortcut, the
  creator had no initiation surface at all, and an empty inbox was a dead end.
  This adds a WhatsApp-style "new chat → gate-filtered contact list → thread" entry
  point on **both** sides, and corrects a provisioning bug where opening a thread
  (not sending) persisted a row — contradicting the code's own "lazy on first send"
  docblocks.
- **What:**
  - **D1 · Provisioning deferred to intent.** A gate-passing GET with no existing
    thread now returns a **transient (unsaved) thread** — no row persisted on open.
    The row materializes on the **first sent message** OR an **attachment upload**
    (both are intent; opening alone never provisions). This corrects the live
    behaviour and makes the roster "Message" shortcut stop creating ghost threads.
  - **D2 · Inbox shows only threads with ≥1 message.** Both inbox queries filter to
    threads that have at least one message — a safety net against empty ghosts.
  - **D3 · One shared messaging predicate, gate ⇔ picker.** Extracted
    `AgencyCreatorRelation::scopePermitsMessaging()` (roster + non-blacklisted); the
    single-pair `CreatorPolicy` gate is **re-sourced** through it, and the new
    set-valued `MessageableContactsFinder` shares the same scope (+ the identical
    creator-`approved` leg) so the two forms **cannot drift**. An agreement test
    pins it (every set member passes the gate; every gate-reject is absent), and the
    break-revert (diverge the finder's predicate → agreement fails) is **proven**.
    The `CreatorPolicy` spine tests stay green-unchanged (the preservation proof).
  - **D4 · Two net-new gate-filtered endpoints** (controller homes in Messaging):
    `GET /creators/me/messageable-agencies` → `{ulid, name, logo_path}` (unpaginated,
    small list); a dedicated `GET /agencies/{agency}/messageable-creators` →
    `{ulid, display_name}` with name search + pagination (NOT a flag on the
    display-oriented roster endpoint — that one deliberately includes
    blacklisted/prospect/non-approved).
  - **D5 · Avatars:** initials fallback on the agency picker (no per-row signed-URL
    minting — the roster-index N+1 judgment); the creator side gets agency
    `logo_path` free. Real creator avatars on the agency picker were **deferred** —
    now **resolved by AH-013** (the shared `ContactMediaUrl` resolver + per-row
    `avatar_url` on the picker; per-row signing is acceptable on the bounded,
    paginated picker list).
  - **D6 · Search + pagination** on the agency-side creator picker (simple
    case-insensitive `LIKE` on `display_name`); creator-side agency list is small,
    so unpaginated.
  - **D7/D8 · Shared `ContactPicker` surface + entry points.** A presentation-only
    picker reusing `RelationshipInbox`'s row shape; a "New conversation" button in
    each inbox header **and** an injected "Start a conversation" empty-state CTA
    (the dead end). The CTA action is injected per side (creator →
    messageable-agencies, agency → messageable-creators), not hardcoded.
  - **i18n:** new strings (picker title, search placeholder, CTAs, empty states) →
    `en` → 24-locale regen → **parity green**.
- **Touched:**
  - BE: `AgencyCreatorRelation` (shared scope), `CreatorPolicy` (re-sourced gate),
    `MessageableContactsFinder` (new), `MessageableContactsController` (new) + routes,
    `RelationshipMessageService` (transient thread + page/meta/mark-read guards),
    both `*RelationshipMessageController`s (open=transient, send/attachment=provision,
    inbox ≥1-message filter).
  - FE: `ContactPicker.vue` (new), `RelationshipInbox.vue` (empty-state CTA),
    both `*MessagesPage.vue` (header button + picker wiring), api-client
    `messaging.ts` types (+ nullable transient thread-meta `id`) and
    `relationshipMessaging.api.ts` methods.
  - Tests: `MessageableContactsAgreementTest` (D3 proof + break-revert),
    `MessageableContactsApiTest`, additions to `RelationshipMessageApiTest`
    (no-provision-on-open both sides, transient mark-read, open-then-send,
    attachment-upload provisions, inbox ghost-hiding), `ContactPicker.spec.ts`,
    `RelationshipInbox.spec.ts` (CTA emit).
  - Docs: this entry + the attachment-orphan tech-debt note (below).
- **Decisions:** D1–D8 above; Q1 — attachment-upload provisions (intent), the
  uploaded-then-abandoned orphan is **logged** (not resolved by D2) in
  `tech-debt.md` (S3-hygiene family); Q2 — both controllers home in Messaging,
  creator route keeps its `creators/me/*` prefix; Q4 — picker lists all messageable
  contacts incl. those with an existing thread (the UNIQUE pair routes into it).
- **Gates:** BE `Messaging|CreatorPolicy` 125 passed; FE messaging specs 28 passed;
  `@catalyst/main` typecheck + lint clean; `@catalyst/api-client` typecheck clean;
  verify-locale parity green across all 23 non-`en` locales.
- **Ref:** AH-012 kickoff + approval; two-commit pair — `68e0266` (feat) + this
  docs commit. Spot-check passed (predicate-agreement + both-ways break-revert,
  no-provision-on-open, transient-meta id-null safety, inbox ≥1-message filter).

### AH-011 · Onboarding architecture-test cleanup (two pre-existing reds)

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** Two source-scan architecture tests were red on `main`, both fallout
  from recent onboarding work (surfaced — not caused — by the AH-010b suite run).
  Standalone cleanup so the FE arch gates are honest again.
- **What:**
  - **`no-hard-coded-colors` (AH-007 fallout):** `AnimatedWizardChromeMobile.vue`
    carried a literal `--chip-active: <hex>` for the active-step "go" green. Traced
    the value: it is exactly `semantic.success[500]` and already exposed as the
    Vuetify `success` theme color (both modes) — same value AND role. Replaced the
    literal with `rgb(var(--v-theme-success))` (no palette addition needed; mapping
    to "nearest" was explicitly avoided). The hex must also stay out of the
    surrounding comment — the scan reads raw text.
  - **`form-error-pattern` (AH-009 fallout):** `Step2ProfileBasicsPage.vue` was on
    the 422-binding allowlist but had dropped its `extractFieldErrors` import. Traced
    where step-2's 422s surface now: the AH-009 profile-edit extraction moved the
    whole body into the host-agnostic `ProfileBasicsForm` (shared by the wizard page
    AND `/creator/profile`), and the binding (`extractFieldErrors` + per-field
    `error-messages` + the `ApiError` catch) moved with it. So this is a genuine
    relocation, not a silent drop: the page comes **off** the allowlist and
    `ProfileBasicsForm` (which now carries the binding) goes **on**.
- **Touched:** `apps/main/src/modules/onboarding/components/AnimatedWizardChromeMobile.vue`,
  `tests/unit/architecture/form-error-pattern.spec.ts` (allowlist swap).
- **Decisions:** Use the existing `success` token (value+role match), not a new one.
  Allowlist relocation is valid because the binding is pointed-at in `ProfileBasicsForm`
  (the invariant the test guards) — confirmed by the page's per-field-422 runtime spec
  still passing through the extracted form.
- **Ref:** this cleanup pair (fix + docs). Both `no-hard-coded-colors` and
  `form-error-pattern` green; `Step2ProfileBasicsPage` spec (10 tests, incl. the
  through-the-form 422 binding) green.

### AH-010b · Relationship messaging — WhatsApp-shaped inbox + thread (frontend)

- **Status:** Landed (push held for final spot-check)
- **Date:** 2026-06-29
- **Why:** AH-010a shipped the backend spine; AH-010b is the surface — a
  WhatsApp-shaped 1:1 inbox + thread for the connected agency↔creator DM, on the
  existing 15s poll (D7, NOT realtime).
- **What:** A net-new conversations surface, both sides, with zero new chat engine.
  - **Generic engine reuse (zero blast radius):** the thread runs on the now-generic
    `useMessageThread<TMessage, TMeta, TSend>` (campaign defaults unchanged) via new
    relationship transports. The 5 `ChatPanel` + the `useMessageThread` campaign
    specs stay green — the engine was generalized, not forked.
  - **Inbox (D8):** a shared, direction-agnostic `RelationshipInbox` (avatar, name,
    last-message preview, timestamp, unread badge). Both pages normalize their own
    rows to one item shape — agency keyed by creator, creator keyed by agency. A 45s
    poll refreshes unread badges.
  - **Thread (D7/D10):** `RelationshipThreadView` — bubbles (own-right/theirs-left),
    per-message sender name on incoming (Q4 — the creator sees which agency member
    wrote each line), HH:mm timestamps, file + link attachments (D4), composer with a
    client-side `http(s)`-only link guard. The **two-state read tick reads straight
    from `read_by_counterparty`** (server truth, never a client guess): single check =
    sent, double check (primary) = read; no tick on incoming.
  - **Entry points (D9, Q5 symmetric):** creator top-level `/creator/messages`
    - thread, "Messages" nav in both the desktop topbar and the AH-007 mobile
      bottom-nav; agency top-level `/messages` + thread (pinned into the
      `requireAgencyUser` arch-test) and a roster-detail "Message" shortcut whose
      visibility mirrors the backend gate (approved + roster + non-blacklisted).
  - **i18n done-gate:** en `app.messaging.relationship.*` + `nav.messages` +
    `availability.creatorNav.messages`, then the full **23-locale fill** (app +
    availability + notifications) — the locale-parity gate is genuinely green
    (`{sender_name}` placeholder preserved across every locale).
- **Touched:** new `apps/main/src/modules/messaging/{components,pages}/Relationship*`
  (+ specs), `relationshipMessaging.api.ts`, generic `useMessageThread`,
  `creators/routes.ts` + `auth/routes.ts` (+ guard arch-test), `CreatorDashboardLayout`
  - `AgencyLayout` nav, `roster/CreatorDetailPage`, notification registry/union/en
  - parity specs, 23 locales × {app,availability,notifications}.json.
- **Build assertions met:** new inbox/thread/transport specs green; the 5 campaign
  `ChatPanel` + `useMessageThread` specs untouched; the agency-route guard spec
  updated + green; locale-parity + notifications-parity green; typecheck + lint clean.
- **Ref:** this FE pair (feat + docs). Backend is AH-010a (`2656e5a`); push held for
  the final spot-check before both ship.

### AH-010a · Relationship messaging — backend spine + gate + attachments + notifications

- **Status:** Landed (push held for AH-010b sequencing)
- **Date:** 2026-06-29
- **Why:** A connected agency and an approved creator had no way to talk outside a
  campaign. AH-010 adds 1:1 direct messaging (WhatsApp-shaped, AH-010b is the FE)
  gated by the relationship — so a blacklisted/declined/prospect agency cannot DM,
  consistent with the AH-005 contact-visibility posture but stricter.
- **What:** A backend relationship-messaging layer built **alongside** campaign
  messaging, not on top of it.
  - **Mirrored spine (Q1, deliberate duplication-debt):** `relationship_threads`
    (`UNIQUE(agency_id, creator_id)`) / `relationship_messages` /
    `relationship_message_read_receipts` + `RelationshipMessageService`. NOT shared
    with the `messages` table / `MessageService` — the campaign `messages.thread_id`
    FK forbids it without a campaign-path change (AH-010 Step-0). Consolidation
    trigger logged in tech-debt.
  - **Gate (D2, load-bearing):** `CreatorPolicy::canMessageRelationship` —
    approved creator + roster + non-blacklisted + active membership/ownership.
    Built from a new status-aware relation query, NOT `canSeeContactDetails`/
    `hasNonBlacklistedRelation` verbatim. Break-revert verified: loosening to the
    not-blacklisted-only predicate fails the declined/prospect/pending/external/
    non-approved specs; reverted.
  - **Attachments (D4):** thread-keyed presigned files + net-new http/https links
    (`javascript:`/`data:` rejected); **synchronous on-complete EXIF strip**
    (reuses `PortfolioImageProcessor`, 25 MB / 50 MP) before any row or signed URL
    — undecodable image → clean 422, not a 500.
  - **Notifications (D5):** two dual-recipient `NotificationType` +
    `AuditAction` verbs. The AuditAction verbs are **inert vocabulary** required
    only by the NotificationType↔AuditAction one-vocabulary tie (the Sprint-11
    `message.received_by_*` precedent) — **NO `audit_logs` row is written on a
    message send**, so a private DM leaves no content or metadata trail. Enforced
    by a guard test (`writes NO audit row on message send`). Recipient resolution
    is relationship-shaped (no assignment to deref).
- **Touched:** `apps/api/app/Modules/Messaging/*` (models, factories, services,
  controllers + concern, request, resource, routes), `database/migrations/2026_06_29_1000{00,01,02}`,
  `CreatorPolicy`, `AuditAction` + `NotificationType` enums (+ their tripwires),
  new `RelationshipMessage{Api,Attachment}Test` + `CreatorPolicyTest` cases.
- **Decisions:** Q1 mirror (duplication-debt + named consolidation trigger);
  Q2 roster-only gate (`external` unreachable + non-roster); Q3 synchronous
  on-complete EXIF strip; Q4 agency-org-level participants (`sender_user_id` per
  message); Q5 symmetric inboxes both sides; Q6 no extra agency eligibility.
  Digest deferred + virus-scan out (tech-debt). `deleted_at` present-but-unwritten.
- **Build assertions met:** full suite **1755 passed / 0 failed** (zero blast
  radius on campaign messaging), gate break-revert, EXIF genuinely stripped on a
  sent image, idempotent per-pair provisioning, PHPStan + Pint clean.
- **Ref:** `2656e5a` (feat) + this docs commit (the AH-010a pair). Kickoff +
  Step-0 in chat; duplication-debt in [`tech-debt.md`](../tech-debt.md). AH-010b
  (WhatsApp-shaped inbox) is the next, separate pair.

### AH-009 · Standalone creator Profile-edit page (reuses wizard steps 2 & 3)

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The wizard was the only creator self-edit path (logged as the wizard-as-settings
  stopgap). Post-onboarding creators had no place to update their profile, socials, or
  portfolio.
- **What:** A "Profile" nav item (desktop topbar + AH-007 mobile bottom-nav) opens an editable
  `/creator/profile` page with two bordered sections — Profile basics (the extracted step-2 form
  body, incl. AH-005 contact) and Socials & portfolio (the two step-3 sub-sections mounted
  unmodified). Reuses the existing save paths (`PATCH /creators/me/wizard/profile` + the social /
  portfolio writes); a single `GET /creators/me` bootstrap hydrates everything. Step 2's `<v-form>`
  body was extracted into a shared `ProfileBasicsForm` (avatar, display name, bio + preview,
  country, region, contact fieldset, language, categories, the `updateProfile` save + 422 mapping)
  that exposes `save()` / `hydrate()` / `isPristine` + a `readiness` emit — **one form, two hosts**:
  the wizard host keeps its chrome (forward-gate, "Save and continue", nav to
  `onboarding.connections`, onMounted + guarded re-hydration watch); the profile host owns its own
  sections, snackbar, and the floor. New strings (`creatorNav.profile`, `creator.ui.profile.*`
  incl. the floor copy) authored in `en` and across all 24 locales (parity green).
- **Touched:** `apps/main` — new `onboarding/components/ProfileBasicsForm.vue` (extracted body),
  `onboarding/pages/Step2ProfileBasicsPage.vue` (now hosts the shared form, keeps wizard chrome),
  new `creators/pages/CreatorProfilePage.vue` (+ `CreatorProfilePage.spec.ts`),
  `creators/routes.ts` (+`creator.profile`), `creators/layouts/CreatorDashboardLayout.vue`
  (conditional nav item), 24× `creator.json` + `availability.json` locales.
- **Decisions:**
  - **Editable, extract-not-duplicate.** Not read-only; the wizard keeps working on the same
    shared `ProfileBasicsForm` body rather than a forked copy (break-revert verified: mutating the
    shared form fails a wizard step-2 spec).
  - **`requireAuth`-only on the creator shell — NOT `requireOnboardingAccess`** (that guard
    redirects every non-`incomplete` creator to the dashboard, which would have made the page
    unreachable for its own audience — the highest-risk finding of the inventory).
  - **Post-submission audience only** (pending / approved / rejected). The nav item is hidden for
    `incomplete` creators, and an `incomplete` deep-link is soft-redirected to
    `onboarding.welcome-back` **from the page** (not the guard, so the route stays `requireAuth`).
  - **D3 literal — sub-sections mounted unmodified.** `ConnectionsSocialSection` /
    `ConnectionsPortfolioSection` are mounted as-is; the page reacts to the store count rather than
    reaching into them, so removal warnings are **post-hoc** (fire when the count lands at zero).
  - **Lifecycle-aware completeness floor (host/page-owned — `CreatorWizardService` untouched).**
    The save paths recompute `profile_completeness_score` / `next_step` with no backend status
    guard, so the regression is guarded at the page edge, split three ways by lifecycle:
    - **pending / rejected → hard block** on profile-basics (`floorMet`, a 1:1 mirror of the
      backend `isProfileComplete`: display_name + country + primary_language + ≥1 category +
      avatar). Save is disabled and guarded, **including the avatar-delete-then-save path**
      (delete avatar → `avatar_path` null → `floorMet` false → blocked).
    - **approved → soft-warn, never block.** The edit is allowed (creator agency) but a warning is
      surfaced; the save genuinely proceeds.
    - **socials / portfolio (all states) → page-level warn at count-zero, never block.** Removing
      the last social / portfolio item is allowed; the page warns when the store count hits zero.
  - **Why approved is soft-warn, not free-edit (the load-bearing finding).** The gating
    read-question — _does anything read `next_step` / `profile_completeness_score` for an approved
    creator?_ — resolved to: `next_step` is **vestigial** post-approval (only wizard surfaces read
    it, all gated to `incomplete`), BUT `profile_completeness_score` is **agency-visible on
    discovery** — `CreatorPublicProfileResource` exposes it for `approved + is_discoverable`
    creators (the same fail-closed gate as the discovery / connection-request reads). So an
    approved creator's edit that lowers completeness lowers a signal prospective agencies see on
    discovery — which is precisely why approved is soft-warned rather than left to edit freely or
    silently. It is also surfaced on the creator's own dashboard (`CompletenessBar`, all statuses)
    and the admin list/detail.
  - **Backend status guard deferred to tech-debt** — the write endpoints have no
    `application_status` guard; this floor is the page-edge defense. (See also the recorded
    decision in `tech-debt.md`: a pending creator below 100% completeness is intentional, not a
    bug — approval is admin judgment, not a completeness gate.)
- **Ref:** `1dcd180` (refactor: extract `ProfileBasicsForm`) + `2ef98ed` (feat: standalone
  profile-edit page + floor).

### AH-007 · Creator platform mobile-responsive pass

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The creator-facing surfaces (onboarding wizard + post-submit dashboard) were
  built desktop-first and were cramped/unusable on small viewports — the wizard's left step
  rail and the dashboard/wizard topbar controls overflowed, the framed wizard content was
  locked to a fixed-viewport inner scroll, and several step-2/step-3 fields broke layout on a
  phone.
- **What:** A frontend-only pass (`apps/main` + one `packages/ui` component), with mobile
  behaviour gated on Vuetify `smAndDown` so desktop is unchanged except where noted:
  - **Navigation reflow.** Onboarding topbar collapses the locale switcher + "Save and exit"
    into a right-side `v-navigation-drawer` hamburger (`v-app-bar-nav-icon`); the creator
    dashboard moves its primary nav from the inline topbar to a `v-bottom-navigation` bar.
  - **Mobile wizard chrome.** New `AnimatedWizardChromeMobile` — a horizontal top step rail
    (fixed edge-anchored number boxes: completed pinned left, upcoming pinned right, active
    centred; thin per-state rectangle outlines) with a snap → SVG-frame-draw → typewriter
    step transition, used instead of the desktop left-rail chrome on `smAndDown`.
  - **Full-height framed content.** The mobile frame moved from a fixed-viewport box with an
    inner scroll to a full-height card the _page_ scrolls; the SVG outline draws the card's
    full height (all four antennas still fire), the step rail is `position: sticky` under the
    app-bar, and a panel `ResizeObserver` (`syncFrameSize`) keeps the outline glued as content
    height changes.
  - **Per-step scroll reset.** Both chromes (desktop + mobile) reset the framed content to its
    top on each step change so a step never opens inheriting the previous step's scroll.
  - **Step-level fixes.** Step 2: the bio/profile preview wraps long unbroken strings
    (`overflow-wrap`/`word-break`) and the dial-code autocomplete no longer wraps to two lines
    on mobile focus. Step 3 social: a mobile-only stacked card with a view/edit toggle
    (read-only `@handle` → Edit reveals the input with Save/Cancel). Step 8: spacing between
    the agreement alert and "Save and continue".
  - **Light-mode logo regression fix.** The light-header logo darkening (added with the
    Catalyst-logo branding swap) used a `:global(...)` scoped rule that Vue's compiler
    collapsed to a bare `.v-theme--light { filter: brightness(0) }`, blacking out the whole
    dashboard in light mode; re-driven from a theme-bound class on the `<img>`.
  - **i18n:** added `app.nav.menu` (hamburger aria-label) and `creator.ui.wizard.actions.cancel`
    across all 24 locales.
- **Touched:** `apps/main` onboarding (`OnboardingLayout`, new `AnimatedWizardChromeMobile`,
  `AnimatedWizardChrome` scroll-reset only, `Step2ProfileBasicsPage` CSS, `Step8ContractPage`
  CSS, `ConnectionsSocialSection` mobile card + view/edit), creator dashboard
  (`CreatorDashboardLayout` bottom nav + logo theme-class fix), shared `packages/ui`
  (`PortfolioGallery` copy-link clipboard fallback), locales (`app.json` `nav.menu` +
  `creator.json` `actions.cancel`, all 24).
- **Decisions:** all mobile branches gated on `smAndDown` (desktop untouched) — _except_ the
  social **Remove** button, deliberately given an outline (`variant="text"` →
  `variant="outlined"`) on **both** desktop and mobile. Mobile wizard frame grows with content
  and the page scrolls (not an inner scroll box). The mobile social view/edit toggle is local
  UI state only and reuses the existing connect/remove flows verbatim (no payload change). Logo
  darkening re-expressed as a theme-driven class, not an ancestor `:global` selector (the
  scoped-CSS footgun that caused the blackout). Beyond-CSS notes: the `PortfolioGallery`
  `execCommand` copy fallback is `<script>` logic in a shared component (affects all consumers,
  copy-feedback only — no content change; desktop success path verified unchanged); no
  API/resource-shape changes; the AH-005 contact card is untouched.
- **Ref:** `dd7d93a` (mobile nav) · `d4e282b` (mobile chrome + polish) · `7e2c327` (scroll
  reset) · `0b176a3` (full-height frame) · `1da5dae` (light-mode blackout fix) + this docs
  commit.

### AH-008 · Portfolio link cards — copy-URL button

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Portfolio link items showed their destination URL but offered no quick way to copy
  it — agencies/creators had to open the link and copy from the address bar.
- **What:** Added a copy-link affordance to link-kind cards in the shared `PortfolioGallery` —
  an icon button that writes the item's `externalUrl` to the clipboard and shows a ✓ tick for
  1.5 s. Surfaced on every gallery consumer (creator onboarding, roster detail, discover
  profile, admin creator detail) via a localized `copyLinkLabel` aria-label across all 24
  locales (main `creator.json` + admin `creators.json`); the consumer pages only pass the new
  label prop. No API or data-shape change.
- **Touched:** `packages/ui` (`PortfolioGallery` button + `PortfolioDrawer` label passthrough),
  `apps/main` (`ConnectionsPortfolioSection`, roster + discover detail pages), `apps/admin`
  (creator detail page), all 24 `creator.json` / `creators.json` locales.
- **Decisions:** the copy logic lives in the shared component, which stays i18n-free (label via
  prop); no persistence/analytics. The HTTP/iOS `execCommand` copy fallback was added later as
  part of the AH-007 mobile pass, not here.
- **Ref:** `185f1a9` (feat) + this docs commit.

### AH-006 · Finish the Connect→Add rename (step-3 social copy)

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** AH-003 renamed the social-account button Connect→Add (nothing actually connects —
  username entry only), but left the surrounding step-3 headings/labels saying "Connect," so the
  screen contradicted itself across all 24 locales.
- **What:** Swept the remaining "Connect"-family copy on the social-account CTA surface to "Add"
  framing — three value edits (`connections.title`, `social.title`, `social.description` in
  `creator.json`) — and regenerated across all 24 locales. Several locales (bg, el, et, fi, ga,
  hr, hu, lt, lv, mt, ro, sk, sl) had never received a translation for the social sub-keys at all;
  hr/sk/sl had Czech copy-pasted into their social block. All corrected in this pass.
- **Decisions:** copy-only, no behavior change; value-edit over key-rename to avoid keyset churn;
  unrelated "connect" uses left untouched (Stripe payout copy, agency connection-request
  workflow, discover connection-status badges, network-error strings, JS identifiers). Two
  agency-side social-metrics empty-state strings flagged as ambiguous but left untouched and
  recorded as tech-debt (social integration deferred).
- **Ref:** `33f2941` (feat) + `90832f4` (docs)

### AH-005 · Creator contact details (phone, WhatsApp, address) — connected-agency-visible

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Connected agencies had no way to reach a creator directly — only the related User's
  email was exposed, and creators had nowhere to provide a phone, WhatsApp, or mailing address.
- **What:**
  - **Four optional plaintext fields on `creators`** — `phone`, `whatsapp`, `address_street`,
    `address_postal_code` (all nullable). The full mailing address composes from the existing
    `country_code` + `region` (city line) + the two new fields — no field stored twice. Plaintext,
    NOT the tax address's `encrypted:array` handling, because these are deliberately agency-visible.
  - **Agency-scoped visibility gate** — `CreatorPolicy::canSeeContactDetails(User, Creator, Agency)`
    = admin OR (active member of _that_ agency AND _that_ agency's relation is non-blacklisted). The
    "non-blacklisted relation" check is one shared `hasNonBlacklistedRelation()` primitive that
    `hasAgencyAccess()` also calls — one canonical blacklist rule. Agency-scoped, not a user-wide
    union: a multi-agency user on Agency A's page for a creator A has blacklisted sees no contact,
    even if their Agency B has a clean relation.
  - **Surfaced only on roster detail** (`AgencyCreatorDetailResource`, gated) + creator-owner
    self-read + admin view-only (base `CreatorResource` attributes — no `admin_attributes`
    duplicate, `EDITABLE_FIELDS` untouched so it stays creator-owned, not admin-editable).
  - **Explicitly withheld** from six surfaces (discover detail, discover list, roster list row,
    talent-pool member, campaign assignment, messaging thread list) — each by omission, each with a
    negative absence assertion that fails if a contact key is ever added there.
  - **Self-edited** via a "Contact details" sub-section on the profile wizard step; rendered to the
    connected agency as a Contact card on roster detail (shown only when the server surfaced it).
  - **i18n done-gate:** new contact-sub-section labels regenerated across all 24 locales; parity green.
- **Touched:** `apps/api` (`creators` migration + four columns, `Creator` model/factory,
  `UpdateProfileRequest`, `CreatorPolicy` gate + shared primitive, `AgencyCreatorDetailResource`,
  `CreatorResource` base attributes, `AgencyCreatorDetailController`), `packages/api-client`
  (`creator.ts` / `agency.ts` types), `apps/main` (`Step2ProfileBasicsPage` contact sub-section,
  roster `CreatorDetailPage` Contact card), locales, policy + withholding + render specs.
- **Decisions:** plaintext not encrypted (agency-visible by design); inline columns not a dedicated
  table; agency-scoped blacklist-aware gate (not the looser relation-exists); `region` reused as the
  city line (no duplicate city column); admin view-only (not the `EDITABLE_FIELDS` contract);
  distinct WhatsApp number (not a flag). Break-revert surfaced and fixed a `toHaveKey($key, $msg)`
  misuse that had silently neutered the withholding guards.
- **Follow-up — country-code dial selector (`1399ee3`):** the phone + WhatsApp contact inputs
  this entry added gained a searchable dial-code selector (a `v-autocomplete` of `+NN` codes,
  backed by new static `countries.ts` / `dialCodes.ts` data and a small `vuetify.ts` default),
  so the dial code is picked separately from the national number on `Step2ProfileBasicsPage`.
  Frontend + static data only — no `apps/api` / `packages/api-client` change, so the `phone` /
  `whatsapp` resource shape is unchanged.
- **Ref:** `5dc1e1f` (feat) + `e58dfec` (docs); dial-code follow-up `1399ee3`.

---

### AH-002 · Digest/invite email locale docblock + English-only decision

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** The `UnreadMessagesDigestMail` docblock falsely implied per-recipient locale handling,
  and the deliberate English-only disposition of the digest + agency-invite emails was unrecorded.
- **What:** Corrected the docblock to state the digest renders in the application default locale
  (`en`) for all recipients — no `->locale(...)` at the send site — and logged the English-only
  decision as tech-debt, including why the digest is harder to localize than a normal mailable: its
  lines are built with `__()` in console context inside `MessageDigestService` (`:204`/`:212`/
  `:220`) before the job is queued, so a future fix must localize at line-build time, not just chain
  `->locale()` at the send site. No behavior change; no test change.
- **Touched:** `apps/api/app/Modules/Messaging/Mail/UnreadMessagesDigestMail.php` (docblock only),
  `docs/tech-debt.md` ("Digest + agency-invite emails are English-only (deliberate)").
- **Ref:** `766d925` (docblock + tech-debt entry); this log reconciliation commit.

---

### AH-004 · Portfolio overhaul (schema + async image worker + drawer)

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The portfolio was a thin, image-only path: small per-creator cap, no full-resolution
  download, raw EXIF-bearing originals served straight back, no link entries, and three separate
  resources each minting signed URLs with their own copy of the (missing) safety logic. It also
  presented inconsistently across the creator, agency-roster, agency-discover, and admin surfaces.
- **What:**
  - **`processing_status` lifecycle** (`processing` → `ready` / `failed`) on portfolio items —
    new enum + migration (`default('ready')` so all existing rows + link items are ready) + model
    cast + factory `processing()` / `failed()` states.
  - **Presigned image uploads** mirroring the proven video path: `POST portfolio/images/init`
    (presigned `PUT`) → client `PUT` with **progress + a client timeout** → `POST
portfolio/images/complete`, which dispatches the worker. Uniform **500 MB** ceiling for all
    file types; per-creator cap raised **10 → 30**.
  - **`ProcessPortfolioImageJob` + `PortfolioImageProcessor`** — an async worker that re-encodes
    the upload at **full resolution with EXIF stripped** (not the avatar downscale path),
    generates a 512px-max-edge thumbnail, and guards a **`MAX_MEGAPIXELS = 50`** decompression-bomb
    cap. On success → `ready`; on over-cap / corrupt input → `failed`. The 50 MP cap is a **matched
    pair** with the memory pins (below): a near-cap decode stays inside the 512 MB test / 768 MB
    worker envelope.
  - **Shared `PortfolioItemPresenter`** — the single source of truth that all **three** portfolio
    mint sites (`CreatorResource`, `AgencyCreatorDetailResource`, `CreatorPublicProfileResource`)
    now route through, so the **server-authoritative `ready`-gate lives in one place**: `view_url`,
    `thumbnail_view_url`, and `download_url` are minted **only** when `processing_status === ready`;
    otherwise null. A break-revert on this gate is the load-bearing spec.
  - **Download** = a presigned GET on the **same already-authorized resource** with
    `ResponseContentDisposition=attachment` (full-res source, never the thumbnail). It therefore
    **inherits each surface's view authz** and the same `ready`-gate — never a broader grant than
    view. Per-surface authz feature tests pin that a caller who 404s the resource never receives a
    `download_url`.
  - **Link portfolio items** — `POST portfolio/links` with http/https-only URL validation (XSS
    guard), surfaced as `ready`-by-definition items with an `external_url`.
  - **`PortfolioDrawer`** — one reusable `v-dialog` (the `ReviewDraftDrawer` pattern) wrapping
    `PortfolioGallery`, wired into all four surfaces with a "View all" affordance + processing
    spinner / failed-state overlays / download button.
  - **Deleting an item cleans up its S3 objects** (raw + thumbnail), including `failed` items whose
    raw object is unreachable behind the gate but would otherwise orphan.
  - **Memory pins (matched pair):** `composer test` runs at `-d memory_limit=512M`; the prod/dev
    `queue:work` worker is sized at `--memory=768` and documented in `local-dev.md`.
  - **i18n done-gate:** new `creator` (main) + `creators` (admin) strings — processing / failed /
    download / view-all labels and the add-link form — regenerated across all 24 locales;
    parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`PortfolioProcessingStatus` enum, migration, `CreatorPortfolioItem`
  model + factory, `PortfolioImageProcessor`, `ProcessPortfolioImageJob`, `PortfolioUploadService`,
  `PortfolioController`, routes, the shared `PortfolioItemPresenter`, the three portfolio resources,
  `composer.json`), `packages/api-client` (`presigned.ts` progress/timeout, `types/creator.ts`),
  `packages/ui` (`PortfolioGallery`, new `PortfolioDrawer`, `index.ts`), `apps/main` (onboarding
  api/composable + spec, `ConnectionsPortfolioSection`, `PortfolioUploadGrid`, roster + discover
  detail pages), `apps/admin` (creator detail page), all `creator.json` / `creators.json` locales,
  `package.json`, `docs/runbooks/local-dev.md`, backend feature/job tests.
- **Decisions:** `MAX_MEGAPIXELS = 50` (not 100) to keep a near-cap decode inside the 512/768 MB
  envelope while still guarding the bomb line; download inherits view authz rather than being a
  separate (broader) grant; the legacy direct-multipart image endpoint is kept for the Playwright
  seed but bypasses the worker (recorded in tech-debt). Resume/multipart, presign-expiry handshake,
  and S3 storage-cost-at-scale remain deferred (tech-debt AH-004 carry-overs).
- **Ref:** `docs/reviews/ah-004-portfolio-overhaul-plan.md` (audited plan); tech-debt
  "Portfolio upload — resume / presign-expiry / storage cost (AH-004 plan carry-overs)" +
  its build-time addendum. Commit-pair: `7b62272` (feat) + `b0605be` (docs); pre-push
  reconciliation follow-up adds the corrected legacy-endpoint disposition + the AH-001 i18n
  completeness debt entry.

---

### AH-003 · Wizard slim + profile-basics polish

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** Sprint 10 (payments) and automated KYC aren't built, and KYC is manual today, so
  the Identity-verification / Tax / Payout steps collect nothing actionable yet — they made
  onboarding longer without value. Separately, the wizard hard-coded its step count (and a
  comment falsely claimed it rendered dynamically), "Connect" misled on form-only social, and
  the profile photo was circular.
- **What:**
  - **Reversible-hide of kyc/tax/payout** via a single static registry
    (`WizardStep::WIZARD_HIDDEN_STEPS`, mirrored by the TS `WIZARD_HIDDEN_STEPS`), held in
    lockstep by a 5.25 parity test. Hidden steps are excluded from the rail, numbering,
    completeness denominator, and the submit gate (so the always-required `tax_profile_complete`
    no longer dead-locks submit). Re-introduction = remove from the list (+ flip the kyc/payout
    Pennant flags ON). NOT a feature flag — it's a build-time "not ready yet" hide.
  - **Merged Social + Portfolio** into one "connections" step with the two kept as distinct
    sub-sections (backend keeps them as separate completion units; APIs/weights unchanged).
  - **Derived numbering/progress/geometry** from a single visible-step list
    (`useWizardSteps`) — removed `TOTAL_STEPS = 9`, the index maps, and the animated chrome's
    `/7`·`/8` divisors, and fixed the false "renders dynamically" comment. A future hide/show
    is now a one-line registry flip.
  - **Profile-basics polish:** photo rectangular (was circular, style-only); "Primary
    language" → "Native language" (label only, column unchanged); removed the "Other languages"
    onboarding input (the `secondary_languages` column + its roster/discover/detail/admin
    displays from AH-001 are untouched; the save payload omits the field so existing data is
    preserved); social CTA "Connect" → "Add" (empty) / "Edit" (added).
  - **i18n done-gate:** the changed/new `creator` strings regenerated across all 24 locales;
    the orphaned `creator.ui.wizard.fields.secondary_languages` key deleted from all 24
    (verified wizard-only first); parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`WizardStep` enum + hidden registry, `CompletenessScoreCalculator`,
  `CreatorResource` bootstrap), `packages/api-client` (`wizard.ts` registry + parity spec),
  `apps/main` onboarding module (new `useWizardSteps`, merged `Step3ConnectionsPage` + two
  section components, `OnboardingLayout`, `OnboardingProgress`, `AnimatedWizardChrome`,
  `Step2ProfileBasicsPage`, `Step9ReviewPage`, `AvatarUploadDrop`, routes), all `apps/main`
  `creator.json` locales, unit + architecture specs, Playwright happy-path.
- **Decisions:** Q1 submit gate ignores `tax_profile_complete` while tax is hidden (the
  alternative is a literal deadlock) — **re-introduction obligation recorded in tech-debt**:
  Sprint 10 must backfill tax for creators who onboard during the hidden window, since tax is
  legally required before a first payout. Q2 static config (not Pennant); hidden takes
  precedence over the existing flag-based skip. Q8 the orphaned `secondary_languages` key is
  deleted from all 24 (parity forces all-24 anyway). D7 "Connect"→"Add", added→"Edit".
- **Ref:** kickoff "Creator onboarding + profile + portfolio reshape (AH-003 + AH-004)";
  tech-debt entries "Hidden onboarding steps (kyc/tax/payout) — Sprint-10-gated" + the AH-004
  upload-ceiling debt. Commit-pair (this entry's landing commit).

---

### AH-001 · EU locale support (24 languages) + persistence

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The language switcher reset on every reload/login (a selected language never
  stuck), and the platform shipped only 3 locales (en/pt/it) while serving EU-wide users.
- **What:** A selected UI language now persists across reload and login in both SPAs
  (server-authoritative via `PATCH /me`, with localStorage for the pre-auth choice), and the
  UI + content-language sets expanded from 3 to all 24 official EU languages via a
  model-authored machine-translation baseline. Includes lazy per-locale loading (only the
  active language is fetched), CLDR pluralization rules for all 24, a request-locale
  middleware so server error strings/emails follow the caller, and parity/placeholder/plural
  CI gates across both SPAs and the backend `lang/` tree. Legally binding content
  (`resources/contracts/**`) is carved out and stays English.
- **Touched:** `packages/api-client` (locale + plural-rules + format registries), both SPA
  i18n bootstraps + switchers + auth stores, Identity module (`PATCH /me`, `SetLocale`
  middleware), `apps/api/lang/**`, all locale JSON across `apps/main` + `apps/admin`,
  architecture parity specs, SOT docs (`00-MASTER-ARCHITECTURE §13`, `CURSOR-INSTRUCTIONS`,
  `02-CONVENTIONS`), new `docs/i18n-glossary.md`.
- **Decisions:** `preferred_language` validates against the rendered `UI_LOCALES`;
  content-language fields against the full `EU_LANGUAGES` (24). `PATCH /me` ignores unknown
  fields rather than 422-ing (matches the notification-preferences precedent; extra fields
  are provably inert). Translation baseline is structurally validated (keys/placeholders/
  plural-form-counts), **not** meaning-verified — per-market human review is a go-live gate,
  not a merge gate. Digest + agency-invite emails remain English-only by decision (see AH-002).
- **Ref:** `docs/reviews/eu-locale-support-review.md` (full review, 9 sub-steps, 48/48 parity).

---

_Maintained alongside the work: when an ad-hoc change lands, its entry moves here in the
same pass — the log and the build move together, never as an afterthought._
