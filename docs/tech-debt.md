# Catalyst Engine — Tech Debt Register

A living list of conscious shortcuts and deferred decisions. Each entry names the
area, the cost we are accepting now, the trigger that escalates the work, and the
sprint by which it must be resolved.

The aim is to make every shortcut visible: nothing in this file should surprise
anyone reviewing it later.

---

## Backend-minted email URL ↔ SPA registered route has no parity test (two strikes: verify-email, reset-password)

- **Where:** the backend URL-minting services (`EmailVerificationService` / `PasswordResetService::buildResetUrl()` and any future emailed-link minters in [`apps/api/app/Modules/Identity`](../apps/api/app/Modules/Identity)) vs the SPA route registrations ([`apps/main/src/modules/auth/routes.ts`](../apps/main/src/modules/auth/routes.ts)).
- **What we accepted (AH-024, July 8, 2026):** nothing pins that a backend-minted email link lands on a registered SPA route. The mismatch class has now occurred **twice** — the verify-email link (fixed earlier) and the reset-password link (`/auth/reset-password` minted vs `/reset-password` registered, AH-024) — each time discovered by a real user clicking a real email into a blank page. Both fixes were route moves; the class itself is unguarded.
- **Trigger:** the next auth-surface chunk, or a third occurrence of the class — whichever comes first (the two-strikes ratchet is why this is now logged rather than shrugged off).
- **Resolution:** a parity test that extracts every backend-minted SPA path (the `buildResetUrl`-style helpers) and asserts each resolves against the SPA route table (path-string parity is enough; no need to boot the router).
- **Owner:** the next auth-surface chunk.
- **Status:** open. Surfaced by AH-024, July 8, 2026 ([ad-hoc log](reviews/adhoc-changes-log.md)).

---

## Main-SPA `CATEGORY_KEYS` has no direct PHP parity spec (the admin registry does)

- **Where:** [`apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue`](../apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue) `CATEGORY_KEYS` vs the backend `categories.*` whitelist in `UpdateProfileRequest` / `AdminUpdateCreatorRequest::CATEGORY_ENUM`.
- **What we accepted (AH-019, July 8, 2026):** the admin SPA's category registry is spec-pinned against the actual PHP source (`field-edit-config-parity.spec.ts` parses `CATEGORY_ENUM` out of the Request file); the **main** SPA's copy is not — a key-set drift there surfaces as a runtime 422 on save, not a test failure. The overclaiming in-code comment (which credited the admin spec with pinning this copy too) was corrected in the AH-018–025 closure commit.
- **Trigger:** the next category-taxonomy change, or the next main-SPA architecture-test pass.
- **Resolution:** mirror `field-edit-config-parity`'s PHP-parse approach in a main-SPA architecture spec (parse the `in:` rule string or `CATEGORY_ENUM` and compare to `CATEGORY_KEYS`).
- **Owner:** the next chunk that touches the category taxonomy.
- **Status:** open. Surfaced by the AH-019 spot-check, July 8, 2026 ([ad-hoc log](reviews/adhoc-changes-log.md)).

---

## Pre-surname accounts render `last_name` as "—" (no backfill possible)

- **Where:** `users.last_name` ([migration `2026_07_08_130000`](../apps/api/database/migrations)) and every account-details surface that renders it (creator self-profile, admin creator detail, connected-agency roster detail).
- **What we accepted (AH-023, July 8, 2026):** `last_name` became required at sign-up, but the column is nullable because every pre-existing account signed up before the field existed — there is no data source to backfill from, so those accounts render an em-dash on the account-details surfaces indefinitely (or until the user is given an edit path; account details are deliberately read-only today).
- **Trigger:** a product call that the gap matters (e.g. compliance/KYC wants full legal names for legacy accounts) — at which point the fix is a prompt-to-complete flow, not a migration.
- **Resolution:** none possible at the data layer; if triggered, add a profile-side "complete your account details" prompt for null-`last_name` users.
- **Owner:** product.
- **Status:** recorded posture. Surfaced by AH-023, July 8, 2026 ([ad-hoc log](reviews/adhoc-changes-log.md)).

---

## `AdminProfile` / `AgencyMembership` creation rows are not independently audited

- **Where:** [`apps/api/app/Modules/Admin/Models/AdminProfile.php`](../apps/api/app/Modules/Admin/Models/AdminProfile.php) and [`apps/api/app/Modules/Agencies/Models/AgencyMembership.php`](../apps/api/app/Modules/Agencies/Models/AgencyMembership.php) — neither uses the `Audited` trait, so creating one writes no audit row.
- **What we accepted (surfaced by AH-025, July 8, 2026):** `admin:create` minting a platform admin audits the **User** creation (the `Audited` trait on `User`, `actor_type='system'` in console context) but the privilege-carrying rows — the `AdminProfile` (role) and `AgencyMembership` (agency + role) — leave no independent trail. This is the **pre-existing** trait-coverage posture (the invite flow has the same shape), not something AH-025 introduced; it is logged because the command made the gap visible for the most privileged account type.
- **Trigger:** an audit-coverage hardening pass (privilege grants are exactly what an auditor asks about first).
- **Resolution:** add the `Audited` trait (or explicit `AuditLogger` calls) to both models, with the role/agency in the allowlisted snapshot.
- **Owner:** a future audit/compliance hardening pass.
- **Status:** open (pre-existing, newly recorded). Surfaced by AH-025, July 8, 2026 ([ad-hoc log](reviews/adhoc-changes-log.md)).

---

## Recorded decision (NOT debt) — a pending creator below 100% completeness is intentional; do NOT gate approval on completeness

- **Where:** the admin creator review queue ([`apps/admin/src/modules/creators`](../apps/admin/src/modules/creators)) — the `profile_completeness_score` column + the approve action — and the wizard write paths that recompute that score (`CreatorWizardService`, `PATCH /creators/me/wizard/profile` + the social / portfolio writes).
- **The decision (so it isn't re-litigated as a bug):** A creator in `application_status = pending` can sit **below 100% completeness**. After submission a creator can still edit — e.g. via the AH-009 `/creator/profile` page they may clear an optional field or remove a social / portfolio item — and `profile_completeness_score` recomputes downward while `application_status` does **not** change (the writes never touch status; only `submit()` does). That is **intentional, not a defect:** approval is **admin judgment, not a completeness gate**. The completeness column surfaces the signal; the admin either approves anyway, or rejects-with-reason naming what's missing. **Do NOT add a completeness gate (a "must be 100%") to the approve action.**
- **Why this is recorded here:** AH-009 made post-submission editing a first-class surface, so the "incomplete creator in the pending queue" state is now reachable in normal use and will look surprising to someone who assumes pending ⇒ complete. The AH-009 page-edge floor already guards the _silent_ part of the regression (pending/rejected hard-block on profile basics; approved soft-warn because `profile_completeness_score` is agency-visible on discovery) — but it deliberately neither freezes the score nor blocks admin approval. The page-edge floor + the admin's judgment are the two controls by design; a server-side approve-time completeness gate is explicitly **out**.
- **Trigger:** none. **Owner:** none. **Status:** recorded decision — needs no work; it exists solely to prevent a future "fix." (Distinct from the real, separately-logged deferral: the wizard write endpoints carry no `application_status` guard — defence-in-depth deferred, noted in the AH-009 ad-hoc log. That is about _who may call the write_, not about _gating approval on completeness_.)

---

## AH-010a (Relationship messaging) — the mirrored message layer is deliberate duplication-debt

- **Where:** the Messaging module ([`apps/api/app/Modules/Messaging`](../apps/api/app/Modules/Messaging)) — the new relationship spine (`RelationshipThread` / `RelationshipMessage` / `RelationshipMessageReadReceipt` + `RelationshipMessageService` + `RelationshipMessageAttachmentUploadService` + `RelationshipMessageNotifications`, [migrations `2026_06_29_100000`–`100002`](../apps/api/database/migrations)) sitting ALONGSIDE the Sprint-11 campaign spine (`MessageThread` / `Message` / `MessageReadReceipt` + `MessageService` + `MessageAttachmentUploadService` + `SendMessageNotifications`).
- **What we accepted in AH-010a (June 29, 2026):** relationship messaging is a **mirrored** message layer, NOT a generalization of campaign messaging. The campaign `messages.thread_id` FK points hard at `message_threads`, so relationship messages cannot share the `messages` table / `MessageService` without a campaign-path schema change. To protect the shipped Sprint-11 system (campaign messaging + its full suite stay untouched/green — a build assertion verified by the 1755-green full run), AH-010a duplicates the read/write/attachment/notification shape against parallel tables. The duplication is the cost; the win is **zero blast radius** on a live surface.
  - The mirror diverges where it should: **EXIF is genuinely stripped** on relationship image attachments (synchronous on-complete, reusing `PortfolioImageProcessor` — campaign messaging still raw-stores EXIF, separately logged); the relationship spine has **no system messages / no `system_event_key`** (no assignment to hang them off); the gate is **status-aware** (`CreatorPolicy::canMessageRelationship` — approved + roster + non-blacklisted, NOT the looser AH-005 not-blacklisted predicate).
- **Consolidation trigger (named):** extract a **shared message contract** (a `MessageLog` interface + a thread-subject abstraction the two spines both implement) **once it is safe to touch both surfaces together** — i.e. when the next sprint already has the campaign messaging suite open for change, OR when a third message surface is proposed (the rule of three). Until then, a shared abstraction would force a campaign-path edit purely for DRY, which is precisely the risk this split avoids. **Resolution:** unify the two spines under the shared contract; delete the duplicated service/attachment/notification logic in favour of one parameterized by the thread subject.
- **Two deferrals ride AH-010a (each a discrete, isolated block):**
  1. **Relationship-message DIGEST is deferred (D5).** The Sprint-11 `MessageDigestService` / `UnreadMessagesDigestMail` is campaign-shaped (it derefs `assignment->campaign` for queries + labels). Relationship messaging ships with **in-app unread + the new in-app notification types** covering the alerting need; the daily email digest is not extended to relationship threads this chunk. **Triggered by** a product call that relationship DMs need an email digest. **Resolution:** generalize the digest query/labelling to take a thread subject (rides the consolidation trigger above).
  2. **`relationship_messages.deleted_at` is present-but-unwritten.** The column is laid down (the campaign-`messages` D-14 parity) but **no delete endpoint ships** — message deletion is out of scope (no path on either surface). **Triggered by** a moderation/redaction sprint. **Resolution:** add the soft-delete write path + the read filter.
- **Virus scanning is OUT (platform-wide gap, not AH-010-specific).** Relationship-message file attachments are MIME/size/prefix-validated + (for images) EXIF-stripped, but **not virus-scanned** — the same gap campaign messaging + portfolio uploads carry. Logged here so the relationship surface is not assumed safer than it is. **Triggered by** a platform-wide AV pass. **Resolution:** a shared scan-on-complete seam across all upload surfaces.
- **Owner:** the next sprint that opens campaign messaging for change (consolidation + digest); a future moderation sprint (`deleted_at`); a platform-wide AV pass (scanning).
- **Status:** open (by design). Surfaced + deliberately deferred by AH-010a, June 29, 2026 ([ad-hoc log](reviews/adhoc-changes-log.md)).

---

## Sprint 13 (Admin Panel Core) — the coming-soon / shell seams (each a discrete swappable block)

- **Where:** the admin SPA ([`apps/admin/src`](../apps/admin/src)) + the admin/notifications/compliance backend modules.
- **What we accepted in Sprint 13 (June 7, 2026):** the admin panel shell + every NON-payment Phase-1 surface shipped green (agency mgmt + suspend-at-auth, creator KYC/detail, audit-log viewer, feature-flag toggles, dashboard, Horizon embed + health, impersonation core + enforcement + dual-audit, tightened admin session timeout). Five conscious deferrals ride it, each built as a discrete block S10/S14 SWAPS rather than unpicks:
  1. **Payment surfaces are coming-soon (D-13, Sprint-10-gated).** The payments nav (disputes / recent), the dashboard payment + dispute cards, the creator-detail payment section, and the payment-event admin alerts ALL ship as the proven coming-soon/placeholder pattern. Each is a discrete swappable block: S10 replaces the `ComingSoonPage` reference / flips `meta.payment_alerts.coming_soon` false / drops the placeholder card — no payment assumptions are woven elsewhere to unpick. **Triggered by** Sprint 10 (escrow). **Resolution:** swap placeholder → real surface.
  2. **GDPR compliance queues are SHELLS (D-11, Sprint-14-gated).** `GET /admin/compliance/export-requests` + `/erasure-queue` return `data: []` + `meta.shell: true` (200, not 404) and the SPA renders the shell-state copy. The `data_export_requests` / `data_erasure_requests` tables + the export/erasure machinery (and the actioning verbs) are Sprint 14. **Triggered by** Sprint 14. **Resolution:** land the tables + machinery; the surface + load path already exist.
  3. **The admin operational-alerts feed has no emit sites yet (D-12).** `GET /admin/alerts` is a genuine consumer (reads the admin's own `Notification` rows, user-level-above-tenancy), but the operational/admin notification EMIT sites land alongside their features, so the feed ships empty-by-default. The payment-event alert TYPES exist (drop-in) but are held back under `meta.payment_alerts` (see (1)). **Triggered by** the features that emit admin operational notifications. **Resolution:** add the emit sites; the surface lights up automatically.
  4. **Admin absolute-session cap is enforced CLIENT-side this sprint (D-11).** `useIdleTimeout` enforces the 30-min idle + 8-h absolute cap in the admin SPA; the authoritative server-side bound remains the standard session lifetime (`config('session.lifetime')`). A server-side absolute-cap (a session-stamped `started_at` checked per request) is not built. **Triggered by** a hardening pass that wants the absolute cap server-authoritative. **Resolution:** stamp + check an admin-session start time middleware-side. **Risk:** low — defence-in-depth; the idle/absolute logout is a UX + reduced-window control, not the auth boundary (impersonation TTL, by contrast, IS server-authoritative — see the review).
  5. **Per-tenant feature-flag overrides are out (D-6).** The toggle UI flips platform-level Pennant flags on/off only; per-agency scoping is not exposed. **Triggered by** a need for per-tenant rollout. **Resolution:** add a scoped-toggle surface over Pennant's scope mechanism.
- **Acceptance-bar honesty:** this sprint meets the acceptance bar for **every NON-payment admin task**. Full Sprint-13 acceptance (the payment-investigation / dispute / refund surfaces, `09-ADMIN-PANEL §6.6`) lands when S10 lights the payment panels — at which point items (1) + the payment half of (3) close by swap.
- **Owner:** Sprint 10 (payment surfaces), Sprint 14 (GDPR machinery), and a future admin-hardening pass (server-side absolute cap, per-tenant flags).
- **Status:** open (by design). Surfaced + deliberately deferred by Sprint 13, June 7, 2026 ([review](reviews/sprint-13-review.md)).

---

## Sprint 12 Chunk 1 (Board engine) — three inert-by-design seams + one schema reconciliation

- **Where:** the Boards module ([`apps/api/app/Modules/Boards`](../apps/api/app/Modules/Boards)) + the §10 board tables ([migrations `2026_06_06_120000`–`120004`](../apps/api/database/migrations)).
- **What we accepted in Sprint 12 Chunk 1 (June 6, 2026):** the board ENGINE shipped (5 tables, lazy provisioning + card-heal, the `CreateBoardCard` + `BoardAutomationListener` consumers, the manual-move dual-trail, the column/automation/move API). Four conscious deferrals/shortcuts ride it:
  1. **Intra-column card ordering (P2).** `board_cards.position` exists in the schema (§10) but is **inert in P1** — the move path never reads or writes it, cards render in a stable id order within a column. **Triggered by** the P2 chunk that adds drag-to-reorder-within-column UX. **Resolution:** honor `position` on move + add a reorder-within-column endpoint.
  2. **Inert payment-verb automation (Sprint-10-gated).** The §3.2 default `assignment.payment_released → Paid` automation is **wired but never fires** — the `CampaignAssignmentStateMachine`'s `releasePayment()` throws `escrowUnavailable()` (Stripe escrow is Sprint 10, deferred). It no-ops in practice today (D-11). **Triggered by** Sprint 10 (escrow). **Resolution:** none needed in Boards — the automation activates automatically the day the `assignment.payment_released` event begins to dispatch. Do NOT build payment here.
  3. **Time-triggered overdue events are OUT (Chunk 3).** `assignment.posting_overdue` / `assignment.draft_overdue` need a scheduler sweep + a net-new draft-deadline field + 2 net-new audit verbs — a separate vertical (D-12). A default automation MAY reference an overdue event key that doesn't emit yet; that is fine (it simply never fires until Ch3) — the same inert-wiring posture as the payment verb. **Triggered by** Sprint 12 Chunk 3. **Resolution:** build the scheduler + draft-deadline field + the two `AuditAction` verbs, then seed the overdue automations. — **✅ Resolved in Sprint 12 Chunk 3 (June 7, 2026):** `boards:scan-overdue` + `OverdueScanService` + `draft_due_at` + the `assignment.posting_overdue` / `assignment.draft_overdue` verbs ([review](reviews/sprint-12-chunk-3-review.md)). NB: the defaults still seed no overdue automation by design — a key is inert until an agency maps it.
  4. **`board_card_movements.to_column_id` nullable reconciliation (as-built).** §10 drafted `to_column_id` non-null; it shipped **nullable + SET NULL** so the in-scope column-delete-with-history flow (10-BOARD-AUTOMATION §14.3) preserves the append-only movement row instead of blocking the delete. The durable history anchor is `card_id`. **Triggered by** never (intentional). **Resolution:** none — documented in `03-DATA-MODEL.md` §10.
- **Risk:** low. (1)+(3) are missing features behind an empty-state/coming-soon surface; (2) is a no-op until escrow exists; (4) is a deliberate schema choice that strictly preserves history. No data is lost in any case.
- **Owner:** Sprint 12 Chunk 3 (overdue), Sprint 10 (payment), and the future P2 ordering chunk.
- **Status:** open (by design). Surfaced + deliberately deferred by Sprint 12 Chunk 1, June 6, 2026 ([review](reviews/sprint-12-chunk-1-review.md)).

---

## Sprint 12 Chunk 2 (Board Kanban UI) — three deferred UX affordances

- **Where:** the Boards FE module ([`apps/main/src/modules/boards`](../apps/main/src/modules/boards)).
- **What we accepted in Sprint 12 Chunk 2 (June 7, 2026):** the Kanban UI shipped (the store + 30s poll, the two DnD surfaces, column CRUD + delete-safeguard, automation config, the card drawer + movement history). Three conscious deferrals ride it:
  1. **Manual-move reason not promptable via drag (Q2).** A drag-drop sends `{ target_column_id }` only — no `reason`. The optional `reason` field on the move endpoint stays unused from the SPA so the optimistic drag flow is never interrupted by a mid-gesture prompt; the movement still records `triggered_by: user`, so the audit trail is intact. **Triggered by** a product need to capture a manual-move note. **Resolution:** add a reason affordance via a drawer control (not the drag) if ever needed.
  2. **Reduced card face (D-10).** The card tile renders only what the closed Chunk 1 `BoardCardResource` exposes (creator name, status badge, days-remaining, colour strip). §4.2's richer wants — creator avatar, platform icon, unread-message count — are **not** on the wire and the Chunk 1 Resource was **not** reopened for them. **Triggered by** a product call to enrich the tile. **Resolution:** extend `BoardCardResource` (avatar URL, platform, unread count) in a backend chunk, then render them on the face.
  3. **Intra-column ordering still inert (P2).** The card DnD only moves cards BETWEEN columns; an intra-column `moved` change is ignored (the backend `position` is inert in P1 — see the Chunk 1 entry). **Triggered by** the P2 drag-to-reorder-within-column chunk. **Resolution:** wire the `moved` event to a reorder-within-column endpoint once the backend honours `position`.
- **Risk:** low. All three are missing UX niceties over a fully-functional board; no data is lost and the trails are intact.
- **Owner:** future board UX chunks (+ Chunk 3 for the overdue events the automation config already lists inertly).
- **Status:** open (by design). Surfaced + deliberately deferred by Sprint 12 Chunk 2, June 7, 2026 ([review](reviews/sprint-12-chunk-2-review.md)). NB: the `reset-to-defaults` UI deferred from Ch2 lands as **backend** in Ch3 (the destructive re-seed endpoint) — the FE button is a future board UX chunk.

---

## Sprint 12 Chunk 3 (Overdue + scheduler + reset-to-defaults) — two deferred refinements

- **Where:** the Boards module ([`apps/api/app/Modules/Boards`](../apps/api/app/Modules/Boards)) + the Campaigns invite path.
- **What we accepted in Sprint 12 Chunk 3 (June 7, 2026):** the overdue vertical + reset shipped (the two time-triggered events via `boards:scan-overdue` → `processEvent`, the `*_overdue_flagged_at` one-shot markers, `draft_due_at`, the three `AuditAction` verbs, the destructive `reset-to-defaults`). Two conscious deferrals ride it:
  1. **`draft_due_at` FE invite-form control (D-2).** The column + the invite request-rule + the controller write ship **backend-only**; the SPA invite form has **no control to set `draft_due_at`** yet (it can be set programmatically or via the API). Nullable means `assignment.draft_overdue` is capable at ship and inert until a deadline is set. **Triggered by** agencies needing to set draft deadlines via the UI. **Resolution:** add the date control to the invite form (mirror the existing `posting_due_at` control if/when one exists).
  2. **Overdue-flag reset-on-un-overdue refinement (D-4).** The `*_overdue_flagged_at` marker is a **permanent** one-shot per assignment per overdue type — it does NOT reset if the deadline is later cleared or extended, so a re-extended-then-re-passed deadline will NOT re-fire. This is the deliberate P1 posture (a true one-shot, no daily-refire on a dragged-out card). **Triggered by** a product need for "deadline extended → overdue can fire again." **Resolution:** clear the matching marker when the deadline changes (or on un-overdue), then the next scan can re-fire.
- **As-built note (no debt, recorded for the "why"):** a **terminal** assignment (Cancelled/Paid/…) carrying a stale deadline with **no mapped overdue automation** gets flagged-once-and-does-nothing — the scan fires the event (one-shot), `processEvent` no-ops for want of a mapped automation, the marker is set, and it never fires again. This is the desired bounded behavior (no status filter by design — Q3), not a bug; the marker explains "why flagged but nothing moved."
- **Risk:** low. (1) is a missing UI nicety over a working backend field; (2) is a deliberate one-shot posture, not a defect.
- **Owner:** future board UX chunk (FE draft-deadline control); a future refinement chunk (reset-on-un-overdue) if demand emerges.
- **Status:** open (by design). Surfaced + deliberately deferred by Sprint 12 Chunk 3, June 7, 2026 ([review](reviews/sprint-12-chunk-3-review.md)).

---

## Local `php` `memory_limit` (128M) exhausts a full `vendor/bin/pest` run

- **Where:** the dev/CI PHP `memory_limit` (`128M`). A whole-suite `vendor/bin/pest` OOMs mid-run; targeted module suites pass clean with `php -d memory_limit=1G`. **Resolution:** raise `memory_limit` (e.g. `512M`/`1G`) in the test PHP config or pin `-d memory_limit=1G` in the CI test step. Surfaced Sprint 12 Chunk 1, June 6, 2026. **Status:** open (low — env-only, not a code defect).

---

## Agency-notified-on-accept/decline + a real notification subsystem (only the send-request email ships)

- **Where:** the two-sided connection lifecycle in the Agencies + Creators modules. The agency→creator **send** path notifies via [`apps/api/app/Modules/Agencies/Mail/ConnectionRequestMail.php`](../apps/api/app/Modules/Agencies/Mail/ConnectionRequestMail.php) (queued, localized). The reverse direction — the creator's **accept/decline** in [`apps/api/app/Modules/Creators/Http/Controllers/CreatorConnectionRequestController.php`](../apps/api/app/Modules/Creators/Http/Controllers/CreatorConnectionRequestController.php) — does **not** notify the requesting agency.
- **What we accepted in Sprint 6.6b (June 3, 2026):** D-9 shipped the send-request email only. **Agency-notified-on-response is deliberately deferred.** When a creator accepts (`pending_request → roster`) or declines (`pending_request → declined`), the agency is **not** pushed a notification; it learns the outcome **pull-style** via the discovery annotation — the creator's `relationship_status` flips, so the discovery card/profile re-renders "Connected" / "Declined" on next view (the D-5 three-state annotation is the feedback channel). No mailable, no in-app toast, no notification row is written on the response.
- **Risk:** low. The outcome is never lost (it's durable in `relationship_status` and visible on the discovery surface); the gap is purely **push immediacy** — an agency that doesn't revisit the discovery surface won't be actively told "creator X accepted." Acceptable at Phase-1 volumes where an agency checks discovery deliberately.
- **Triggered by:** the chunk that builds a **real notification subsystem** (in-app notification center + a `notifications` table + the push/email fan-out) — agency-notified-on-response is one of its first consumers. Until that lands, adding a one-off accept/decline mailable would be a second bespoke email path that the subsystem would immediately subsume.
- **Resolution:** when the notification subsystem ships, emit an agency-facing notification on `accept`/`decline` (and migrate the send-request email onto the same fan-out so there is one notification spine, not a scatter of bespoke mailables).
- **Owner:** the future notification-subsystem workstream.
- **Status:** in-progress (S11.0 — notification subsystem mini-sprint). Chunk 1 (June 6, 2026) built the spine the resolution depends on: the custom `notifications` table, `NotificationType` vocabulary, per-user preferences, and the `NotificationService` emit seam. Chunk 2 (June 6, 2026) built the admins+managers fan-out resolver ([`Agency::notifiableMembers()`](../apps/api/app/Modules/Agencies/Models/Agency.php)) the resolution will reuse, but the **accept/decline agency emitter itself remains deferred**: it is a bucket-c site — there is no `agency_creator_relation.accepted/.declined` verb in `AuditAction`, only the generic `.updated`, so an in-app verb cannot be added under the one-vocabulary discipline without minting a net-new audit verb first (see the new "S11.0 left four notification sites…" entry below). **Chunk 3a + 3b (June 6, 2026)** closed the **user-facing-surface** portion of the subsystem (the notification center + badge + poll in Ch3a; the per-user preferences read/write + minimal in-app prefs UI in Ch3b) — but the **accept/decline emitter itself is still bucket-c deferred** (blocked on the net-new `agency_creator_relation.accepted/.declined` verb). Surfaced + deliberately deferred by Sprint 6.6b, June 3, 2026 ([review](reviews/sprint-6-6b-review.md)).

---

## Agency-side assignment notifications target the inviting user, not an agency-wide inbox/role

- **Where:** the Sprint 9 Chunk 2 notification set — [`SendAssignmentNotifications`](../apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php) (`DraftSubmittedForReviewMail`) and [`VerifyPostedContentJob`](../apps/api/app/Modules/Campaigns/Jobs/VerifyPostedContentJob.php) (`PostVerificationFailedMail`).
- **What we accepted in Sprint 9 Chunk 2 (June 5, 2026):** agency-facing notifications (draft-submitted-for-review, verification-failed) are addressed to the assignment's `invited_by_user_id` — the single user who sent the invite — mirroring the `ConnectionRequestMail` precedent. There is no agency-wide inbox, shared mailbox, or role fan-out, so only the inviting user is notified; another manager on the same agency sees nothing.
- **Risk:** low at Phase-1 volumes (one operator per assignment is the norm). The gap is shared visibility, not lost data — the assignment status is durable and visible on the campaign detail surface.
- **Triggered by:** the real notification subsystem (same trigger as the entry above) or the first agency that needs multiple operators to see review/verification events.
- **Resolution:** fan agency-facing notifications out to an agency-wide inbox/role when the notification subsystem lands (migrate these mailables onto the same spine).
- **Owner:** the future notification-subsystem workstream.
- **Status:** ⏳ in-progress, substantially resolved (S11.0 — notification subsystem mini-sprint). Chunk 1 (June 6, 2026) built the subsystem core and wired the draft-reviewed → creator proof consumer. **Chunk 2 (June 6, 2026) applied the agency-role fan-out** ([`Agency::notifiableMembers()`](../apps/api/app/Modules/Agencies/Models/Agency.php) → admins + managers, staff excluded) to the two **in-app** agency sites it covers — `draft_submitted` and `contracted` in [`SendAssignmentNotifications`](../apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php): each agency admin/manager now gets an in-app notification, no longer just the single `invited_by_user_id`. **Two deliberate residuals:** (1) the **email stays single-inviter** (D-6 — fanning email out is a louder change the resolution did not require; in-app fans out, email does not — commented at the seam); (2) the **third agency site, `PostVerificationFailedMail`** ([`VerifyPostedContentJob`](../apps/api/app/Modules/Campaigns/Jobs/VerifyPostedContentJob.php)), is a bucket-c site still email-only (no `assignment.verification_failed` audit verb exists — see the entry below). **Chunk 3a + 3b (June 6, 2026)** added the user-facing surface (center/badge/poll + the per-user preferences read/write + in-app prefs UI) so the fanned-out `draft_submitted`/`contracted` rows are now visible AND each operator can opt out per-type — but the `PostVerificationFailedMail` site stays bucket-c email-only. Surfaced + deliberately deferred by Sprint 9 Chunk 2, June 5, 2026 ([review](reviews/sprint-9-chunk-2-review.md)).

---

## S11.0 left four notification sites email-only + the accept/decline emitter + the approve/reject banner repoint (each blocked on a net-new AuditAction verb)

- **Where:** the S11.0 Chunk 2 retrofit deliberately scoped itself to the _zero-new-vocabulary_ sites — those whose `NotificationType` is already a member or is a clean enum-add (the `AuditAction` verb already exists). The following remain **email-only / not-yet-emitted**:
  - **`PostVerificationFailedMail`** — [`VerifyPostedContentJob`](../apps/api/app/Modules/Campaigns/Jobs/VerifyPostedContentJob.php) (agency-facing; would also fan out).
  - **`ResubmitRequestedMail`** (fresh **and** in-place, 2 modes) — [`CampaignAssignmentResolutionController`](../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php) (creator-facing).
  - **`ContractAttachedMail`** — [`CampaignAssignmentContractController`](../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentContractController.php) (creator-facing).
  - **The accept/decline agency emitter** — [`CreatorConnectionRequestController::transition()`](../apps/api/app/Modules/Creators/Http/Controllers/CreatorConnectionRequestController.php) (an inline `relationship_status` flip today, no notification at all; the "Agency-notified-on-accept/decline" entry above).
  - **The `:945` approve/reject dashboard-banner repoint** — [`CreatorDashboardPage.vue`](../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue) still reads `application_status` directly even though Chunk 2 now writes `creator.approved`/`creator.rejected` rows.
- **What we accepted in Sprint 11.0 Chunk 2 (June 6, 2026):** scoping the chunk to the clean retrofit + fan-out kept it a tight backend pair. The four sites above are **bucket-c**: each needs an in-app `NotificationType` value, but the one-vocabulary discipline (every `NotificationType` value MUST be a live `AuditAction` value — enforced by [`NotificationTypeEnumTest`](../apps/api/tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php)) blocks the add because **no suitable `AuditAction` verb exists**: there is no `assignment.verification_failed` (only `assignment.live_verified`, the pass), no `contract.*` verb at all, and only the generic `agency_creator_relation.updated` for accept/decline (no `.accepted`/`.declined`). `assignment.resubmit_requested(_in_place)` _do_ exist in `AuditAction` but were deliberately curated OUT of `NotificationType` as internal transitions — un-excluding them is a reversal to weigh, not a neutral add. The **banner repoint** additionally requires a **frontend** change (held for Chunk 3).
- **Risk:** low. Every one of these events still has its existing email (or, for accept/decline, the durable `relationship_status` pull-signal); the gap is in-app immediacy + history for these specific events, plus the persistent "fake" banner. No data is lost.
- **Triggered by:** the next `AuditAction`-verb mint that naturally covers one of these (e.g. the Messaging system-message verbs, or a contract-lifecycle verb), OR a dedicated notifications cleanup pass. The banner repoint is naturally pulled in by Chunk 3 (the creator notification center) since the FE will already be reading the feed.
- **Resolution:** mint the missing `AuditAction` verb(s) under the naming convention (`<subject>.<verb>`), add the matching `NotificationType` case + the tripwire-list line, then emit in-app alongside the existing mail (the Ch1/Ch2 proof pattern — the agency sites reuse [`Agency::notifiableMembers()`](../apps/api/app/Modules/Agencies/Models/Agency.php)). Repoint the banner once Chunk 3's feed read exists.
- **Owner:** the notification-subsystem workstream (Chunk 3 / Messaging) or the relevant verb-minting sprint.
- **Status:** open. Surfaced + deliberately deferred by Sprint 11.0 Chunk 2, June 6, 2026.

---

## Deferred creator settings page (timezone correction + `theme_preference` persistence)

> **Update (2026-06-15, EU Locale Support chunk): the `preferred_language` half of this entry is being RESOLVED.** That chunk adds the persist-and-hydrate loop for UI locale — a `PATCH /api/v1/me` (+ `/admin/me`) locale-only self-update endpoint, client `localStorage` persistence, boot/login hydration (server-wins), and a `SetLocale` backend middleware — independent of a full settings page. What remains deferred under this entry is the **settings page itself** plus **`timezone` manual correction** and **`theme_preference` persistence** (the binary↔tri-state gap at the bottom of this entry is untouched). The endpoint added now is locale-only and rejects unknown fields with 422, so timezone/theme write-back is still net-new when the settings page lands.

- **Where:** there is no creator settings surface today. The fields that would live there: [`apps/api/app/Modules/Identity/Models/User.php`](../apps/api/app/Modules/Identity/Models/User.php) (`timezone`, `preferred_language`, `theme_preference`), set once at row-creation by [`apps/api/app/Modules/Identity/Services/SignUpService.php`](../apps/api/app/Modules/Identity/Services/SignUpService.php) and never updated afterward. The SPA reads them via `useAuthStore`/[`packages/api-client/src/types/user.ts`](../packages/api-client/src/types/user.ts) but has no surface to write them back.
- **What we accepted in Sprint 5 Chunk C (June 3, 2026):** Chunk C was re-scoped from "auto-detect + settings page" down to **auto-detect only** (capture the browser IANA tz at both sign-up entry points — D-c1/D-c3). The **settings page was deferred** because the Chunk-C inventory (S5/S7) found it is net-new/medium — the _first_ creator settings surface, with **no** creator settings route/page/nav item and **no** User self-update endpoint existing today — AND that `preferred_language` + `theme_preference` are _also_ client-only / never persisted after row creation (S7). A one-field tz settings page would just be torn out and rebuilt when language + theme need the same surface, so they should land together.
- **Risk:** low. Auto-detect (Chunk C) already makes the calendar correct-by-default for essentially every real creator; the missing settings page only blocks _manual correction_ of the three fields. Until it ships, a creator cannot change their timezone, UI language, or theme after sign-up.
- **Triggered by:** the first creator request to change language/theme/timezone in-app, or a future "creator account settings" chunk.
- **Resolution (the deferred chunk owns, as one surface):**
  1. The first creator settings **route + page + nav item**.
  2. A **net-new User self-update endpoint** (`users/me`-style, **own-record-only** authorization — a creator may only edit their own row).
  3. A **lean IANA timezone picker** — `v-autocomplete` over the _full_ `Intl.supportedValuesOf('timeZone')` (not a curated subset; a creator could be anywhere).
  4. **Persist `preferred_language` and `theme_preference`** through the same endpoint (today both are dropped after row creation).
- **Inventory findings (Sprint 5 follow-on pre-kickoff, 2026-06-04) — where a future build should start:**
  - **First user-self-update-of-`User` surface.** No PATCH/PUT self-update path exists today — only signup writes the four prefs, and `GET /me` is read-only ([`Identity/Routes/api.php:57`](../apps/api/app/Modules/Identity/Routes/api.php)). The endpoint is genuinely net-new; the creator wizard (`creators/me/wizard/*`) writes the **Creator** satellite, not the **User** row.
  - **The four prefs are P1 `users` columns:** `preferred_language`, `preferred_currency`, `timezone`, `theme_preference` ([`User.php:71`](../apps/api/app/Modules/Identity/Models/User.php) — `$fillable`).
  - **Normalisers need extraction, not a direct call.** `SignUpService::normaliseTimezone()` ([`SignUpService.php:299`](../apps/api/app/Modules/Identity/Services/SignUpService.php)) and `normaliseLanguage()` ([`SignUpService.php:281`](../apps/api/app/Modules/Identity/Services/SignUpService.php)) are **`private`** — reuse means lifting them to a shared helper/enum, not calling as-is.
  - **⚠ The real complexity is the persist-and-hydrate loop, not the form:**
    - **Theme** lives in `localStorage` (`catalyst.main.theme`) via [`useThemePreference.ts:66`](../apps/main/src/composables/useThemePreference.ts), applied to Vuetify through [`useTheme.ts:53`](../apps/main/src/composables/useTheme.ts) — but is **binary** client-side (`['light','dark']`, [`useThemePreference.ts:70`](../apps/main/src/composables/useThemePreference.ts)) while the **server enum + DB default are tri-state** (`light/dark/system` — [`ThemePreference.php:14`](../apps/api/app/Modules/Identity/Enums/ThemePreference.php); signup writes `System` at [`SignUpService.php:116`](../apps/api/app/Modules/Identity/Services/SignUpService.php)). That binary↔tri-state gap must be resolved (drop `system` server-side, or have the picker map it).
    - **Locale is thinner still — no client persistence, no hydration.** The switcher binds `v-model="locale"` straight onto vue-i18n ([`CreatorDashboardLayout.vue:108`](../apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue)); i18n **hard-defaults to `en`** ([`i18n/index.ts:71`](../apps/main/src/core/i18n/index.ts)) and **nothing reads `preferred_language`** to hydrate it, so the chosen language **resets on every reload**. The settings page must add both the write-back AND the on-login hydration.
- **Open decisions to settle at kickoff (NOT pre-decided):**
  1. **Endpoint placement** — `users/me/settings` (Identity; mirrors `GET /me`, which carries no `tenancy` alias and is **not** in the `tenancy.md` §4 allowlist) **vs** `creators/me/settings` (Creators; every `creators/me/*` route **is** §4-allowlisted, see [`tenancy.md`](security/tenancy.md) §4). The choice sets the allowlist cost.
  2. **Timezone on an explicit settings form: reject vs normalise.** Signup is non-rejecting by design (bad tz → UTC, never 422). On a deliberate settings picker a `422` ("not a valid timezone") is defensible; reusing the non-rejecting normaliser is also defensible. Reviewer call.
  3. **Is `preferred_currency` in scope?** No `Currency`/`CurrencyCode` enum exists; it is set from `config('app.default_currency')` at signup ([`SignUpService.php:114`](../apps/api/app/Modules/Identity/Services/SignUpService.php)) and is **not** "broken/client-only" like the other three. Include all four, or just the three that are currently un-persisted.
  4. **Dedicated `/creator/settings` route + a 3rd topbar item vs a dashboard section** (the creator topbar is a static two-item nav today).
- **Owner:** future creator-settings workstream.
- **Status:** open. Surfaced + deliberately deferred by Sprint 5 Chunk C, June 3, 2026 ([review](reviews/sprint-5-chunk-c-review.md)); pre-kickoff inventory pass added June 4, 2026 (Inventory findings above).

---

## Auto-detected timezone can capture a travel zone and cannot be corrected in-app (until the settings page ships)

- **Where:** [`apps/main/src/modules/auth/pages/SignUpPage.vue`](../apps/main/src/modules/auth/pages/SignUpPage.vue) (captures `Intl.DateTimeFormat().resolvedOptions().timeZone` at sign-up) → [`apps/api/app/Modules/Identity/Services/SignUpService.php`](../apps/api/app/Modules/Identity/Services/SignUpService.php) (`normaliseTimezone()`, persisted to `users.timezone`).
- **What we accepted in Sprint 5 Chunk C (June 3, 2026):** Chunk C captures the _browser's_ zone at registration. A creator who signs up (or accepts an invite) **while traveling** captures the travel zone, not their home zone. Because the creator settings page is deferred (entry above), there is currently **no in-app way to correct a wrong auto-detected zone**.
- **Risk:** low and rare. This is strictly better than the prior always-`'UTC'` behaviour (the calendar now renders in a real zone for the overwhelming majority who sign up at home), and the failure mode is "wrong-but-real zone for a traveling minority," not a broken calendar.
- **Triggered by:** the deferred creator settings page — its IANA picker is exactly the correction mechanism this limitation needs.
- **Resolution:** ship the creator settings page (entry above); manual tz correction resolves this automatically.
- **Owner:** future creator-settings workstream.
- **Status:** open (accepted limit, named honestly). Surfaced by Sprint 5 Chunk C, June 3, 2026 ([review](reviews/sprint-5-chunk-c-review.md)).

---

## Postgres FTS name/bio search on the creator roster (spec'd `tsvector`, not built)

- **Where:** spec at [`docs/03-DATA-MODEL.md:219`](03-DATA-MODEL.md) ("Postgres full-text index on `display_name`, `bio` — combined `tsvector` column"). No migration builds it; confirmed by the Sprint 4 Chunk 5 pre-kickoff inventory. The roster list that would consume it is [`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`](../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php).
- **What we accepted in Sprint 4 Chunk 5 (June 3, 2026):** D-c5-2 deferred FTS name/bio search to Sprint 6 (Internal creator matching). The roster ships with the four filters that have real backing today (status / country / language / category); name/bio search needs net-new infrastructure — a generated `tsvector` column + GIN index, **a SQLite-divergence guard** (the GIN/`to_tsvector` path is Postgres-only; the local + CI test DB is SQLite `:memory:`, which has no `tsvector`), and a query helper — that belongs with Sprint 6's matching engine, not bolted onto a list chunk.
- **Risk:** low. The list is useful and complete without free-text search at Phase-1 roster volumes; the four structured filters narrow effectively. The cost is purely a missing convenience until Sprint 6.
- **SQLite-divergence note:** unlike the `categories` filter — which uses `whereJsonContains`, and degrades gracefully (Postgres `@>`/GIN vs SQLite `json_each`, both supported by the query grammar) — there is **no portable `tsvector` equivalent on SQLite**. Sprint 6 must either guard the FTS path by driver (Postgres-only, with a `LIKE`/`ILIKE` fallback on SQLite) or accept that the FTS branch is exercised only in Postgres CI.
- **Triggered by:** Sprint 6 (Internal creator matching) — the natural home for search + the talent-pool/metrics/availability filters deferred alongside it.
- **Resolution:** in Sprint 6, add the generated `tsvector` column + GIN index (pgsql-guarded migration), a driver-aware query helper (FTS on Postgres, `ILIKE` fallback on SQLite), and wire a `?q=` param into the roster/matching endpoint.
- **Owner:** Sprint 6 matching workstream.
- **Status:** **CLOSED** — Sprint 6 Chunk 1 (2026-06-03, D-1/D-3, [review](reviews/sprint-6-chunk-1-review.md)). Built exactly as the resolution sketched, scoped to **name/bio** (handle search is a separate deferred entry below):
  - Migration `2026_06_03_100001_add_search_vector_to_creators_table.php` adds a **pgsql-guarded** STORED generated column `search_vector = to_tsvector('simple', display_name || bio)` + `idx_creators_search_gin` GIN index. The entire `ALTER`/index lives behind `getDriverName() === 'pgsql'`, mirroring the `idx_creators_categories_gin` block — so the column **does not exist on SQLite** and the test schema is untouched.
  - `AgencyCreatorController::applySearchFilter` is **driver-aware** (the one filter that needs an explicit branch — FTS has no portable grammar degrade): Postgres `search_vector @@ plainto_tsquery('simple', ?)`; SQLite `LOWER(display_name|bio) LIKE ? ESCAPE '\'`. `?q=` is threaded controller → `roster.api.ts` (`RosterListParams.q`) → the page's debounced search box.
  - **Untestable-seam discipline (D-3):** the SQLite `ILIKE` fallback is the CI-exercised path and is fully tested (narrows by name + bio; unmatched→0 break-revert; blank no-op; wildcard escaping). The Postgres FTS branch ships with a dormant `markTestSkipped()` counterpart (live the day Postgres CI lands) **and** was manually verified against the live local Postgres 16 (port 5435) on a throwaway `catalyst_test` DB — all 24 roster tests green incl. the otherwise-skipped FTS assertion (recorded in the chunk review). The `'simple'` config minimizes the FTS-lexeme vs ILIKE-substring divergence; the residual difference is documented, not papered over.
- **Follow-up — FTS/filter logic is now SHARED (Sprint 6.6a, 2026-06-03, D-3, [review](reviews/sprint-6-6a-review.md)):** the Sprint 6.6a discovery read-path needed the **same** name/bio FTS + country/language/category filters against the global `creators` pool. Rather than copy the driver-aware branch (a copy would drift, and the Postgres path is the untestable seam above), `applySearchFilter` + `applyCreatorFilters` were **extracted from `AgencyCreatorController` into a shared trait** [`App\Modules\Agencies\Concerns\FiltersCreatorColumns`](../apps/api/app/Modules/Agencies/Concerns/FiltersCreatorColumns.php), now `use`d by both the roster controller and the new `AgencyCreatorDiscoveryController`. The extraction is **behavior-preserving** (the roster controller's existing tests stayed green — the safety net) and keeps the FTS branch **single-source**. The trait methods are `@template`d on the model so they accept any `Builder<TModel>` (the roster's relation builder + discovery's `Builder<Creator>`). The **availability filter was NOT extracted** — it's relation-coupled (plucks relation `creator_id`s) and has no meaning against the global pool.

---

## A real availability filter on the agency roster (the cheap-signal design problem)

- **Where:** [`apps/main/src/modules/roster/pages/CreatorRosterPage.vue`](../apps/main/src/modules/roster/pages/CreatorRosterPage.vue) ships availability as a **disabled affordance** (D-4); [`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`](../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php) has no availability filter. Availability data lives in `creator_availability_blocks` as **per-creator RRULE recurrence**, not a stored status.
- **What we accepted in Sprint 6 Chunk 1 (June 3, 2026):** D-5 — a _real_ availability filter is **deferred as a design problem, not just effort.** The Chunk-1 inventory reframed the scope: availability was assumed to be a stored field that a filter could read, but there is **no stored availability status**. Answering "is this creator free in window X?" for a roster page means expanding each creator's RRULE set (N expansions per page) — a second spine of query weight, not an extension of the FTS chunk. So availability ships as an inert affordance (faded, span-wrapped tooltip "Availability filtering is coming soon", **issues no query** — a 0-results from an empty-data query reads as broken), exactly like the metrics affordance.
- **Risk:** none today (the affordance is honest about being unbuilt). The cost is a missing filter until the design decision is made.
- **Triggered by:** an agency workflow that needs to filter the roster by who's free — likely the campaign-matching work that consumes availability.
- **Resolution (two options sketched at deferral, to be chosen deliberately):**
  - **(a) Denormalized roster-wide signal:** maintain a cheap per-creator column (e.g. `next_free_date` / a coarse availability bucket) updated when availability blocks change, so the roster filter is a plain indexed `WHERE`. Cheap to query, but adds a denormalization to keep in sync (write-time cost + a backfill).
  - **(b) Accept N RRULE expansions per page:** expand each rostered creator's recurrence at query time for the requested window. No denormalization, but O(roster-size) expansion per page load — needs a bound (page size cap) and likely a cache.
  - Pick one consciously after measuring expected roster sizes + the query window's shape; do not bolt either onto a list/search chunk.
- **Owner:** the chunk that first needs availability-based roster filtering.
- **Status:** **CLOSED** — Sprint 6.5 (2026-06-03, D-1…D-6, [review](reviews/sprint-6-5-review.md)). The Sprint-6.5 read-pass reframed the choice: the question isn't (a) vs (b) but **what the filter MEANS** — there is no "available now" status to denormalize; availability is "no overlapping hard block in a date range [from, to]" (the staffing question). That ruled out the simple scalar of (a) (a range query can't read a single `next_free_date`), and (b)'s naive per-page expansion would desync pagination counts. Built instead as **approach A (per-page/whole-filtered-set expansion with CORRECT counts), hard-only**:
  - A batch `AvailabilityExpansionService::expandMany()` (the existing per-creator `expand()` refactored to share an `assemble()` path — batch == loop by construction, not a reimplementation; pinned by `AvailabilityExpandManyTest`) loads all creators' one-off + recurring blocks in **2 queries**, expands per creator.
  - `AgencyCreatorController::index` expands the **filtered** relation set, collects the busy (overlapping-hard) creator ids, and applies them as a `whereNotIn` **before** `->paginate()` — so the availability exclusion becomes a real SQL predicate once PHP knows the busy set, keeping `meta.total`/`last_page`/page contents correct (no filter-within-page). Soft blocks never exclude (mirrors `AvailabilityConflictService`). Window is day-granular + inclusive of the `to` day, clamped to 366 days.
  - FE: the disabled affordance flipped to two native `type="date"` inputs threaded through `RosterListParams` → `loadRoster()` → `roster.api.ts` (both-required; one-sided range sends no param).
  - The whole-filtered-set expansion is **scale-bounded** — see the new entry directly below for the promote-to-C trigger.

---

## Availability roster filter is scale-bounded — promote to a materialized busy-intervals table (approach C) when rosters grow

- **Where:** [`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`](../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php) `applyAvailabilityFilter()` + [`apps/api/app/Modules/Creators/Services/Availability/AvailabilityExpansionService.php`](../apps/api/app/Modules/Creators/Services/Availability/AvailabilityExpansionService.php) `expandMany()`.
- **What we accepted in Sprint 6.5 (June 3, 2026):** D-5 — the real availability filter (entry directly above) is built with **filter-before-pagination + correct counts (approach A)**: to know who's available _before_ counting/slicing, the controller expands **every creator in the FILTERED relation set** (not just the 25 on the page) over the requested window. At Phase-1 roster volumes this is fine and deliberately right-sized — the work is **bounded** on two axes: the filtered set is one agency's roster (the agency-scoped relation set, not the global creators table), and the per-creator recurrence expansion is bounded by the 366-day window clamp (`MAX_WINDOW_DAYS`). The query cost is flat in page count (~1 pluck + 2 block queries + the 2 paginate queries), not O(creators × pages). **But it does not scale indefinitely:** the whole-filtered-set expansion grows with a single agency's roster size, and a very large roster (or a hot filter) turns the per-request PHP expansion into a measurable cost.
- **Risk:** none today (Phase-1 rosters are small + bounded). The cost materializes only when an agency's roster grows large or this filter becomes hot.
- **Honest-deviation guard already in place:** the build flagged that if the _filtered relation set_ were ever unbounded in some query path, the expansion must NOT ship — it would be silently unbounded. It is bounded (per-agency roster + window clamp), so this is a **scale ceiling, not an unbounded vector**. The ceiling is named here rather than discovered in production.
- **Triggered by (the promote-to-C trigger):** **rosters exceed a meaningful volume** (a single agency carrying many thousands of relations) **OR** this roster query shows up in **slow-query logs** / the matching-engine performance pass flags it.
- **Resolution (approach C — deliberately deferred, needs net-new scheduler infra):** maintain a **materialized busy-intervals table** (`creator_busy_intervals`: `creator_id`, `starts_at`, `ends_at`, hard-only) populated by a **scheduled expansion job** that pre-expands each creator's RRULE set over a rolling horizon (and re-runs on availability-block writes). The roster filter then becomes a plain indexed `WHERE NOT EXISTS (busy interval overlapping [from, to])` — a real SQL predicate that joins the paginated query directly, eliminating the per-request PHP expansion entirely. The same `AvailabilityExpansionService` feeds the job, so the expansion logic stays single-source. C was not built now because it needs a scheduler + a backfill + an invalidation path — infrastructure for a scale problem we don't have yet (same discipline as the FTS untestable-seam: right-sized now, ceiling named, upgrade triggered).
- **Owner:** the matching-engine performance pass / the first agency whose roster filter shows in slow-query logs.
- **Status:** open. Surfaced + deliberately deferred by Sprint 6.5, June 3, 2026 ([review](reviews/sprint-6-5-review.md)).

---

## Handle search on the agency roster (`creator_social_accounts.handle`)

- **Where:** the roster `?q=` FTS (Sprint 6 Chunk 1) searches **name/bio only** — the spec'd `tsvector` is over `creators(display_name, bio)`. The handle lives on a different table, [`creator_social_accounts.handle`](../docs/03-DATA-MODEL.md), one row per (creator, platform).
- **What we accepted in Sprint 6 Chunk 1 (June 3, 2026):** D-2 — handle search is **deferred**. It's outside the spec'd name/bio `tsvector` and needs a join to `creator_social_accounts` + a second search path (a creator has multiple handles across platforms) — a scope multiplier on a chunk whose one real spine was FTS. Name/bio is the spec'd surface; handles become rich (verified, metric-bearing) only when the social adapters land, which is the natural home for searching them.
- **Risk:** low — agencies search their roster by name far more than by raw handle at Phase-1 volume; the four structured filters + name/bio FTS cover the common cases.
- **Triggered by:** the social-adapter work (when handles become first-class, synced, metric-bearing) OR an explicit agency need to find a creator by @handle.
- **Resolution:** extend the roster search to also match `creator_social_accounts.handle` — either via a `whereHas('socialAccounts', …)` `ILIKE`/FTS branch joined into the existing `?q=` path, or a dedicated handle index once handles are normalized + deduped by the adapters. Mind the driver-aware seam already established for the name/bio FTS.
- **Owner:** social-adapter workstream.
- **Status:** open. Surfaced + deliberately deferred by Sprint 6 Chunk 1, June 3, 2026 ([review](reviews/sprint-6-chunk-1-review.md)).

---

## Unindexed roster filters: `agency_creator_relations.relationship_status` + `creators.primary_language`

- **Where:** [`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`](../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php) — the `?status=` and `?language=` filters. Schema: [`2026_05_14_100007_create_agency_creator_relations_table.php`](../apps/api/database/migrations/2026_05_14_100007_create_agency_creator_relations_table.php) (no index on `relationship_status`) and [`2026_05_14_100000_create_creators_table.php`](../apps/api/database/migrations/2026_05_14_100000_create_creators_table.php) (no index on `primary_language`; `country_code` and `categories` ARE indexed — `idx_creators_country_code`, GIN `idx_creators_categories_gin`).
- **What we accepted in Sprint 4 Chunk 5 (June 3, 2026):** D-c5-1 shipped these two filters without adding indexes. Both run **behind the agency-scoped relation set** — the query is already constrained to one agency's `agency_creator_relations` rows (tenancy scope + the belt-and-suspenders `where('agency_id', …)`), so the filtered cardinality is the agency's roster size, not the global table. At Phase-1 volumes a sequential scan over one agency's relations is negligible.
- **Risk:** low at Phase-1 volume; grows only if a single agency's roster becomes very large (thousands+).
- **Triggered by:** Sprint 6's matching engine (which will profile roster queries under realistic volume) OR the first agency whose roster query shows up in slow-query logs.
- **Resolution:** add a B-tree index on `agency_creator_relations(agency_id, relationship_status)` and, if `primary_language` filtering proves hot, `creators(primary_language)`. Defer until a measured need exists.
- **Owner:** Sprint 6 matching workstream / performance pass.
- **Status:** open. Surfaced + deliberately deferred by Sprint 4 Chunk 5, June 3, 2026 ([review](reviews/sprint-4-chunk-5-review.md)).

---

## Heavy Vuetify components (`VSelect` / `VDataTableServer`) leak across jsdom mounts — component-spec stub pattern

- **Where:** [`apps/main/src/modules/roster/pages/CreatorRosterPage.spec.ts`](../apps/main/src/modules/roster/pages/CreatorRosterPage.spec.ts) (the pattern's first use), against [`CreatorRosterPage.vue`](../apps/main/src/modules/roster/pages/CreatorRosterPage.vue). Contrast: [`BrandListPage.spec.ts`](../apps/main/src/modules/brands/pages/BrandListPage.spec.ts) survives 7 full mounts only because it has **no** `v-select`.
- **What we accepted in Sprint 4 Chunk 5 (June 3, 2026):** Vuetify's `VSelect` (its `VOverlay`/`VMenu` internals teleport to `<body>` and are not reclaimed on unmount) and `VDataTableServer` (retains a large tree) **leak across jsdom mounts** and OOM the Vitest worker at ~3–4 full renders. So `CreatorRosterPage.spec` renders the **real** data-table in **exactly one** row-DOM test (the load-bearing D-c5-4 non-navigating-row assertion hits real DOM there) and **stubs** `VDataTableServer` in the other four cases, which drive the filter refs directly + assert against the mocked API. `VSelect` is always stubbed; the read-only rating is rendered as static star `v-icon`s rather than `v-rating` (another jsdom memory hog).
- **Risk:** **none today** — coverage for the roster surface is intact (logic, filters, empty/error states via refs + mock; row DOM + no-navigation via the one real mount). The cost is a test-ergonomics constraint, not a coverage gap.
- **Triggered by:** the **next rich agency-SPA list** — **Sprint 6's matching / roster-management view**, which will carry _more_ selects + filters (talent pools, metrics/availability, FTS search box) and so hits the same wall harder. The stub-the-heavy-components pattern scales poorly as a single page accrues many heavy controls.
- **Resolution (Sprint 6 decides deliberately):** either (a) keep + formalize the "render one real table, stub the rest" pattern (cheap, fast, but table-DOM coverage stays thin), or (b) stand up **Playwright** table-DOM coverage for the matching view and let component specs stub freely. Pick one consciously rather than discovering the OOM mid-build.
- **Owner:** Sprint 6 matching workstream.
- **Status:** **CLOSED** — Sprint 6 Chunk 1 (2026-06-03, D-6, [review](reviews/sprint-6-chunk-1-review.md)) chose **option (b)** deliberately. The inventory corrected the assumption that Playwright was greenfield — it's already stood up + running in the `e2e-main` CI job (10 specs), so adding a roster spec is cheap. `playwright/specs/roster-search-and-affordances.spec.ts` covers the **real** `v-data-table-server` DOM + the search-narrows-the-table flow + the disabled-affordance tooltips against actual seeded rows (via a new `_test/agencies/{agency}/roster-creators` helper). The `CreatorRosterPage.spec.ts` now stubs the heavy components freely (`VSelect`, `VDataTableServer`, `VTextField`) and drives refs directly — the OOM-prone "render one real table" mount is retained for the existing row-DOM test but the chunk did **not** push the workaround further as this entry feared. The pattern is now: heavy table/search/filter DOM → Playwright; logic/filter/empty/error → stubbed component specs.

---

## ⚠️ Deferred `creators.signed_master_contract_id` → `contracts.id` FK (column is temporarily MULTI-MEANING)

- **Where:** [`apps/api/database/migrations/2026_05_14_100000_create_creators_table.php`](../apps/api/database/migrations/2026_05_14_100000_create_creators_table.php) (the FK-less column) + the three writers of `creators.signed_master_contract_id`:
  - [`apps/api/app/Modules/Creators/Services/CreatorWizardService.php`](../apps/api/app/Modules/Creators/Services/CreatorWizardService.php) `acceptClickThroughContract()` — writes a **real `contracts.id`** (Sprint 4 Chunk 4, the correct semantic).
  - [`apps/api/app/Modules/Creators/Jobs/ProcessEsignWebhookJob.php:111`](../apps/api/app/Modules/Creators/Jobs/ProcessEsignWebhookJob.php) — writes an **`integration_events.id` sentinel** (NOT a contracts.id).
  - [`apps/api/app/Modules/Creators/Services/WizardCompletionService.php:133`](../apps/api/app/Modules/Creators/Services/WizardCompletionService.php) — writes a **`now()->timestamp` unix-timestamp sentinel** (NOT a contracts.id).
- **What we accepted in Sprint 4 Chunk 4 (June 2, 2026):** D-c4-1 called for adding the spec'd `creators.signed_master_contract_id` → `contracts.id` FK constraint now that the `contracts` table exists. We **deferred the DB-level constraint** because the column is currently **multi-meaning** — it holds three structurally incompatible kinds of value depending on which path wrote it (a real `contracts.id`, an `integration_events.id`, and a unix timestamp). Adding a hard FK today would immediately violate on the two vendor sentinel paths and break their tests. The click-through path (this chunk) is correct: it writes a genuine `contracts.id` and the Eloquent `Creator::masterContract()` relation resolves it.
- **Risk:** a latent data-integrity landmine. Until unified, `signed_master_contract_id` cannot be trusted as a real FK — `Creator::masterContract()` resolves to `null` for the two sentinel paths (the sentinel value is not a real `contracts.id`), and any future code that JOINs on this column will silently mis-resolve. No FK protects against orphaned/garbage values today.
- **Mitigation today:** step-8 satisfaction (`CompletenessScoreCalculator`) only checks `signed_master_contract_id IS NOT NULL` (presence, not validity), which all three writers satisfy, so the wizard behaves correctly. The multi-meaning is invisible to the wizard but real at the data layer.
- **Triggered by:** the **e-sign vendor adapter chunk** (the next Sprint-4 e-sign workstream). It MUST NOT be missed there.
- **Resolution (the vendor chunk owns this, in order):**
  1. Convert `ProcessEsignWebhookJob` to create a real `contracts` row (vendor envelope: `signature_provider=docusign|dropboxsign`, `signature_envelope_id`, `sent_at`, `status=signed`) and set `signed_master_contract_id` to that row's id — replacing the `integration_events.id` sentinel.
  2. Convert `WizardCompletionService::contractReturn()` likewise — replacing the `now()->timestamp` sentinel with a real `contracts.id`.
  3. Backfill any pre-existing sentinel rows to real `contracts` rows.
  4. **Only then** add the DB-level FK constraint (`signed_master_contract_id` → `contracts.id`, `nullOnDelete`) in a migration. Add a `Sprint4MigrationTest`-style assertion that the constraint exists.
- **Owner:** the e-sign vendor adapter chunk (Sprint 4 e-sign workstream / spec-native S9).
- **Status:** open. Surfaced + deliberately deferred by Sprint 4 Chunk 4, June 2, 2026 ([review](reviews/sprint-4-chunk-4-review.md)).

---

## Read-receipts ("which sections were viewed") for contract acceptance

- **Where:** the contract step — [`apps/api/app/Modules/Creators/Services/ContractTermsRenderer.php`](../apps/api/app/Modules/Creators/Services/ContractTermsRenderer.php) (terms render) + the click-through accept path. Spec reference: [`docs/20-PHASE-1-SPEC.md`](20-PHASE-1-SPEC.md) Step 8 (`:420` — "which sections were viewed").
- **What we accepted in Sprint 4 Chunk 4 (June 2, 2026):** D-c4-7 scoped read-receipts OUT. The spec's "which sections were viewed" is **viewing telemetry**, not acceptance evidence — it is not part of the `contracts` shape (`03-DATA-MODEL.md §8`), and conflating it with the acceptance record would muddy what `signed_signature_data` means (binding-signature evidence: method + IP/UA + timestamp). The structured acceptance record (this chunk) is complete without it.
- **Risk:** low. Read-receipts are a nice-to-have evidentiary enhancement (proof the creator scrolled through each clause), not a correctness or compliance blocker for the click-through binding signature, which the master agreement §10 already establishes as legally effective.
- **Triggered by:** the e-sign vendor adapter chunk (the natural home — vendors capture per-section view events), OR a compliance-hardening pass.
- **Resolution:** capture per-section view telemetry (likely a separate `contract_view_events` table or a vendor-supplied field), kept distinct from `signed_signature_data`. Defer to the vendor chunk.
- **Owner:** e-sign vendor adapter chunk.
- **Status:** open. Surfaced + deferred by Sprint 4 Chunk 4, June 2, 2026.

---

## `creators.click_through_accepted_at` is now denormalized (eventual removal)

- **Where:** [`apps/api/database/migrations/2026_05_15_100002_add_click_through_accepted_at_to_creators_table.php`](../apps/api/database/migrations/2026_05_15_100002_add_click_through_accepted_at_to_creators_table.php) (the column) + readers: [`CreatorResource`](../apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php) (surfaces it), [`CreatorWizardService::acceptClickThroughContract()`](../apps/api/app/Modules/Creators/Services/CreatorWizardService.php) (idempotency guard keys off it).
- **What we accepted in Sprint 4 Chunk 4 (June 2, 2026):** D-c4-3 made the `contracts` row the source of truth for "contract step satisfied" (step-8 now keys off `signed_master_contract_id`, no longer off `click_through_accepted_at`). We **kept `click_through_accepted_at` set** as a denormalized convenience rather than deprecating it — low-risk, avoids touching unrelated reads (`CreatorResource` still exposes it; the accept idempotency guard still uses it). The acceptance timestamp is now redundant with `contracts.signed_at`.
- **Risk:** minor denormalization drift potential — two sources for the same timestamp (`creators.click_through_accepted_at` and `contracts.signed_at`). They are written in the same transaction so cannot diverge today, but a future writer that updates one without the other would.
- **Triggered by:** a schema-cleanup pass once the deferred FK lands and `masterContract()` is a reliable join (then `click_through_accepted_at` can be derived from `masterContract.signed_at`).
- **Resolution:** repoint `CreatorResource` + the accept idempotency guard at `masterContract()->signed_at` / `signed_master_contract_id`, then drop the column in a migration. Estimated ~30 minutes.
- **Owner:** the schema-cleanup pass following the deferred-FK resolution (above).
- **Status:** open. Surfaced by Sprint 4 Chunk 4, June 2, 2026.

---

## Full API test suite needs `memory_limit=2G` (unrelated big-CSV test OOMs at 128M)

- **Where:** [`apps/api/tests/Feature/Modules/Creators/BulkInviteCsvParserTest.php`](../apps/api/tests/Feature/Modules/Creators/BulkInviteCsvParserTest.php) ("rejects a CSV exceeding the 5MB hard cap" builds a 300k-row CSV in memory).
- **What we accepted (June 2, 2026, noted during Sprint 4 Chunk 4):** running the **entire** suite via `php artisan test` at PHP's default 128M `memory_limit` OOMs partway through (cumulative across the run, surfacing at the big-CSV test). The suite is green only at a raised limit: `php -d memory_limit=2G vendor/bin/pest`. Separately, `php artisan test --parallel` trips a pre-existing `use RuntimeException` non-compound-name warning in `Sprint3Chunk2InvariantsTest.php`. Neither is related to any feature code — they're test-harness ergonomics.
- **Risk:** low, but a footgun: "all green" silently depends on remembering the memory flag. A contributor running the documented default command sees a scary FatalException unrelated to their change.
- **Mitigation today:** scoped runs (per-module/per-dir) stay under 128M; the raised-limit full run is documented in chunk reviews.
- **Triggered by:** a CI/test-harness tidy-up, OR the next chunk that touches the bulk-invite CSV test.
- **Resolution:** either set `memory_limit=2G` in the test bootstrap / `phpunit.xml` (`<ini>`), or rewrite the big-CSV assertion to stream/stub the size check instead of materialising 300k rows; and fix the `Sprint3Chunk2InvariantsTest` import so `--parallel` is clean. Estimated ~20 minutes.
- **Owner:** test-harness tidy-up / next bulk-invite-touching chunk.
- **Status:** open. Surfaced by Sprint 4 Chunk 4, June 2, 2026.

---

## Creators feature dir OOMs at 128M in one process (unrelated Stripe-mock test)

- **Where:** running the whole [`apps/api/tests/Feature/Modules/Creators`](../apps/api/tests/Feature/Modules/Creators) directory in a single process hits PHP's default 128M `memory_limit` with a fatal inside an unrelated Stripe-mock test; it passes with `php -d memory_limit=512M`.
- **What we accepted (June 5, 2026, noted during the contract-gate-decouple chunk):** this is a _narrower_ scope than the full-suite 2G entry above — it shows the existing entry's "scoped runs (per-module/per-dir) stay under 128M" mitigation is **no longer true for the Creators dir specifically**. The whole-dir run now needs `-d memory_limit=512M`. Unrelated to any feature code in this chunk — surfaced only because this chunk's verification ran the whole Creators dir at once.
- **Risk:** low, but a latent CI hazard: a job that runs `tests/Feature/Modules/Creators` as one process at the default limit will see a FatalException unrelated to the change under test. Combined with the 2G full-suite entry, "all green" silently depends on remembering a memory flag at multiple granularities.
- **Mitigation today:** run the Creators dir with `php -d memory_limit=512M` (or finer-grained per-file runs).
- **Triggered by:** the same test-harness tidy-up as the 2G entry — ideally fixed together by setting `memory_limit` in `phpunit.xml` `<ini>` and/or making the offending Stripe-mock test release memory between cases.
- **Resolution:** fold into the test-harness `memory_limit` fix (set the limit in bootstrap/`phpunit.xml`), or identify and tighten the leaking Stripe-mock test so the Creators dir runs clean at 128M. Estimated ~15 minutes (on top of the 2G entry's fix).
- **Owner:** test-harness tidy-up.
- **Status:** open. Surfaced by the contract-gate-decouple chunk, June 5, 2026.

---

## Defensive `requireAgencyUser` guard for agency-shell routes

- **Where:** [`apps/main/src/core/router/guards.ts`](../apps/main/src/core/router/guards.ts) (new guard, symmetric to the existing `requireOnboardingAccess`) + the `meta.guards` arrays on every `appRoutes` entry in [`apps/main/src/modules/auth/routes.ts`](../apps/main/src/modules/auth/routes.ts) (`app.dashboard`, `brands.*`, `agency-users.list`, `creator-invitations.bulk`, `settings`).
- **What we accepted in Sprint 3 stabilization (May 18, 2026):** `SignInPage.vue` and `VerifyTotpPage.vue` were patched to dispatch post-login by `user.attributes.user_type` — creators land on `/onboarding`, every other user_type lands on `/`. That closes the bulk-invite → magic-link → sign-up → sign-in → wrong-layout misroute the user actually hit. It does NOT close the deeper case: a creator who knows the URLs (or whose browser autocompletes `/brands` from history) and manually navigates to an agency surface will still render the agency shell behind `requireAuth` alone, because `requireAuth` does not inspect `user_type`. The asymmetric companion guard `requireOnboardingAccess` already bounces non-creators OUT of wizard routes (`apps/main/src/core/router/guards.ts § 181-211`); the inverse — bouncing creators OUT of agency routes — is absent.
- **Risk:** non-functional surface confusion. A creator can render `BrandListPage`, `AgencyUsersPage`, `BulkInvitePage`, `SettingsPage`, and `DashboardPlaceholderPage` if they navigate by URL — none of these have backend permissions that grant a creator any state-changing affordance (the backend's `tenant-required` middleware + role checks fail every mutation), so the worst case is a visually broken page (empty tables, 403 banners on actions, no membership context). It is NOT a security gap — the backend is the SOT for authorization. It IS a polish gap that breaks the user's mental model of where they live in the app.
- **Mitigation today:** post-login dispatch is now correct for the canonical entry path (sign-in → home). Creators arriving on agency URLs by other means (bookmark, history, copy-paste from a teammate's URL) still see the wrong shell.
- **Triggered by:** the next router-touching chunk OR a deliberate UX-polish sweep across both SPAs.
- **Resolution:** add `requireAgencyUser` guard (symmetric to `requireOnboardingAccess`) that redirects `user_type === 'creator'` → `{ name: 'onboarding.welcome-back' }` and falls through for every other user_type. Apply to every `appRoutes` entry. Add the architecture-test counterpart that walks `appRoutes` and asserts every entry carries the guard. Estimated effort: ~30 minutes including tests + the parity architecture-test.
- **Owner:** next router-touching chunk OR Sprint 4 polish.
- **Status:** **CLOSED** — Sprint 6 Chunk 1 (2026-06-03, D-7, [review](reviews/sprint-6-chunk-1-review.md)). `requireAgencyUser` added to `guards.ts` (+ `GuardName` union + registry), redirecting `user_type === 'creator'` → `onboarding.welcome-back` and falling through for every other type. Wired **second** in the chain (`requireAuth → requireAgencyUser → [requireMfaEnrolled → requireAgencyAdmin]`) so a creator is bounced before the MFA/admin checks. **Scope refinement vs the original "every `appRoutes` entry"**: applied to every **agency-shell** route (`layout: 'agency'` — the 9 entries: dashboard, roster, brands.{list,create,detail,edit}, agency-users, creator-invitations.bulk, settings). The one `appRoutes` exception is `accept-invitation` — a public pre-auth landing (`layout: 'auth'`, no `requireAuth`) where the guard cannot run; it's documented inline + asserted excluded. The route-walking arch-test `agency-routes-agency-user-guard.spec.ts` pins (1) the full agency-shell set, (2) every such route carries the guard after `requireAuth`, (3) `accept-invitation` does NOT. Guard unit tests cover the creator redirect + each non-creator fall-through + defensive no-user + registry. The pre-existing MFA arch-test's exact-order assertion was updated for the inserted guard.

---

## Audit remaining auth-flow pages for per-field 422 rendering parity

- **Where:** the deferred surfaces from the Sprint 3 stabilization audit (May 18, 2026) — [`apps/main/src/modules/auth/pages/ForgotPasswordPage.vue`](../apps/main/src/modules/auth/pages/ForgotPasswordPage.vue), [`EnableTotpPage.vue`](../apps/main/src/modules/auth/pages/EnableTotpPage.vue), [`DisableTotpPage.vue`](../apps/main/src/modules/auth/pages/DisableTotpPage.vue), [`VerifyTotpPage.vue`](../apps/main/src/modules/auth/pages/VerifyTotpPage.vue) plus the three admin SPA mirrors under [`apps/admin/src/modules/auth/pages/`](../apps/admin/src/modules/auth/pages/) (`EnableTotpPage.vue`, `DisableTotpPage.vue`, `VerifyTotpPage.vue`). The reference pattern is [`BrandCreatePage.vue`](../apps/main/src/modules/brands/pages/BrandCreatePage.vue) + the now-fixed `SignUpPage` / `ResetPasswordPage` / `InviteUserModal` ([Sprint 3 Chunk 5](reviews/sprint-3-chunk-5-review.md) introduced `extractFieldErrors` from [`@catalyst/api-client`](../packages/api-client/src/errors.ts) and the stabilization commit landed the auth-page parity).
- **What we accepted in Sprint 3 stabilization (May 18, 2026):** three high-yield pages were brought to parity (SignUp, ResetPassword, InviteUserModal — each binds 422 `details[]` entries via `extractFieldErrors` to per-input `error-messages`). The remaining auth pages still funnel every error through `resolveErrorMessage` only, which renders the generic "Something went wrong" banner whenever the backend ships `code: validation.failed` (the canonical envelope code from [`ValidationExceptionRenderer`](../apps/api/app/Core/Errors/ValidationExceptionRenderer.php) — by design non-fingerprinting per the chunk-4 standard 5.4, so the per-rule message stays trapped in `details[]`).
- **Risk:** lower than the SignUp surface — every remaining page is single-input (`email` on Forgot, `code` on the TOTP triplet) or carries a `current_password` field whose validation almost always returns a semantic top-level code (`auth.password.invalid`) that the resolver already handles. The realistic gap is: invalid-format errors (e.g. malformed email on Forgot, 5-digit TOTP code) render as the generic banner instead of the specific reason. SignIn pages (main + admin) are intentionally excluded — login errors are deliberately non-fingerprinting per chunk-6 (Bug A) and a per-field surface would leak whether the email exists.
- **Mitigation today:** the deferred pages already render _something_ (the generic banner) and the backend's `auth.*` semantic codes that cover the common failure modes (account locked, MFA required, password compromised, TOTP code invalid) flow through the resolver correctly. Only the rarer "Laravel emitted a `validation.failed` for this field" path is degraded.
- **Triggered by:** any chunk that touches the deferred page UX, OR a follow-up stabilization pass that wants surface parity for symmetry / discoverability reasons.
- **Resolution:** mechanical replication of the three-step pattern landed in the stabilization commit — (1) import `ApiError, extractFieldErrors` from `@catalyst/api-client`; (2) add `fieldErrors` ref typed to the page's field union; (3) bind `:error-messages="fieldErrors.<field>"` on each `v-text-field`. Keep `resolveErrorMessage` as the fallback for semantic codes. Add one spec per page using the real `validation.failed` envelope shape (see `SignUpPage.spec.ts` "binds per-field validation messages" as the canonical template). Estimated effort: ~10 minutes per page × 7 pages.
- **Owner:** next UX-polish-touching chunk OR a dedicated stabilization sweep.
- **Status:** open. Surfaced by Sprint 3 stabilization pass audit, May 18, 2026 (companion to the SignUp / ResetPassword / InviteUserModal fixes landed the same day).

---

## Agency-side prospect/invited creators list

- **Where:** new surface needed at `apps/main/src/modules/creator-invitations/` (or extension of the [`apps/main/src/modules/agency-users/`](../apps/main/src/modules/agency-users/) `InvitationHistoryTable` pattern). Backend: query surface over [`agency_creator_relations`](../apps/api/database/migrations/) filtered to current agency. Sprint 6 owns the full version per [`docs/20-PHASE-1-SPEC.md`](20-PHASE-1-SPEC.md) Sprint 6 "Agency SPA: creator roster view."
- **What we accepted in Sprint 3 stabilization (May 16, 2026):** Bulk-invite UI ships at `/creator-invitations/bulk` ([Sprint 3 Chunk 4](reviews/sprint-3-chunk-4-review.md)). The post-upload Results card is the only signal of who was invited. No persistent list view surfaces invited-but-not-yet-accepted, accepted-but-incomplete, or approved/rejected creators back to the agency admin.
- **Risk:** agency admins lose visibility on outstanding invitations once they close the Results card. They have no in-platform way to chase non-responders, see who's mid-wizard, or audit the long tail of their bulk-invite history. In production this either drives users to external tracking (defeating the platform's intent) or to repeated re-invitations.
- **Mitigation today:** none. Audit log captures invitation events but isn't surfaced in the agency SPA. The agency-users "Invitation history" tab covers agency-user invitations only, not creator invitations.
- **Triggered by:** Sprint 6 roster view per the spec, OR a Sprint 4 decision to land a minimal prospect-creators list as agency-side stabilization (see Sprint 4 scope discussion).
- **Resolution:** two-tier framing.
  - **Full version (Sprint 6 per spec):** roster view with filtering (country, language, categories, follower range, engagement rate, availability), FTS search, saved talent pools, per-creator detail with ratings + notes + blacklist status.
  - **Minimal version (Sprint 4 chunk candidate):** prospect-creators list at `/creator-invitations` showing email + status (pending invitation / accepted / incomplete / submitted / approved / rejected) + `invited_at` + `invitation_link_status`. Backend: paginated query against `agency_creator_relations` filtered to current agency. Estimated ~1 chunk of work.
- **Owner:** Sprint 4 chunk candidate (minimal) OR Sprint 6 (full version).
- **Status:** **CLOSED** — the **full version (Sprint 6 per spec)** shipped across Sprint 6's three chunks; the minimal Sprint-4 list was leapfrogged (never built — the full roster supersedes it). Each spec'd bullet now has a home:
  - **Roster view + filtering (country / language / categories) + FTS name/bio search** — Sprint 6 Chunk 1 (2026-06-03, [review](reviews/sprint-6-chunk-1-review.md)). The follower-range / engagement-rate / availability filters ship as **honest inert affordances** (no real backing data yet) and are tracked by their own open entries above (handle search, availability-as-a-design-problem) — those are deferred _within_ Sprint 6, not part of this entry's "lose visibility on invitations" risk.
  - **Per-creator detail with ratings + notes + blacklist status** — Sprint 6 Chunk 2a (2026-06-03, [review](reviews/sprint-6-chunk-2a-review.md)).
  - **Saved talent pools** — Sprint 6 Chunk 2b (2026-06-03, [review](reviews/sprint-6-chunk-2b-review.md)). Per-agency, per-brand-label pools with CRUD (Brand-mirrored, soft-delete + restore), creator membership (a net-new pivot-write surface gated on the relation-exists check), and an "add to pool" picker on the 2a detail page.

  The original visibility risk (agency admins lose track of outstanding invitations after closing the bulk-invite Results card) is now answered by the roster view itself: the roster surfaces every `agency_creator_relation` (roster / prospect / external) with status, and the detail view shows per-creator state. **What remains open** (separate entries, not this one): handle search, a real availability filter, and follower/engagement filters — each blocked on net-new infrastructure (social adapters / RRULE expansion), not on this surface.

---

## Unified server-authoritative stall detection across TrackedJob + wizard saga endpoints

- **Where:** [`apps/api/app/Modules/TrackedJobs/Models/TrackedJob.php`](../apps/api/app/Modules/TrackedJobs/Models/TrackedJob.php) + the three wizard saga status controllers (KYC / payout / contract) under [`apps/api/app/Modules/Creators/Http/Controllers/`](../apps/api/app/Modules/Creators/Http/Controllers/). SPA consumers: [`apps/main/src/modules/creator-invitations/pages/BulkInvitePage.vue`](../apps/main/src/modules/creator-invitations/pages/BulkInvitePage.vue) + [`apps/main/src/modules/onboarding/composables/useVendorBounce.ts`](../apps/main/src/modules/onboarding/composables/useVendorBounce.ts).
- **What we accepted in Sprint 3 stabilization (May 16, 2026):** Bulk-invite gained a client-side timeout (MAX_POLLS=20, ~60 s wall-clock) mirroring `useVendorBounce`'s existing client-side detection (Sprint 3 Chunk 3 Q-vendor-bounce-1 = (a) — see [`sprint-3-chunk-3-review.md`](reviews/sprint-3-chunk-3-review.md)). Both surfaces detect stall purely client-side; the backend has no notion of "this job has been pending too long."
- **Risk:** a truly-hung backend job (worker crash, DB lock, vendor saga frozen mid-status, etc.) is invisible to ops — no alerting, no audit trail of stuck workloads. The SPA's "Try again" affordance starts a new job rather than surfacing the actual operational issue. The stall pattern can recur indefinitely against the same broken backend with no observability signal.
- **Mitigation today:** none at the backend. Client-side detection bounds the poll loop budget (~60 s on both surfaces); transient errors don't burn budget (mirrored convention in both `useVendorBounce` and `BulkInvitePage`).
- **Triggered by:** Sprint 4 ops-readiness work, OR a production incident where stuck jobs go unnoticed because no signal reached observability.
- **Resolution:** add `stalled` to [`TrackedJobStatus`](../apps/api/app/Modules/TrackedJobs/Enums/TrackedJobStatus.php) enum + lazy TTL check on `GET /api/v1/jobs/{job}` ([`GetJobController`](../apps/api/app/Modules/TrackedJobs/Http/Controllers/GetJobController.php)) and on the three wizard saga `*Status` endpoints + deprecate the client-side timeout in `useVendorBounce` in favour of reading the server status + add `stalled`-branch handling to both `BulkInvitePage` and the wizard step pages + corresponding cross-layer tests (Pest TTL transition + Vitest stalled-state render + architecture-test parity if the existing status enum has one). Estimated effort: 5-8 hours.
- **Owner:** Sprint 4 chunk candidate.
- **Status:** open. Surfaced by Sprint 3 stabilization pass, May 16, 2026.

---

## SignIn pages render `meta.correct_spa_url` as plain text only (no clickable hop)

- **Where:** [`apps/main/src/modules/auth/pages/SignInPage.vue`](../apps/main/src/modules/auth/pages/SignInPage.vue) + [`apps/admin/src/modules/auth/pages/SignInPage.vue`](../apps/admin/src/modules/auth/pages/SignInPage.vue) + the `auth.wrong_spa` bundle entries in both SPAs' `core/i18n/locales/{en,pt,it}/auth.json`. The backend's [`LoginController`](../apps/api/app/Modules/Identity/Http/Controllers/LoginController.php) maps `LoginResultStatus::WrongSpa` to a 403 envelope carrying `meta.correct_spa_url` (the other SPA's URL — resolved at backend response time), and the SPA's [`useErrorMessage`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) resolver already forwards `error.details[0].meta` as a values bag into `t()`. The forwarding works; the bundle templates just don't interpolate it.
- **What we accepted in Sprint 3 chunk 6:** the bundle entry is plain text ("This account is registered for the admin console. Please sign in there instead." on main / mirror copy on admin). The user reads the message, types the right URL by hand, retries. Sprint 3 chunk 6's goal was unblocking the admin login (Bug A) and closing the user-type × guard mismatch hole (Bug B); rendering a clickable link is UX polish that doesn't change correctness.
- **Risk:** a one-extra-click cost on the wrong-SPA path. Users who mistype the URL bounce again. Not a fingerprinting / security concern — the `correct_spa_url` is the same value `config('app.frontend_main_url')` / `config('app.frontend_admin_url')` carries, which is already public via CORS.
- **Mitigation today:** the i18n message names the destination SPA explicitly ("admin console" / "agency console") so the user knows what to type even without the link.
- **Triggered by:** the next chunk that touches the SignIn page UX, or a UX-quality sprint that surveys auth-page polish across both SPAs.
- **Resolution:** parametrise the `auth.wrong_spa` bundle entry with `{correct_spa_url}` and render the SignIn page's error banner with a vue-i18n `<i18n-t>` slot so the URL becomes an `<a href>` while the rest stays static. The values-bag forwarding from `useErrorMessage` already populates the placeholder — the bundle template just needs to reference it. Estimated effort: 30 minutes per SPA + a unit test pinning that the banner renders a link element.
- **Owner:** next UX-polish-touching chunk.
- **Status:** open.

---

## Admin-origin CSRF preflight rule supports exactly one admin SPA URL

- **Where:** [`apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie::originIsAdminSpa()`](../apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie.php) reads `config('app.frontend_admin_url')` as a single string. The CSRF-preflight session-cookie widening from Sprint 3 chunk 6 (see [`docs/reviews/sprint-3-chunk-6-review.md`](reviews/sprint-3-chunk-6-review.md) — Bug A) only matches a request `Origin` / `Referer` against that one URL.
- **What we accepted in Sprint 3 chunk 6:** one admin URL per environment is the production topology (`admin.catalystengine.com` in prod, `admin.staging.*` in staging, `127.0.0.1:5174` in local dev), so a single-string config suffices today. Generalising to an array would mean a config-shape change (string → list<string>) that ripples to `cors.php` (which reads the same key) and to any operator runbooks.
- **Risk:** a deployment that needs the admin SPA reachable on >1 origin (e.g. a vanity domain rollout, a regional partition served from two hostnames, a preview-deployment scheme for PRs) cannot configure the widening rule without code changes. The CSRF preflight will land on the main session and admin login will 419 on the un-listed origin — the exact failure mode chunk 6 fixed for the canonical URL.
- **Mitigation today:** none. Single-origin is the documented topology.
- **Triggered by:** any sprint that introduces a multi-origin admin deployment shape — vanity domains, preview deploys with their own hostname, or a partner-tenant SPA mounted at a separate hostname.
- **Resolution:** widen `config('app.frontend_admin_url')` to accept either a single string or a list, and have `originIsAdminSpa` iterate. Pair with the same change in `config/cors.php` (which already reads from the same key for CORS allow-list). Estimated effort: half-day including tests and one runbook touch-up.
- **Owner:** the sprint that introduces multi-origin admin.
- **Status:** open.

---

## Laravel default exception shapes outside `ValidationException` still bypass the canonical envelope

- **Where:** [`apps/api/bootstrap/app.php`](../apps/api/bootstrap/app.php) `withExceptions()` — only `Illuminate\Validation\ValidationException` is currently normalized to the JSON:API error envelope from [`docs/04-API-DESIGN.md`](04-API-DESIGN.md) § 8. Other Laravel-default exception types (`Illuminate\Auth\AuthenticationException`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`, `Illuminate\Auth\Access\AuthorizationException`, `Illuminate\Http\Exceptions\HttpResponseException`, the generic 5xx path, etc.) still emit Laravel's stock JSON shape when no controller has hand-built an `ErrorResponse::single` for the failure path.
- **What we accepted in Sprint 3 chunk 5:** the chunk-5 brand-create bug was specifically a `ValidationException` mismatch — that path is now canonical. The other exception types either (a) have controller-level handling that already emits the envelope (`LoginController`, `CreatorWizardController`, password reset, invitation flows — all `ErrorResponse::single`-based), or (b) hit Laravel's defaults but for routes the SPA doesn't currently surface to end users. Either way, no user-visible symptom is currently outstanding. Extending the renderer to cover the remaining exception types is a scope expansion the brand-create bug did not need to land its fix.
- **Risk:** the next time the SPA hits a route that returns one of the un-normalized exceptions (e.g. a freshly-added module that throws `AuthorizationException` from a policy gate without a controller catch, or a route that 404s on a missing model binding), the SPA will surface `[http.invalid_response_body]` again — exactly the symptom chunk 5 set out to eliminate. The bug is invisible to the test suite because backend feature tests assert against the Laravel-default shape (status code + message) and never reach the SPA's envelope parser.
- **Mitigation today:** none structural. Documented in [`docs/04-API-DESIGN.md`](04-API-DESIGN.md) § 8 that the envelope is the wire contract, and in the chunk-5 review that the normalizer covers `ValidationException` only. New module controllers that build their own error responses continue to use `ErrorResponse::single` (the in-tree convention since Sprint 1 Chunk 4) and stay envelope-compliant by hand.
- **Triggered by:** the next chunk that lands a SPA-surfaced route where the failure path relies on Laravel's default exception rendering (not a hand-built `ErrorResponse::single`). Likely candidates: a module that uses route-model binding without an explicit `missing()` callback (404 default), a new policy gate that lacks a controller-level `try { $this->authorize(...) } catch` (403 default), or a route that hits a `RouteNotFoundException` because of a typo (405 / 404).
- **Resolution:** extend `bootstrap/app.php` `withExceptions()` to register `render()` callbacks for each remaining exception type — most of which can share a single helper since they map cleanly onto the same envelope shape (`status` + `code` + `title` + optional `detail`). Concretely: `AuthenticationException` → `auth.unauthenticated` (401), `AuthorizationException` → `authz.forbidden` (403), `NotFoundHttpException` / `ModelNotFoundException` → `http.not_found` (404), `MethodNotAllowedHttpException` → `http.method_not_allowed` (405), `ThrottleRequestsException` → `rate_limit.exceeded` (429), the generic `Throwable` fallback → `http.unknown_error` (500, debug-aware). Pair with one feature test per exception type asserting the envelope shape, parallel to the chunk-5 `ValidationExceptionRendererTest` integration case. Use `ErrorResponse::single` (already in-tree) as the shared builder instead of hand-rolling each.
- **Owner:** the sprint that next surfaces a real user-visible regression from this gap, or whichever earlier sprint has chunk headroom to land the prophylactic fix. Estimated effort: half-day for the renderers + half-day for the test pass, no architecture decisions outstanding.
- **Status:** open.

---

## Test suite runs against SQLite in-memory by default

- **Where:** [`apps/api/phpunit.xml`](../apps/api/phpunit.xml) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- **What we accepted in Sprint 1:** the Pest suite spins up an SQLite in-memory database for every test run. This is fast, hermetic, and requires no docker dependency from CI. Production runs PostgreSQL 16 (per [`docs/00-MASTER-ARCHITECTURE.md`](00-MASTER-ARCHITECTURE.md) §3).
- **Risk:** subtle SQL bugs invisible in SQLite — `jsonb` operators, full-text search, GIN indexes, window-function variants, `INSERT ... ON CONFLICT` quirks, partial indexes, `NULLS FIRST/LAST` ordering. Code that exercises these features can pass tests under SQLite and fail in production.
- **Mitigation today:** Sprint 1 migrations and models stick to driver-agnostic Laravel column types (`json()`, `ipAddress()`, `ulid()`, `char()`) and avoid raw Postgres SQL. The full migration set is also exercised against the live local Postgres on every chunk closure (see chunk-1 verification log).
- **Triggered by:** any sprint that adds Postgres-specific operators (`@>`, `?`, `?|`, `to_tsvector`, `similarity()`), index types (`gin`, `gist`, `brin`), partial indexes, or generated columns.
- **Resolution required by Sprint 8** at the latest. CI must add a Postgres-targeted test job that runs the same suite against a Postgres 16 service container; both jobs become required status checks. Until then, tests that rely on Postgres-specific behaviour must `markTestSkipped()` under SQLite and provide a Postgres-only counterpart. Sprint 8 Postgres-CI also upgrades `audit_logs.ip` `varchar(45)` → `inet` and any `json` → `jsonb` columns non-destructively (expand/migrate/contract per [`docs/08-DATABASE-EVOLUTION.md`](08-DATABASE-EVOLUTION.md)), since audit data will exist by then.
- **Owner:** Sprint that first introduces Postgres-specific SQL.
- **Status:** open.

---

## TOTP issuance does not honor `Carbon::setTestNow()`

- **Where:** [`apps/api/app/Modules/Identity/Services/TwoFactorService.php`](../apps/api/app/Modules/Identity/Services/TwoFactorService.php) (`currentCodeFor()`) and [`apps/api/app/TestHelpers/Http/Controllers/IssueTotpController.php`](../apps/api/app/TestHelpers/Http/Controllers/IssueTotpController.php).
- **What we accepted in Sprint 1 chunk 6.1:** the test-helper TOTP endpoint (`POST /api/v1/_test/totp`) calls `TwoFactorService::currentCodeFor()`, which delegates to `PragmaRX\Google2FA\Google2FA::getCurrentOtp()`. That library reads PHP's `time()` directly and does NOT honor `Carbon::setTestNow()`. The chunk-6 Playwright specs (#19 enrollment, #20 lockout) do not need to combine clock-skip with TOTP issuance, so a real-time-derived code is sufficient today.
- **Risk:** a future spec that combines `Carbon::setTestNow()` time-travel with TOTP issuance silently gets codes derived from real wall-clock time. Silent because the test passes by coincidence — TOTP windows are 30 seconds wide and Carbon-pinned tests usually run fast enough that the simulated and real clocks happen to share the same window. Drift across a 30-day fast-forward would no longer share a window and the test would flip to a confusing "TOTP rejected" failure with no obvious cause.
- **Mitigation today:** the limitation is documented as a `WARNING` in the docblock of `TwoFactorService::currentCodeFor()` and cross-referenced from the `IssueTotpController` docblock, so a future engineer who needs combined clock-skip + TOTP behaviour finds the limitation before they spend an afternoon debugging silent drift.
- **Triggered by:** any future spec that needs both — e.g., a spec that fast-forwards 30+ days via `POST /api/v1/_test/clock` and then enrolls a new TOTP factor, or any sprint that exercises long-window TOTP scenarios (replay attempts across a stale window, drift correction).
- **Resolution:** extend `TwoFactorService::currentCodeFor()` with an optional `?Carbon $at = null` parameter and route through `Google2FA::getCurrentOtpAt($timestamp)` when provided. The test-clock cache value (`config('test_helpers.clock_cache_key')`, default `test:clock:current`) is the canonical source for `$at` in the test-helper controller — `IssueTotpController` would read it via `TestClock::current()` and pass it through, so the same Redis-backed clock that drives `Carbon::setTestNow()` also drives `Google2FA`.
- **Owner:** the sprint that introduces a spec needing combined clock-skip + TOTP. Likely Sprint 9 (session-management UI) or Sprint 13+ (security pipeline) but not earlier.
- **Status:** open.

---

## `useErrorMessage` mapping table is not coverage-checked against the backend code registry

- **Where:** [`apps/main/src/modules/auth/composables/useErrorMessage.ts`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) — the mapping table from `ApiError.code` → i18n key + interpolation values.
- **What we accepted in Sprint 1 chunks 6.5–6.7:** `useErrorMessage` maintains a finite explicit map covering the auth error codes the UI currently renders. New backend `auth.*` codes added in future chunks need a manual line in the map to render with their intended interpolation values; without a line, they fall through to `auth.ui.errors.unknown`.
- **Risk:** a future backend error code lands with an i18n entry (the chunks 6.3 architecture test ensures that), but renders as "An unexpected error occurred" because `useErrorMessage` doesn't know about it. The user-facing impact is a less-helpful error message; not a security or data risk.
- **Mitigation today:** the chunks 6.3 architecture test [`i18n-auth-codes.spec.ts`](../apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts) ensures every backend code has an i18n entry, so the missing case is "code exists in bundle, code missing from `useErrorMessage` map" — a degraded UX, not a crash.
- **Triggered by:** the next chunk that adds a new `auth.*` error code AND surfaces it through the UI.
- **Resolution:** add a new architecture test that walks `useErrorMessage`'s mapping table, walks the harvested backend codes from the chunks 6.3 source-inspection, and asserts every UI-renderable code has an explicit mapping (or a documented fall-through). The set of "UI-renderable" codes is the subset of backend codes that any auth page consumes.
- **Owner:** the sprint that introduces the new auth error code.
- **Status:** closed in Sprint 1 chunk 7.1. The original concern presumed an explicit map; chunks 6.5–6.7 actually shipped a prefix-allowlist resolver (`isLikelyBundledCode`) that forwards every `auth.* | validation.* | rate_limit.*` code straight to `t(error.code)` — there is no per-code mapping table to drift against. With the chunk-6.3 architecture test [`i18n-auth-codes.spec.ts`](../apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts) extended in chunk 7.1 to also harvest `rate_limit.*` literals from the backend (so any new `rate_limit.*` code without a matching bundle entry trips CI before merge), the same drift-detection guarantee that was the original goal now holds for both prefix families. If a future chunk adds a fourth top-level prefix (e.g. `tenant.*`), the resolver and the architecture test must be extended together — that pairing is now the contract.

---

## `auth.account_locked.temporary` i18n bundle has no `{minutes}` interpolation

- **Where:** [`apps/main/src/core/i18n/locales/{en,pt,it}/auth.json`](../apps/main/src/core/i18n/locales/) — the `auth.account_locked.temporary` bundle entry.
- **What we accepted in Sprint 1 chunks 6.8–6.9:** The bundle entry is `"Too many failed sign-in attempts. Please try again in a few minutes."` — generic phrasing, no `{minutes}` placeholder. The backend response carries `meta.retry_after_minutes` on the `AuthErrorResource`, and `useErrorMessage` already forwards `details[0].meta` as the interpolation bag, so the data path is open — only the bundle entry needs a placeholder to consume the value.
- **Risk:** Users see "in a few minutes" instead of the actual minutes remaining. Materially less helpful when the lockout has 14 minutes remaining vs 30 seconds remaining; both render the same string.
- **Mitigation today:** None. The generic phrasing is correct, just imprecise.
- **Triggered by:** A UX-focused chunk that improves auth error messages, OR a user complaint about not knowing how long to wait.
- **Resolution:** Add `{minutes}` placeholder to all three locale bundle entries. Update spec `failed-login-lockout-and-reset.spec.ts`'s substring assertion to accommodate the new shape (still matches `'failed sign-in'` as a substring; no full-string assertion needed).
- **Owner:** The sprint that introduces the UX improvement.
- **Status:** open.

---

## Spec #19 (2FA enrollment) skipped pending in-flight TOTP enrollment helper

- **Where:** [`apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts`](../apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts) — `test.skip(...)` on the sole `full enrollment + re-sign-in flow` test, marked with the `TODO(spec-19-skip)` anchor.
- **What we accepted in Sprint 1 chunk 6 hotfix #3:** Spec #19 is muted in CI. The spec drives the SPA's two-step 2FA enrollment (`TwoFactorEnrollmentService::start()` → `confirm()`) and needs a TOTP code minted from the in-flight enrollment secret — the secret that lives in the cache (key prefix `identity:2fa:enroll:`) during enrollment, NOT the persisted `users.two_factor_secret` column (which is NULL until `confirm()` lands successfully). The chunk-6.8 `mintTotpCodeForEmail` fixture only services the post-enrollment case, so the spec 422s at the helper step. The chunk-6.8 spec design assumed a helper that didn't yet exist.
- **Risk:** The only end-to-end test for the 2FA enrollment flow is muted. Regressions in `EnableTotpPage`, `TwoFactorEnrollmentService::confirm()`, the recovery-codes UI countdown, or the `requireMfaEnrolled` router guard's enrollment-rebound behaviour can land without surfacing in CI until the spec is restored. Spec #20 (failed-login lockout) remains active and exercises a different slice of the auth surface.
- **Mitigation today:** Vitest unit specs + Pest feature specs cover the underlying behaviour at the component + service + controller level (`EnableTotpPage.spec.ts`, `RecoveryCodesDisplay.spec.ts`, `TwoFactorEnrollmentService` Pest tests, `IssueTotpController` Pest tests, `requireMfaEnrolled` guard architecture test). The 2FA path is exercised; what's missing is the cross-layer integration check that Playwright provides.
- **Triggered by:** the next chunk that touches `EnableTotpPage`, `TwoFactorEnrollmentService`, `IssueTotpController`, the recovery-codes UI, or the `requireMfaEnrolled` guard in a way that could break the cross-layer enrollment flow. Also triggered by the Sprint 2 kickoff if the spec is still skipped at that point — restoring it should be sequenced into Sprint 2 planning rather than carried indefinitely.
- **Resolution:** Follow-up review round designs an in-flight TOTP enrollment helper. The chunks 6.8–6.9 review's post-merge addendum #3 captures the discovery context. The same review round should also reconsider chunk-6.8 OQ-3 (whether `CACHE_STORE=array` was technically a correct choice given `php artisan serve`'s per-request PHP process model — separate technical question, related context). Once the helper lands, flip `test.skip` → `test` and remove the `TODO(spec-19-skip)` anchor.
- **Owner:** the sprint that introduces a chunk hitting one of the trigger conditions, OR an explicit "restore spec #19" sub-chunk in Sprint 2 if no triggering chunk has landed by then.
- **Status:** closed in Sprint 1 chunk 7.1. New backend test-helper endpoint `POST /api/v1/_test/totp/secret` (in [`IssueTotpFromSecretController`](../apps/api/app/TestHelpers/Http/Controllers/IssueTotpFromSecretController.php)) accepts a base32 secret directly and returns the current 6-digit code derived from it; the new Playwright fixture [`mintTotpFromSecret`](../apps/main/playwright/fixtures/test-helpers.ts) wraps it. The chunk-7.1 redesign of the spec reads the in-flight secret from the SPA's `enable-totp-manual-key` `data-test` element (the same string the SPA shows the user for manual authenticator entry) and forwards it to the helper, sidestepping the cache-walking complexity that taking `email` would have required. The helper is gated identically to the chunk-6.1 surface (env + token + provider) and routes through `TwoFactorService::currentCodeFor()` so the chunk-5 Google2FA isolation invariant holds. The deviation from the kickoff's "by email" wording is documented in the chunk-7.1 review's "Open questions" section. `test.skip` removed; the spec is active in CI.

---

## Spec #20 (failed-login lockout + reset) skipped pending throttle-vs-lockout-vs-resolver follow-up

- **Where:** [`apps/main/playwright/specs/failed-login-lockout-and-reset.spec.ts`](../apps/main/playwright/specs/failed-login-lockout-and-reset.spec.ts) — `test.skip(...)` on the sole `short-window lockout, fast-forward unlock, long-window escalation` test, marked with the `TODO(spec-20-skip)` anchor.
- **What we accepted in Sprint 1 chunk 6 hotfix #4:** Spec #20 is muted in CI. The spec's narrative ("6th rapid sign-in attempt hits the temporary-lockout response, fast-forward 16 minutes, attempt again, etc.") doesn't match the real-server surface for three layered reasons that surfaced in CI runs #32 and #33:
  1. **Cache driver.** `CACHE_STORE=array` doesn't survive `php artisan serve`'s per-request PHP process model, so neither the throttle counter nor the lockout counter accumulate. Fixed by hotfix #3 (commit `22f3f6a`); cache state now persists across requests in CI.
  2. **Layer ordering.** The route-level `throttle:auth-login-email` middleware (5/min/email + IP, defined in [`IdentityServiceProvider::registerRateLimits()`](../apps/api/app/Modules/Identity/IdentityServiceProvider.php) lines 65-79) preempts the application-level `FailedLoginTracker` (5/15min/email, [`AuthService::recordFailureAndMaybeLock()`](../apps/api/app/Modules/Identity/Services/AuthService.php)) at exactly the same threshold. The 6th rapid attempt returns 429 + `code: rate_limit.exceeded` from the throttle and never reaches `LoginController` → `AccountLockoutService::temporaryLock()`. The chunk-5 Pest suite hides this overlap by explicitly disabling the throttles to exercise the lockout layer in isolation ([`LoginTest.php`](../apps/api/tests/Feature/Modules/Identity/LoginTest.php) lines 29-35); the chunk-3 throttle suite hides it from the other side ([`AuthRateLimitTest.php`](../apps/api/tests/Feature/Modules/Identity/AuthRateLimitTest.php)). Both layers are tested in isolation but never composed, so the chunk-6.8 spec design didn't catch the preemption.
  3. **Resolver taxonomy.** Even if spec #20 asserted on the throttle response instead of the lockout response, [`useErrorMessage`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) rejects any code without an `auth.` or `validation.` prefix. The throttle's `rate_limit.exceeded` falls through to `auth.ui.errors.unknown` ("Something went wrong. Please try again."). Tracked separately in the "SPA renders generic fallback for rate-limit errors on auth endpoints" entry below.
- **Risk:** The only end-to-end test for the failed-login lockout + reset / escalation flow is muted. Regressions in `FailedLoginTracker`, `AccountLockoutService::temporaryLock()` / `escalate()`, the chunk-3 24h escalation path (`is_suspended = true` + `auth.account_locked.suspended`), and the chunk-3.5 password-reset clearing of the lockout cache can land without surfacing in CI until the spec is restored. Spec #19 (2FA enrollment) is also muted under the entry above — both Playwright specs in chunk 6.8 are now deferred.
- **Mitigation today:** Pest feature specs cover both layers in isolation: `LoginTest.php` for the lockout (with throttles disabled), `AuthRateLimitTest.php` for the throttle (with infinite-budget login), `PasswordResetTest.php` for the lockout-clearing on reset. What's missing is the cross-layer integration check Playwright provides AND a Pest test that exercises throttle-and-lockout in composition (the "neither isolation suite catches the overlap" hole the chunk-5 testing-strategy comment in `LoginTest.php` line 31 implicitly acknowledges).
- **Triggered by:** the next chunk that touches `FailedLoginTracker`, `AccountLockoutService`, the `auth-login-email` named limiter, the SPA's sign-in error rendering, the password-reset lockout-clearing path, OR the resolver-taxonomy entry below. Also triggered by the Sprint 2 kickoff if the spec is still skipped at that point — restoring it should be sequenced into Sprint 2 planning rather than carried indefinitely.
- **Resolution:** Follow-up review round picks one of: (i) add a `_test/throttle/reset` (or similar) test-helper that neutralizes the named limiters per spec so the lockout layer can be exercised in isolation, mirroring the Pest `RateLimiter::for('...', Limit::none())` pattern; (ii) rewrite spec #20 to assert the throttle-then-lockout chain that production actually exhibits, dropping the lockout-in-isolation framing; (iii) some composition of the two. The follow-up should also decide whether to add a Pest test that exercises both layers together so the chunk-5 isolation pattern doesn't keep hiding the overlap. The chunks 6.8–6.9 review's post-merge addendum #3 captures the discovery context.
- **Owner:** the sprint that introduces a chunk hitting one of the trigger conditions, OR an explicit "restore spec #20" sub-chunk in Sprint 2 if no triggering chunk has landed by then. Likely paired with the spec #19 restore (same follow-up review round).
- **Status:** closed in Sprint 1 chunk 7.1 via option (i). New test-helper endpoint pair `POST/DELETE /api/v1/_test/rate-limiter/{name}` (controller in [`NeutralizeRateLimiterController`](../apps/api/app/TestHelpers/Http/Controllers/NeutralizeRateLimiterController.php), service in [`RateLimiterNeutralizer`](../apps/api/app/TestHelpers/Services/RateLimiterNeutralizer.php)) lets a spec mark the named `auth-login-email` limiter as `Limit::none()` for the duration of the test. Persistence is cache-backed so the override survives across `php artisan serve`'s per-request PHP processes; [`TestHelpersServiceProvider::boot()`](../apps/api/app/TestHelpers/TestHelpersServiceProvider.php) re-applies the override on every fresh request via `RateLimiter::for($name, fn => Limit::none())`, which is the same primitive `LoginTest::beforeEach` uses. The chunk-7.1 redesign of the Playwright spec calls [`neutralizeThrottle`](../apps/main/playwright/fixtures/test-helpers.ts) in `beforeEach` and [`restoreThrottle`](../apps/main/playwright/fixtures/test-helpers.ts) in `afterEach` (the mandatory pair the controller docblock enforces), so the request graph reaches the application-level lockout in the same shape `LoginTest` exercises in Pest. Composition coverage is now explicit too: a new Pest test in [`RateLimiterNeutralizerTest`](../apps/api/tests/Feature/TestHelpers/RateLimiterNeutralizerTest.php) ("with auth-login-email neutralised, the 6th wrong-password attempt is the lockout (not the throttle)") exercises both layers together — closing the "neither isolation suite catches the overlap" hole the chunk-5 testing-strategy comment in `LoginTest.php` line 31 implicitly acknowledged. `test.skip` removed; the spec is active in CI.

---

## SPA renders generic fallback for rate-limit errors on auth endpoints

- **Where:** [`apps/main/src/modules/auth/composables/useErrorMessage.ts`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) — the `isLikelyBundledCode()` predicate at lines 63-65.
- **What we accepted in Sprint 1 chunk 6 hotfix #4:** The `useErrorMessage` resolver only accepts backend codes prefixed with `auth.` or `validation.` and falls back to `auth.ui.errors.unknown` ("Something went wrong. Please try again.") for anything else. The four auth rate-limiters in [`IdentityServiceProvider::registerRateLimits()`](../apps/api/app/Modules/Identity/IdentityServiceProvider.php) (`auth-ip`, `auth-login-email`, `auth-password`, `auth-resend-verification`) all emit `code: rate_limit.exceeded` on a 429 response with the localized message in the response's `title` field via the `auth.login.rate_limited` bundle key (interpolated with `{seconds}`). The SPA never sees the `title` because the resolver maps by `code` alone, and `rate_limit.*` is not in the accepted prefix set. Discovered via the chunk-6.8 spec #20 CI investigation in hotfix #4 (the spec's failure exposed this production-surface gap as a side effect).
- **Risk:** Any user who hits an auth rate limit sees the unknown-fallback string instead of the actual cause. Concrete scenarios: 6+ failed sign-in attempts on the same email within a minute, repeated `/forgot-password` requests within a minute, repeated `/resend-verification` requests within a minute. The bundled message ("Too many sign-in attempts. Please try again in {seconds} seconds.") never reaches the user. Material UX degradation at exactly the moments where users most need a clear error message; not a security or data risk.
- **Mitigation today:** None. The unknown-fallback is a correct safety surface (no information leak) — just a worse UX than the bundled message that already exists.
- **Triggered by:** A production sighting (a real user reports "Something went wrong" after rapid sign-in attempts), OR the chunk-6.8 spec #20 follow-up review (the "Resolver taxonomy" finding above), whichever comes first.
- **Resolution:** Two clean shapes for the follow-up review to pick from — (i) extend `isLikelyBundledCode()` to accept `rate_limit.` as a third valid prefix AND ensure each locale's `auth.json` has a `rate_limit.exceeded` entry (or the resolver maps `rate_limit.exceeded` → `auth.login.rate_limited` explicitly to reuse the existing bundle key); (ii) widen the resolver to fall back to the response's `title` field when the code-keyed lookup misses (covers any future code without per-code mapping work). Either way, the i18n architecture test [`tests/unit/architecture/i18n-auth-codes.spec.ts`](../apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts) needs an extension to cover the chosen approach so the gap can't reopen.
- **Owner:** The follow-up review for spec #20, OR an explicit UX chunk if no review has triggered by Sprint 2.
- **Status:** closed in Sprint 1 chunk 7.1 via option (i). [`useErrorMessage`](../apps/main/src/modules/auth/composables/useErrorMessage.ts) now accepts `rate_limit.*` as a third valid prefix; en/pt/it [`auth.json`](../apps/main/src/core/i18n/locales/) bundles each carry a top-level `rate_limit.exceeded` entry with a `{seconds}` interpolation; backend [`IdentityServiceProvider::registerRateLimits()`](../apps/api/app/Modules/Identity/IdentityServiceProvider.php) was simultaneously fixed to emit `meta: { seconds }` per error entry (the existing shape interpolated `seconds` into `title` only — the resolver pulls interpolation values exclusively from `details[0].meta`, so without this backend change the bundled string would render with an unfilled placeholder; flagged as deviation #2 in the chunk-7.1 review). Coverage: the Vitest [`useErrorMessage.spec.ts`](../apps/main/src/modules/auth/composables/useErrorMessage.spec.ts) gains positive cases for `rate_limit.exceeded` (with and without meta) plus negative cases pinning that the predicate did NOT widen to `error.*`, `http.*`, or bare-prefix codes; the [`SignInPage.spec.ts`](../apps/main/src/modules/auth/pages/SignInPage.spec.ts) gains an end-to-end render assertion ("Too many requests. Please try again in 42 seconds."); the architecture test [`i18n-auth-codes.spec.ts`](../apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts) was extended to also harvest `rate_limit.*` literals from the backend so a future rate-limit code without a matching bundle entry trips CI before merge; and the backend [`AuthRateLimitTest.php`](../apps/api/tests/Feature/Modules/Identity/AuthRateLimitTest.php) gains a Pest case that pins `errors.0.meta.seconds` is an integer in `[0, 60]`.

---

## Vue 3 single-root attribute fall-through can silently override child `data-test` selectors

- **Where:** Any `.vue` file that invokes another single-root component AND passes a `data-test` (or other plain HTML attribute) at the call site, where the child's root already carries the same attribute. The class of bug; not a single offending location. The chunk-7.1 post-merge hotfix removed the one known instance ([`apps/main/src/modules/auth/pages/EnableTotpPage.vue`](../apps/main/src/modules/auth/pages/EnableTotpPage.vue) was passing `data-test="enable-totp-recovery"` to `<RecoveryCodesDisplay>`, whose `<section>` root already had `data-test="recovery-codes-display"` — see commit hash recorded in the chunk-7.1 review).
- **What we accepted in Sprint 1 chunk 7.1 post-merge hotfix:** No architecture test exists that scans `.vue` files for `data-test` (or other non-class/style attribute) being passed to a child component whose root already declares the same attribute. The bug class is real: Vue 3 single-root attribute fall-through REPLACES the child's value with the parent-supplied value, silently breaking any test that relies on the child's own selector. The chunk-7.1 hotfix verified this empirically with a one-shot harness (`<Child render="<section data-test='child-id'/>" />` invoked as `<Child data-test="parent-id" />` renders as `<section data-test="parent-id">`) — only the parent's value survives.
- **Risk:** A future contributor adds `data-test="..."` at a child invocation site (defensively, mirroring a parent page's naming), unaware that it nukes the child's selector. Vitest specs that mount the parent and assert on the parent's data-test will pass; Playwright specs that target the child's data-test will fail with a confusing "element not found" against a clearly-rendered-in-the-DOM element. The diagnostic loop (especially against CI artifacts) is non-trivial — see the chunk-7.1 hotfix saga for the full discovery context.
- **Mitigation today:** A docblock comment at the EnableTotpPage call site warns future contributors. The deferred architecture test would catch the class systematically, but no runtime guard exists.
- **Triggered by:** The next chunk that adds a new `.vue` file invoking a child component AND passes a `data-test` at the call site. Also any Playwright spec failure with "element not found" where the page snapshot shows the element is rendered.
- **Resolution:** Add an architecture test (`apps/main/tests/unit/architecture/`) that walks all `.vue` files, parses each `<template>` for child-component invocations (PascalCase tags), checks whether the call site passes a `data-test` attribute, and if so, parses the child component's `<template>` and asserts the child's root does NOT also declare a `data-test`. Approach options for the parser: regex-based (fast, brittle, false positives on slot syntax), `@vue/compiler-sfc` (precise, requires the compiler dep, parses templates to AST). The architecture test stays scoped to `data-test` because that's the bug class — Vuetify components routinely accept legitimate prop fall-through (id, aria-\*, class, etc.) and the test should not trip on those.
- **Owner:** The chunk that next adds a new parent/child component pair with `data-test` at the call site, OR an explicit "tooling" sub-chunk in chunk 7.2+ if no triggering chunk has landed by then.
- **Status:** open.

---

## Laravel exception handler returns HTML/redirect for unauthenticated `/api/v1/*` requests without `Accept: application/json`

- **Where:** [`apps/api/bootstrap/app.php`](../apps/api/bootstrap/app.php) — the `withExceptions(...)` block, plus Laravel's framework-default `Illuminate\Auth\Middleware\Authenticate::redirectTo()` / `unauthenticated()` behaviour. The miss is a config gap, not a code bug.
- **What we accepted in Sprint 1 chunk 7.1 post-merge hotfix:** An unauthenticated request to any `/api/v1/*` route that does NOT carry `Accept: application/json` (or `X-Requested-With: XMLHttpRequest`) triggers Laravel's default `redirect-to-named-login` branch instead of the JSON 401 envelope. The default redirect target is `route('login')`. This API-only Laravel app has no named `login` route — the SPA owns the sign-in UI and the API surface has only `auth.login` (the chunk-3 [`Identity` Routes](../apps/api/app/Modules/Identity/Routes/api.php) line 71 controller route, not a redirect target). The `route('login')` lookup throws `Symfony\Component\Routing\Exception\RouteNotFoundException` ("Route [login] not defined."), which cascades to a 500 response with Laravel's HTML error page instead of a clean 401 JSON envelope. Discovered when spec #19's `signOutViaApi` Playwright fixture (which lacked the headers) hit `POST /api/v1/auth/logout` after sign-out cookie expiry and surfaced the 500 in CI.
- **Risk:** Any programmatic API consumer (Playwright fixtures, future integration tests, third-party scripts, partner-API consumers, CLI tools) that calls a protected endpoint without the SPA's header conventions gets a confusing 500 + HTML response instead of a documented 401 JSON envelope. Diagnostic loops on the 500 are non-trivial — the actual cause (missing `Accept` header) is several layers removed from the surface symptom (`RouteNotFoundException`). The chunk-7.1 hotfix saga added ~30 minutes to the diagnosis specifically because of this misleading surface.
- **Mitigation today:** The SPA's [`apiClient`](../apps/main/src/core/api-client/) sets `Accept: application/json` on every request (chunk-3 convention, enforced by [`tests/unit/architecture/no-direct-http.spec.ts`](../apps/main/tests/unit/architecture/no-direct-http.spec.ts)). After this commit, the Playwright fixtures in [`apps/main/playwright/fixtures/test-helpers.ts`](../apps/main/playwright/fixtures/test-helpers.ts) follow the same convention via the shared `defaultHeaders` constant. Both consumer paths now self-identify correctly. The risk is for FUTURE consumers that don't inherit the convention.
- **Triggered by:** A new API consumer surfaces with a misleading 500 error trace (e.g., a partner integration, a CLI tool, a test fixture in another app), OR a Sprint that hardens API responses to a documented contract (the 04-API-DESIGN.md envelope shape from § 8 should hold for ALL responses, not just SPA-originated ones), OR Laravel 12+ if the framework defaults change to JSON-first for `/api/*` paths.
- **Resolution:** Configure the exception handler in `apps/api/bootstrap/app.php`'s `withExceptions(...)` block to match the request path against `api/*` and force JSON responses for all paths in that namespace, regardless of the request's `Accept` header. Concretely: register a `render` callback for `Throwable` (or specifically for `AuthenticationException`) that checks `$request->is('api/*')` and returns the standard error-envelope JSON (matching [`docs/04-API-DESIGN.md`](04-API-DESIGN.md) § 8 — `errors: [{ status, code, title, ... }]`) with the appropriate HTTP status. The `auth.unauthenticated` code already exists in the i18n bundle (chunk-6.5) for the `title` field. Add a Pest test that pins the contract (a request to a protected `/api/v1/*` endpoint without `Accept: application/json` AND without authentication returns 401 JSON, not 500 HTML).
- **Owner:** The Sprint that next touches Laravel exception handling configuration (e.g., a security-hardening sub-chunk in Sprint 2+, or a "documented API contract" hardening pass), OR an explicit "API hygiene" sub-chunk if no triggering Sprint lands within 2 sprints.
- **Status:** open.

---

## Test-clock pinning interacts with Laravel cookie expiry to invalidate session/XSRF cookies when wall-clock time moves past `T0 + session.lifetime`

- **Where:** Backend [`apps/api/app/TestHelpers/Services/TestClock.php`](../apps/api/app/TestHelpers/Services/TestClock.php) (the chunk-6.1 surface that pins `Carbon::setTestNow()`) interacting with Laravel's session middleware (`Illuminate\Session\Middleware\StartSession`) and Symfony's cookie serialiser (`Symfony\Component\HttpFoundation\Cookie::__toString()`). The Playwright surface most likely to trip this is any spec that calls [`setClock`](../apps/main/playwright/fixtures/test-helpers.ts) before a SPA-driven CSRF-protected POST. The chunk-7.1 hotfix worked around it in [`apps/main/playwright/specs/failed-login-lockout-and-reset.spec.ts`](../apps/main/playwright/specs/failed-login-lockout-and-reset.spec.ts) (`T0 = Date.now() + 30 days`).
- **What we accepted in Sprint 1 chunk 7.1 post-merge hotfix:** Laravel's session middleware computes the session-cookie expiry as `Carbon::now()->addMinutes(config('session.lifetime'))`. With `Carbon::setTestNow()` pinned (e.g. via the chunk-6.1 test clock), `Carbon::now()` returns the pinned instant — so `$expiresTimestamp = pinned_now + lifetime_seconds`. Symfony then serialises the cookie with `Max-Age = $expiresTimestamp - time()`, where `time()` is **real** wall-clock time, not Carbon. If `pinned_now + lifetime < real wall-clock now`, `Max-Age` is negative; Symfony clamps it to 0; the browser is required by the cookie spec to delete the cookie immediately. The `expires` attribute of the cookie is also a past timestamp, so even browsers that ignored Max-Age would still discard the cookie. Net effect: every CSRF-protected POST after such a `setClock(...)` call lands without `XSRF-TOKEN` or session cookies, Laravel's `VerifyCsrfToken` returns HTTP 419, and the SPA's `useErrorMessage` resolver falls through to `auth.ui.errors.unknown` ("Something went wrong. Please try again."). Discovered when chunk 7.1's deferred Playwright spec #20 was reactivated and started failing in CI on a wall-clock date one day past the hard-coded `T0 = '2026-05-10T09:00:00Z'` baseline (which gave `Max-Age = (T0 + 2h) - real_now ≈ -30h → clamped to 0`).
- **Risk:**
  - **Future specs that pin past dates silently break.** Any future Playwright spec that calls `setClock(request, '<past or near-past date>')` before driving a state-changing form submit will hit the same surface — and the failure mode (generic "Something went wrong") gives no signal that cookie expiry is the cause. Diagnosis requires reading the network trace's raw `Set-Cookie` header to spot `Max-Age=0`.
  - **Time-bomb pattern in already-shipped specs.** Even a spec that pins a date "in the chunk-N timeframe" (i.e. close to the authoring wall clock) will eventually flip from passing to failing as the repo's wall clock drifts past `T0 + session.lifetime`. The chunk-7.1 spec #20 fix (`T0 = Date.now() + 30 days`) is robust forever; the original `T0 = '2026-05-10T09:00:00Z'` was a time bomb that exploded ~24 hours after `T0 + 2h`.
  - **Backend Pest specs are immune** because they don't issue real cookies through a real browser — Laravel's test client doesn't enforce `Max-Age`. Only the Playwright (or production) request graph trips this.
- **Mitigation today:** Spec #20's `T0` is now computed as `Date.now() + 30 days` (one full month of wall-clock buffer past the spec's longest fast-forward). The spec docblock + an inline comment at the `T0` definition explain the constraint. No system-level guardrail exists — a future spec author who hard-codes a past date will repeat the discovery.
- **Triggered by:** A Playwright spec author hard-codes a `setClock(request, '<past date>')` call, OR a long-deferred spec reactivates after the wall clock has drifted past its hard-coded baseline + `session.lifetime`, OR a Sprint 2+ chunk extends the test-clock surface to anything that issues HTTP cookies.
- **Resolution:** Two complementary structural fixes:
  1. **Make `setClock` warn/fail when given a baseline that would invalidate cookies.** Extend the [`setClock`](../apps/main/playwright/fixtures/test-helpers.ts) Playwright fixture (and/or the [`TestClock`](../apps/api/app/TestHelpers/Services/TestClock.php) backend service) to compare the pinned instant + `config('session.lifetime')` against the real wall clock and either log a clear warning or throw with a self-explanatory message ("setClock(<instant>) would invalidate session cookies — pick a baseline >= now + session.lifetime").
  2. **Add an architecture test that walks all Playwright specs and rejects hard-coded `Date('YYYY-MM-DD…')` literals passed to `setClock` (or via a `T0` const that's a `Date(<string-literal>)`).** Forces specs to either compute T0 relative to wall clock or to import a shared "safe T0" helper (option 3 below).
  3. **Provide a shared helper.** Add `safeFutureClockBaseline()` (or similar name) to [`test-helpers.ts`](../apps/main/playwright/fixtures/test-helpers.ts) that returns `Date.now() + N days` with `N` documented as "covers session.lifetime + spec fast-forwards". Specs that need a relative offset compose the helper with offsets in minutes/hours.
     Land any subset; option 1 is the highest-leverage one because it produces a clear runtime error when authoring future specs.
- **Owner:** The next sprint that touches the Playwright fixture surface, the test-clock backend, OR a "test infrastructure" hardening sub-chunk. Likely Sprint 2 if no triggering chunk lands earlier.
- **Status:** open.

## Idle-timeout enforcement unwired on both admin and main SPAs

**Where:** `apps/main/src/core/auth/useIdleTimeout.ts` (composable exists but is not invoked from `apps/main/src/App.vue`); `apps/admin/src/App.vue` (does not invoke any idle-timeout composable).

**What we accepted:** Both SPAs ship without active idle-timeout enforcement despite `useIdleTimeout` existing on main. Per `05-SECURITY-COMPLIANCE.md` § 6.3, admin SPA should enforce a 30-minute idle timeout (stricter than main's looser policy); neither SPA currently does so.

**Risk:** An admin user leaves their session open indefinitely without re-authenticating. An attacker with physical access to the unlocked machine gains admin-level access without re-prompting. The window is bounded only by the session cookie's absolute lifetime, not by inactivity.

**Mitigation today:** Session cookie has a 2-hour absolute lifetime per Laravel's default `session.lifetime`; user must re-authenticate after that regardless of activity. This is significantly weaker than active idle-timeout enforcement but caps the exposure window.

**Triggered by:** A future security-hardening sprint OR a security review flagging idle-timeout as a gap OR a CI test that asserts idle-timeout behavior (none exist today).

**Resolution:** Wire `useIdleTimeout` from each SPA's `App.vue` with the configured timeout values from `05-SECURITY-COMPLIANCE.md` § 6.3 (admin 30 min, main per main's policy). Ensure both invocations use the correct per-SPA configuration. Add Vitest coverage for the wiring.

**Owner:** Future security-hardening sprint.

**Status:** Open. Surfaced as deviation D6 in sub-chunk 7.4 (Group 2 of chunk 7).

## Admin SPA Playwright job runs without a Laravel backend

**Where:** `.github/workflows/ci.yml` § "E2E — admin SPA (placeholder until chunk 7)" (job `e2e-admin`, lines 268–313). The job runs Playwright against `pnpm dev` only — no Postgres, no Redis, no PHP, no `php artisan serve`. `apps/admin/playwright.config.ts` `webServer.command: 'pnpm dev'` starts only the Vite dev server at `:5174`; the Vite proxy forwards `/api` + `/sanctum` to a non-existent backend at `:8000`.

**What we accepted:** Pre-7.4 the admin `App.vue` rendered an i18n title statically — no backend dependency. The single `smoke.spec.ts` asserted that h1. Post-7.4 the SPA's home route (`/` → `app.dashboard`) is gated by `requireAuth` which calls `store.bootstrap()` → `/admin/me`; without a backend that request hangs / fails and the SPA never gets past the loading shell. The fix landed in the Group 2 CI follow-up commit was to target `/sign-in` (a `requireGuest` route, no bootstrap call) so the smoke spec stays backend-independent while the infra deferral resolves. The kickoff for 7.4 explicitly scoped Playwright work as 7.6's surface: "No new Playwright work (that's 7.6's surface)." Adding Postgres + Redis service containers + PHP setup + `migrate:fresh` + a shared `TEST_HELPERS_TOKEN` to the e2e-admin job is meaningful CI infra work and was deliberately deferred.

**Risk:** As soon as chunk 7.6 introduces real admin E2E specs (sign-in happy path, sign-in error paths, mandatory-MFA enrollment journey, deep-linking with intended-destination preservation across the MFA redirect — the D7 admin-specific adaptation), the job MUST be extended to include a real backend OR the specs need to drive the SPA against a mocked api-client (which would compromise their E2E character). Until then, the smoke spec is the only signal that the admin Playwright config + Vite dev server boot correctly; it cannot exercise anything that touches the auth-store contract.

**Mitigation today:** The smoke spec uses `/sign-in` which mounts `requireGuest` and does not call `bootstrap()`, so no `/admin/me` request fires. The spec verifies the SPA mounts + i18n resolves + the route renders. Coverage gaps until 7.6: any route gated by `requireAuth` or `requireMfaEnrolled` is unreachable in the admin E2E surface — those branches are covered at the Vitest unit + dispatcher level (`apps/admin/tests/unit/core/router/index.spec.ts`, including the chained-D7-flow case) instead.

**Triggered by:** Chunk 7.6 (Group 3 of chunk 7) — the chunk that ships the substantive admin E2E specs. That chunk's natural surface is exactly this CI infra change.

**Resolution:** Extend `.github/workflows/ci.yml` job `e2e-admin` to mirror `e2e-main`'s shape:

1. Add `services: { postgres, redis }` blocks with the same image tags + healthchecks.
2. Add `env:` block with the same Laravel runtime config (`APP_ENV`, `DB_*`, `CACHE_STORE: database`, `SESSION_DRIVER: database`, `SANCTUM_STATEFUL_DOMAINS`, `VITE_API_BASE_URL`). Override `SESSION_COOKIE` to `catalyst_admin_session` to match admin's cookie name.
3. Add the `Setup PHP` + composer steps + `Generate fresh TEST_HELPERS_TOKEN` + `Generate Laravel APP_KEY` + `migrate:fresh` + Playwright cache + install browsers steps from `e2e-main`.
4. Extend `apps/admin/playwright.config.ts`'s `webServer` block to spin both the API (`php artisan serve --port=8000`) AND the Vite dev server, the same way `apps/main/playwright.config.ts` does. The Vite proxy already forwards `/api` + `/sanctum` to `:8000`.
5. Revert `apps/admin/tests/e2e/smoke.spec.ts` to target `/` (or extend it to exercise the full D7 chained flow against the real backend), so the smoke gains its full pre-7.4 contract back.

**Owner:** Chunk 7.6 (sub-chunk 7.6 within Group 3 of chunk 7).

**Status:** Closed in Sprint 1 chunk 7.6 (Group 3 of chunk 7). The `e2e-admin` job now mirrors `e2e-main`'s full stack: Postgres + Redis service containers, PHP setup, `migrate:fresh`, `TEST_HELPERS_TOKEN` rotation, and a dual `webServer` block in `apps/admin/playwright.config.ts` that boots both the Laravel API and the Vite dev server. Ports are offset (admin API: `:8001`, admin Vite: `:5174`) so `e2e-main` and `e2e-admin` can run concurrently without colliding on a single API port. The smoke spec is replaced by two substantive specs: `admin-sign-in.spec.ts` (happy path against an admin pre-enrolled in 2FA) and `admin-mandatory-mfa-enrollment.spec.ts` (the D7 deep-link chained-flow journey). All chunk-7.1 saga conventions are applied from the first commit — per-spec `auth-ip` neutralisation, shared `defaultHeaders` constant on every API-driven fixture, no parent `data-test` attribute fall-through on the `<RecoveryCodesDisplay>` slot, `resetClock` in `afterEach` as a defence-in-depth — so this surface does not replay the saga.

---

## Light theme `primary` / `on-primary` fails WCAG AA-normal contrast (2.49:1)

- **Where:** [`packages/design-tokens/src/vuetify.ts`](../packages/design-tokens/src/vuetify.ts) `lightTheme.colors` — `primary` = `#14B8A6` (`brand.teal[500]`), `on-primary` = `#FFFFFF` (`neutral[0]`). Pinned by the `it.todo` in [`packages/design-tokens/src/vuetify.spec.ts`](../packages/design-tokens/src/vuetify.spec.ts) (`lightTheme: primary / on-primary meets WCAG AA-normal — currently 2.49:1, deferred per chunk-8 kickoff (don't redesign light palette)`).
- **What we accepted in Sprint 1 chunk 8.1:** White text on the brand-teal `#14B8A6` measures **2.49:1** — below WCAG AA-normal (4.5:1) AND below AA-Large (3.0:1). The pair has been part of the design-tokens package since chunk 3 and is shipped to users in the main SPA's primary action surface (every `<v-btn color="primary">` button label, the brand mark, etc.). The chunk-8 kickoff explicitly forbade redesigning the light palette in chunk 8.1: _"Light theme: preserve current Vuetify light palette as the baseline (don't redesign). [...] Dark mode is additive."_ The contrast spec records the failure with an `it.todo` so the gap is visible in CI output without breaking the build.
- **Risk:** Users with low vision (especially older users or those with mild contrast sensitivity) struggle to read button labels on the primary action color. WebAIM's AA-normal threshold (4.5:1) is the legal baseline in many jurisdictions for UI text; 2.49:1 is well below. Brand-related: teal-500 is the brand color, so the issue is structural, not a fixable opacity tweak.
- **Mitigation today:** None at the runtime layer. The dark theme passes AA-normal (10.0:1 for primary/on-primary) so users who switch to dark mode are unaffected. The contrast spec test surfaces the measurement so contributors see "1 todo" in design-tokens test output and can find the rationale via the test docblock + this entry.
- **Triggered by:** A future chunk explicitly scoped to refining the light palette (e.g., a Phase-1-late accessibility-audit chunk, a brand-redesign chunk, or a UX-polish sprint), OR a user complaint about button label readability in light mode. The dark palette refinement that landed in chunk 8.1 (dark `error` → `palette.danger[500]` to pass AA-normal) is the model.
- **Resolution options (any of):**
  1. **Darker `primary`:** swap `brand.teal[500]` (#14B8A6) → `brand.teal[700]` (#0B6F66). Contrast against white: ~6.6:1 ✅. Cross-cuts the brand identity — teal-700 is darker than the marketed brand color; needs design review.
  2. **Darker `on-primary`:** swap `neutral[0]` (white) → `neutral[900]` (#121211). Contrast: ~9:1 ✅. Visually unusual (dark text on saturated teal); compatible with branding but breaks the "white text on brand color" Material Design convention.
  3. **Add a `primary-darken-1` Vuetify variant for buttons** and use it via Vuetify's `variant="elevated"` defaults so default buttons render with a slightly darker primary that DOES pass AA-normal. Less disruptive but doesn't fix raw `color="primary"` consumers.
  4. **Defer** to a brand-redesign chunk where the entire palette is revisited.
- **Owner:** The first sprint that owns light-palette refinement OR the sprint that responds to a user complaint about button readability. Likely Sprint 9+ (UX hardening / a11y audit).
- **Status:** open.

---

## Design-tokens CSS variables (`tokens.css`) are dormant — not consumed by any `.vue` file

- **Where:** [`packages/design-tokens/tokens.css`](../packages/design-tokens/tokens.css) — defines `--color-bg-app`, `--color-text-primary`, `--color-action-primary`, etc. Imported by both SPAs' `main.ts` so the variables resolve at runtime, but a chunk-8.2 grep across `apps/main/src/**` and `apps/admin/src/**` for `var(--color-` returns zero matches (re-verified at the start of Group 2).
- **What we accepted in Sprint 1 chunk 8.1:** Components consume colors exclusively through Vuetify's theme (`color="primary"` props on Vuetify components, `var(--v-theme-*)` variables on scoped `<style>` blocks). The design-tokens CSS variables are unused at the SPA layer. The chunk-3 design-tokens package was structured assuming raw CSS-variable consumption would happen in parallel with Vuetify integration, but the team converged on Vuetify-only consumption during chunks 6–7.
- **Partially resolved in Sprint 1 chunk 8.2 (Group 2 of chunk 8):** the `@media (prefers-color-scheme: dark)` block was removed from `tokens.css` because Group 2's `useThemePreference` composable now reactively consults `window.matchMedia('(prefers-color-scheme: dark)')` and drives the active theme through `useTheme().setTheme()`. Two competing sources of truth would have been a hazard (`useTheme` is the architecture-test-enforced SOT). Removal was safe — zero `.vue` consumers of any `--color-*` variable. The `:root[data-theme='dark']` block above is left in place as a future opt-in path for any consumer that wants to drive palettes via a `data-theme` attribute on `<html>`.
- **Remaining concern:** the broader `--color-*` semantic-variable system (the `:root` and `:root[data-theme='light' | 'dark']` blocks in `tokens.css`) is still dormant. The two CSS-variable systems coexist in the runtime; the unused one occupies parser time and serializes into devtools.
- **Mitigation today:** None — the variables resolve correctly, just no one reads them. The chunk-8.1 architecture test (`no-hard-coded-colors.spec.ts`) explicitly allows `var(--v-theme-*)` references but does NOT enforce against `var(--color-*)` consumption (since none exists today). If a future contributor decides to wire `tokens.css` consumption back in, the test won't object.
- **Triggered by:** a future chunk that decides to consume `--color-*` directly (in which case standardize on one of the two systems and remove the other) — the `prefers-color-scheme` hazard sub-concern is closed by chunk 8.2.
- **Resolution options (remaining surface):**
  1. **Remove `tokens.css` entirely** and remove the import from both SPAs' `main.ts`. The brand/neutral CSS variables ARE technically useful for the rare case a component needs a raw value (logos, gradients), but the chunks 6/7/8 compliance shows that case has not arisen.
  2. **Keep `tokens.css` as a documented escape hatch** (logo gradients, marketing pages) and add an architecture test that allowlists known consumers. Today: zero allowlist entries.
  3. **Standardize on `tokens.css` + `data-theme` attribute** instead of Vuetify's theme system. Directly contradicts the chunk-8 kickoff's "extend Vuetify's existing theme system rather than building a parallel CSS variable layer" — would require revisiting the whole foundational decision.
- **Owner:** ~~the next chunk that surfaces a real `--color-*` consumer OR an explicit cleanup chunk.~~
- **Status:** **closed** in Sprint 3.5 Chunk 5 (W1) via resolution option 1 (remove the dormant `--color-*` layer). `tokens.css` was NOT deleted wholesale — it is kept as the carrier for the non-color-system tokens that the Vuetify theme layer doesn't own: `--brand-*` (incl. `--brand-aurora-*`, a live 4-surface consumer), `--radius-*`, `--space-*`, `--font-*`, and `--catalyst-typography-*`. The `main.ts` imports stay. Only the dormant, zero-consumer `--color-*` / `--neutral-*` semantic-variable layer was removed, so there is no longer a parallel-CSS-variable-system question. (The `@media` hazard sub-concern was resolved earlier in Sprint 1 chunk 8.2.)

---

## `useAgencyStore` direct localStorage usage

- **Where:** [`apps/main/src/core/stores/useAgencyStore.ts`](../apps/main/src/core/stores/useAgencyStore.ts).
- **What we accepted in Sprint 2 Chunk 2:** `useAgencyStore` calls `localStorage.{get,set,remove}Item` directly to persist the active agency ULID under `catalyst.agency.current`. The architecture test (`use-theme-is-sot.spec.ts`) normally forbids direct localStorage access to enforce that `useThemePreference` is the sole localStorage consumer — but explicitly allows non-theme uses via the allowlist + a tech-debt entry (per the test's own docblock).
- **Risk:** if a second store or composable also needs localStorage, the pattern will diverge further from the single-composable principle. Two ad-hoc localStorage users are harder to migrate to `IndexedDB` or server-side storage than one composable that all stores delegate to.
- **Mitigation today:** the storage key (`catalyst.agency.current`) is a named constant inside the store file. Migration is a single-file change.
- **Triggered by:** any Sprint 3+ task that introduces a second non-theme localStorage use.
- **Resolution:** extract a `useAgencyPreference` composable mirroring `useThemePreference`, and have `useAgencyStore` delegate to it. The composable becomes the new allowlist entry; the store no longer needs one.
- **Owner:** Sprint 3 (when workspace-switching becomes a real multi-agency feature).
- **Status:** open.

---

## Sprint 1 self-review §a inaccuracy — `agency_creator_relations` reconciliation

- **Where:** [`docs/reviews/sprint-1-self-review.md`](./reviews/sprint-1-self-review.md) §a, vs. the Sprint 3 Chunk 1 read pass.
- **What we accepted in Sprint 3 Chunk 1:** Sprint 1's self-review §a claimed `agency_creator_relations` shipped as part of the multi-tenancy primitives chunk. The Chunk 1 read pass (per standing standard #34, cross-chunk handoff verification) found NO migration, NO model, NO code references outside docs. Chunk 1 created the table from scratch in a single migration with the full P1 column set per spec §6 plus the Sprint-3 invitation columns. Without #34 the chunk would have built against a non-existent table and surfaced as a runtime migration error mid-build.
- **Risk:** the historical-record drift is benign now that the table exists, but the unreconciled review document misleads any future contributor doing Sprint 1 archaeology (e.g., debugging a multi-tenancy regression, writing a Sprint 1 retrospective, recreating the schema from review-history alone).
- **Resolution:** in a dedicated doc-cleanup pass, edit `docs/reviews/sprint-1-self-review.md` §a to note the actual handoff state (table NOT shipped; introduced in Sprint 3 Chunk 1 migration #14) and add a forward-pointer to the Sprint 3 Chunk 1 review file.
- **Owner:** any future doc-cleanup chunk OR the Sprint 4 kickoff read pass (if it surfaces this drift again).
- **Status:** open.

---

## Standards migration backlog — PROJECT-WORKFLOW.md §5 not yet authoritative

- **Where:** [`docs/PROJECT-WORKFLOW.md`](./PROJECT-WORKFLOW.md) §5 — "Standing standards" list.
- **What we accepted in Sprint 3 Chunk 1:** §5 at the time documented standards #5.1–#5.20, and this entry asserted the Sprint-2 self-review §b standards were not yet migrated. **That claim was already stale when written:** Sprint 2 §b's 7 patterns were in fact already present as **#5.11–#5.17**. (#5.18–#5.20 — CI-authoritative Pint, user-enumeration for preview/status endpoints, read-prior-review-before-merge — originated from the Sprint 1 chunk-7.1/8 reviews + the §g observations, not Sprint 2 §b.) The confusing parallel "#34 / #40 / #41 / #42" numbering used in the Sprint 3 chunk reviews was a legacy review-file scheme that maps cleanly onto the §5.x scheme:
  - **#34** (cross-chunk handoff verification) = **§5.11**
  - **#40** (defense-in-depth coverage) = **§5.17** — its "break-revert" connotation in the chunk reviews is captured separately as **§5.35** (architecture-test claim verification via break-revert)
  - **#41** (sandbox Pint not authoritative) = **§5.18**
  - **#42** (no enumerable identifiers) = **§5.19**
  - The legacy `#34/#40/#41/#42` labels are deprecated; cite the `§5.x` numbers going forward.
- **Resolution:** ~~Sprint 2 §b → §5.11–5.17 (already done before this entry).~~ Sprint 3 self-review §b's 16 patterns migrated to **§5.21–§5.36** in Sprint 3.5 Chunk 5 (W4.1). The §5 list is now authoritative through Sprint 3; the parallel numbering is normalized to `§5.x`.
- **Owner:** ~~dedicated housekeeping commit; Pedram drives.~~
- **Status:** **closed** in Sprint 3.5 Chunk 5 (W4.2). Sprint 2 §b confirmed already migrated (#5.11–5.17 — the original "un-migrated" claim was stale; #5.18–5.20 are Sprint 1 chunk-7.1/8 origin, not Sprint 2 §b); Sprint 3 §b migrated as #5.21–5.36; legacy `#34/#40/#41/#42` numbering normalized to `§5.x`.

---

## Integration driver env-var convention — INTEGRATIONS_DRIVER vs per-provider names

- **Where:** [`docs/06-INTEGRATIONS.md`](./06-INTEGRATIONS.md) §13.1 + [`apps/api/.env.example`](../apps/api/.env.example).
- **What we accepted in Sprint 3 Chunk 1:** Spec §13.1 names a single `INTEGRATIONS_DRIVER=mock` env var to control which family of provider adapters is bound. The actual `.env.example` uses per-provider variables (e.g. `KYC_PROVIDER=mock`, `ESIGN_PROVIDER=mock`, `STRIPE_PROVIDER=mock`). Sprint 3 Chunk 1 did not reconcile this divergence — Chunk 2 (when it binds Mock implementations behind a driver flag) is the natural decision point.
- **Risk:** Sprint 4+ ops automation that sets the integration driver may reach for `INTEGRATIONS_DRIVER` first and silently fall back to the deferred stubs because nothing reads it.
- **Resolution:** Chunk 2 picks one of the two conventions and standardises across spec + `.env.example` + AppServiceProvider binding. Worth landing in 06-INTEGRATIONS.md §13.1 after Chunk 2's decision.
- **Owner:** Sprint 3 Chunk 2 read pass.
- **Status:** **closed** in Sprint 3 Chunk 2 sub-step 4 — Q-driver-convention = per-provider env vars. `config/integrations.php` reads `KYC_PROVIDER`, `ESIGN_PROVIDER`, `PAYMENT_PROVIDER` (all default `mock`); `CreatorsServiceProvider::makeProviderResolver()` consumes them via `config('integrations.<kind>.driver')`. Mixed-vendor staging environments are tractable (KYC live + e-sign mock + payment mock). The `INTEGRATIONS_DRIVER` variable is NOT read anywhere; the per-provider names are the canonical convention. Documented in `docs/feature-flags.md` "Driver convention" bullet. Spec §13.1 in `docs/06-INTEGRATIONS.md` should be updated in a doc-cleanup pass to match (deferred — content drift, no code impact).

---

## Resume UX bootstrap shape — admin/creator endpoint symmetry pending

- **Where:** [`apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php`](../apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php) + (future) admin endpoint at `GET /api/v1/admin/creators/{creator}`.
- **What we accepted in Sprint 3 Chunk 1:** Q2 (resume UX) demands a stable bootstrap response shape between `GET /api/v1/creators/me` and `GET /api/v1/admin/creators/{creator}` (both consumed by Chunk 3's admin pending-approval pane). Chunk 1 ships only the creator-facing endpoint; the admin endpoint is Chunk 3 scope. The shared `CreatorResource` is structured to satisfy both call sites — but no admin-route consumer has yet validated the assumption that the admin view needs zero additional fields beyond what `CreatorResource` exposes today (admin view DOES need `rejection_reason` + `kyc_verifications` history per spec §6.2).
- **Risk:** Chunk 3 may discover that admin needs additional fields, requiring either (a) a `withAdmin()` factory method that conditionally appends fields, or (b) a dedicated `AdminCreatorResource` that breaks the symmetry promise.
- **Mitigation today:** `CreatorResource` is a single class with a single `toArray()` shape. Adding admin-only fields via a factory method is a one-file change.
- **Resolution:** Chunk 3 implements the admin endpoint and either extends `CreatorResource` with a factory method (preferred for shape symmetry) or creates an `AdminCreatorResource` (acceptable but incurs a one-time review cost on the symmetry rationale).
- **Owner:** Sprint 3 Chunk 3.
- **Status:** **closed** in Sprint 3 Chunk 3 sub-step 1 via the symmetric-factory shape. [`CreatorResource`](../apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php) exposes an instance method `withAdmin(bool $isAdmin = true): self` that flips a private `$isAdmin` flag; `toArray()` reads the flag and conditionally appends an `admin_attributes` block carrying `rejection_reason` + `kyc_verifications` history. The creator-self call site (`GET /api/v1/creators/me`) emits the base shape; the admin call site (`GET /api/v1/admin/creators/{creator}`, sub-step 9) chains `->withAdmin(true)` before `->response()`. Symmetric — same class, same `toArray()`, one extra field group conditional on the flag. No `AdminCreatorResource` subclass was needed; the symmetry promise from Chunk 1 holds. Coverage: [`AdminCreatorShowTest`](../apps/api/tests/Feature/Modules/Creators/Admin/AdminCreatorShowTest.php) asserts the admin response carries both the creator-self block AND the `admin_attributes` block; [`CreatorWizardEndpointsTest`](../apps/api/tests/Feature/Modules/Creators/CreatorWizardEndpointsTest.php) verifies the creator-self response does NOT carry `admin_attributes`. The forward-compat `kyc_verifications` shape strips PII (newest-first, status + provider + timestamps only) so Phase-2 admin pages can render the history without touching the underlying table.

---

## Sprint 3 Chunk 3 — `lastActivityAt` is approximated via `creator.updated_at`

- **Where:** [`apps/main/src/modules/onboarding/stores/useOnboardingStore.ts`](../apps/main/src/modules/onboarding/stores/useOnboardingStore.ts) — `lastActivityAt = creator.value?.attributes.updated_at`. The Welcome Back UI surface reads this through `storeToRefs` at [`apps/main/src/modules/onboarding/pages/WelcomeBackPage.vue`](../apps/main/src/modules/onboarding/pages/WelcomeBackPage.vue) and feeds it into the `timeAgoCopy()` helper that picks the "minutes / hours / days" bucket for the orientation string.
- **What we accepted in Sprint 3 Chunk 3 (Refinement 6):** The Welcome Back page's "you were here X ago" copy is derived from a `last_activity_at` field that maps to `Creator::updated_at`. The mapping is structurally correct for the orienting copy ("you last submitted a wizard step 2 hours ago") but does NOT capture passive engagement ("you were viewing the wizard 30 minutes ago without submitting"). For the Welcome Back UI surface itself the distinction does not matter; the copy is meant to re-orient a returning creator, not provide audit-grade activity tracking.
- **Risk:** Two latent surfaces that would care about the distinction —
  1. **Analytics-driven UX.** Sprint 6+ funnel analytics that want "draft saved" vs "viewed" engagement metrics on the wizard cannot use `updated_at` (the former updates only on save).
  2. **Draft-saved messaging.** A future "you have unsaved changes from your last session" surface needs a separate signal.
- **Mitigation today:** None — the approximation is correct for the current consumer (Welcome Back orientation copy). Documented inline in `WelcomeBackPage.vue` and `CreatorResource` so a future reader sees the trade-off.
- **Triggered by:** the next chunk that adds a wizard-analytics surface (likely Sprint 6+) OR the chunk that adds "you have unsaved changes" messaging (likely Sprint 4+).
- **Resolution:** Introduce a dedicated `Creator::last_seen_at` (or similar) column updated on every authenticated wizard route hit via middleware (e.g. `TouchCreatorLastSeen` on the `creators.me.*` route group). The migration is additive (nullable column, no backfill needed). Frontend reads the new field from the bootstrap response and the docblock in `WelcomeBackPage.vue` is updated to reflect the precise signal. Note: a per-request DB write is a real cost; if Sprint 6 finds it shows up in p95 latency, batch via Redis pipeline (write on every request, persist every N seconds) — same pattern as `last_login_at` deferred-touch in Sprint 1 chunk 6.
- **Owner:** the sprint that introduces a triggering surface.
- **Status:** open. Surfaced by Refinement 6 in the Sprint 3 Chunk 3 plan-approval.

---

## Forgot-password user-enumeration defense regression — surfaced by Sprint 3 Chunk 1 bulk-invite

- **Where:** [`apps/api/app/Modules/Identity/Services/PasswordResetService.php::request()`](../apps/api/app/Modules/Identity/Services/PasswordResetService.php) + [`apps/api/app/Modules/Creators/Routes/api.php`](../apps/api/app/Modules/Creators/Routes/api.php) (the `creators.me.*` route group's missing `verified` middleware).
- **What we accepted in Sprint 3 Chunk 1:** `PasswordResetService::request()` does NOT check `User::email_verified_at` before issuing a reset token. Pre-Sprint-3 this was a latent gap — there was no Eloquent path to create an unverified User without going through the verify-email flow (`SignUpService` immediately queues the verification mail; `EmailVerificationService::verify()` is the only writer to `email_verified_at`). Sprint 3 Chunk 1's `BulkInviteService` is the first surface that creates User rows with `email_verified_at = null` outside the verify-email flow, exposing the gap.
- **Risk:** An attacker who knows or guesses an invited email can trigger a forgot-password mail to that invitee's inbox before the legitimate invitee consumes the magic link. If the attacker (or the invitee racing the attacker) completes the reset, they authenticate the User row WITHOUT consuming the invitation token. The `AgencyCreatorRelation` stays `prospect` indefinitely; the agency is unaware. Wizard routes use `auth:web` not `verified`, so the unverified-via-reset User has full wizard access. Net effect: bulk-invite becomes a user-confusion / spam vector (P1) and an invitation-bypass vector (P2). Regression of standing standard #9 (user-enumeration defense across the auth surface).
- **Mitigation today:** None — the gap exists and is exploitable as soon as the Chunk 2 frontend ships the magic-link landing page. The throwaway password itself (256-bit random hex, Argon2id-hashed) is not the weakness; the password broker is.
- **Resolution:** Sprint 3 Chunk 2 P1 blocker (see [`docs/reviews/sprint-3-chunk-1-review.md`](./reviews/sprint-3-chunk-1-review.md) → "P1 blockers for Chunk 2"). Concretely: (a) `PasswordResetService::request()` returns silently when `User::email_verified_at IS NULL`, (b) wizard routes add the `verified` middleware alongside `auth:web`, (c) break-revert independent unit coverage per #40 for both gates, (d) `PasswordResetServiceTest` gets a `"returns silently for unverified users"` case.
- **Sprint 4 close** to retrospective the full standing-standard #9 surface — Sprint 1 + Sprint 2 auth-surface review didn't catch this latent gap; the retrospective audits every endpoint that issues a credential / token / link to a User, against the verified-email gate.
- **Owner:** Sprint 3 Chunk 2 (immediate fix); Sprint 4 close (#9 surface retrospective).
- **Status:** **closed** in Sprint 3 Chunk 2 sub-step 1 (commit `6c76425`). `PasswordResetService::request()` now short-circuits with `if ($user->email_verified_at === null) { return; }` keeping the user-enumeration-defense response shape intact (204 either way, behaviour diverges server-side). Wizard routes (`creators.me.*` group) gained the `verified` middleware. Break-revert coverage landed in `PasswordResetTest` (`returns silently for unverified users`) and `CreatorWizardVerifiedGateTest` (3 tests covering rejection, admission, and source-inspection of the middleware on every wizard route). The Sprint 4-close retrospective on the full #9 surface remains a separate open task.

---

## tenancy.md § 4 categorization sloppy — three categories collapsed into one

- **Where:** [`docs/security/tenancy.md`](./security/tenancy.md) § 4.
- **What we accepted in Sprint 3 Chunk 1 (F1):** The cross-tenant route allowlist in § 4 collapses three semantically distinct categories into one ("cross-tenant"): **(a) cross-tenant** (admin tooling that legitimately spans tenants — e.g. platform-admin agency-listing), **(b) tenant-less** (no tenant data — liveness, public preview), **(c) path-scoped tenant** (tenant resolved from URL path param, not session — e.g. `POST /api/v1/agencies/{agency}/invitations`). The doc's invariant ("every cross-tenant route MUST appear in the allowlist below") is correct but the table justifications now mix three rationales. Sprint 3 Chunk 1's F1 fix added the categorization note inline but deferred the structural Category column.
- **Risk:** A future contributor reads the table, sees a `creators/me` row justified as "Creator is a global entity" and a Sprint 2 invitation row justified as "agency resolved from path param", and may not realize they belong to different categories with different security review requirements. Auditors reading the doc see a flat list and may miss that the no-context contract MEANS something different per category.
- **Resolution:** Dedicated housekeeping commit before Sprint 4 kickoff. (1) Add a `Category` column to the table, (2) recategorize all existing rows, (3) audit every route in the codebase against the allowlist (the F1 audit found 3 missing routes — there may be more), (4) add per-category security-review guidance to the prose around § 4.
- **Owner:** dedicated housekeeping commit; Pedram drives.
- **Status:** open.

---

## Provider contract test "exactly one Sprint-3 method" broken by design when Chunk 2 lands

- **Where:** [`apps/api/tests/Feature/Modules/Creators/IntegrationProviderBindingsTest.php`](../apps/api/tests/Feature/Modules/Creators/IntegrationProviderBindingsTest.php) → `"the three contracts each define exactly one Sprint-3 method"`.
- **What we accepted in Sprint 3 Chunk 1:** The contract test pins the Sprint-3 surface at exactly one method per contract (`initiateVerification`, `sendEnvelope`, `createConnectedAccount`). When Chunk 2 extends the contracts to add status-check methods (`getVerificationStatus`, `getEnvelopeStatus`, `getAccountStatus`) and webhook methods (`parseWebhookEvent`, `verifyWebhookSignature`), this test MUST fail. The break is intentional: it forces the Chunk 2 author to update the assertion in lockstep with the contract extension, preventing accidental contract growth.
- **Risk:** A Chunk 2 author confused by the failure may simply remove the assertion rather than replace it with the new "Sprint-3-completion surface" enumeration, eroding the contract-shape pin entirely.
- **Mitigation today:** The test docblock and this tech-debt entry call out the by-design break + the replacement assertion shape (`"each contract has the Sprint-3-completion surface"` with explicit method-name enumeration matching the chosen wizard-completion architecture per the Sprint 3 Chunk 1 review's "Honest deviations" → "Provider contract surface narrowed from kickoff").
- **Resolution:** Chunk 2 close: update the assertion to enumerate the new contract methods explicitly. Pin both the Sprint-3-initiate methods AND the new completion methods.
- **Owner:** Sprint 3 Chunk 2.
- **Status:** **closed** in Sprint 3 Chunk 2 sub-step 3. The contract test now pins the Sprint-3-completion surface (KYC: 4 methods, eSign: 4 methods, Payment: 2 methods — total 10) via `the three contracts each define exactly the Sprint-3-completion surface (...)`. The reset is paired with a `each contract docblock cites the Sprint-3 completion surface` source-inspection check so the docblock + the test stay in lockstep across future contract extensions. The Sprint 3 Chunk 1 docblocks naming the future-extension methods (`getVerificationResult(string $sessionId): KycResult` etc.) drifted from Chunk 2's actual shape (`getVerificationStatus(Creator): KycStatus`); see the new tech-debt entry "Sprint 3 Chunk 1 contract docblocks describe an outdated future-extension shape" below for the cleanup follow-up.
  - **Sprint 4 Chunk 2 update (2026-06-02) — the predicted break fired and was handled correctly.** Adding the real Stripe adapter extended `PaymentProvider` 2→4 methods (`verifyWebhookSignature` + `parseWebhookEvent`), which broke the enumerated contract-shape assertion exactly as this entry warned. The assertion was **updated in lockstep, not deleted or weakened**: it now reads `the three contracts each define exactly their built surface (KYC: 4, eSign: 4, Payment: 4)` and enumerates Payment's four methods (`createConnectedAccount`, `getAccountStatus`, `parseWebhookEvent`, `verifyWebhookSignature`) — **total 10 → 12**. It still uses an exact `toBe` match on the sorted public-method list per contract, so it still fails if any method is added or removed. The paired docblock source-inspection check (`each contract docblock documents its built surface for #34 cross-chunk handoff verification`) was updated in lockstep too — Payment now pins `Inbound-webhook surface (Sprint 4 Chunk 2` while KYC/eSign keep `Sprint 3 completion surface`. The pinned surface is therefore now **"Sprint-3-completion + Chunk-2 webhook"** for Payment (Sprint-3-completion for KYC/eSign). The contract-growth guard remains fully intact. See [`docs/reviews/sprint-4-chunk-2-review.md`](reviews/sprint-4-chunk-2-review.md).

---

## Sprint 3 Chunk 1 contract docblocks describe an outdated future-extension shape

- **Where:** Existing docblocks on [`apps/api/app/Modules/Creators/Integrations/Contracts/KycProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/KycProvider.php), [`apps/api/app/Modules/Creators/Integrations/Contracts/EsignProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/EsignProvider.php), [`apps/api/app/Modules/Creators/Integrations/Contracts/PaymentProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/PaymentProvider.php) — the chunk-2 sub-step 3 review-file deviation D-pause-2-2 (Refinement 1 in the chunk-2 plan-approval).
- **What we accepted in Sprint 3 Chunk 2:** The Chunk 1 docblocks committed to a future-extension shape that named methods `getVerificationResult(string $sessionId): KycResult` and `getEnvelopeStatus(string $envelopeId): EnvelopeStatus`. Chunk 2's hybrid-completion-architecture decision picked structurally-better names: `getVerificationStatus(Creator): KycStatus` and `getEnvelopeStatus(Creator): EsignStatus` (Creator is the durable identity in our domain; session/envelope IDs are vendor-side ephemera). Chunk 2 surfaced this as honest deviation D-pause-2-2 in the sub-step-3 review section rather than silently overriding (#34 cross-chunk handoff verification). The Chunk 1 docblocks now describe a method shape that no longer matches the actual implementation — a low-severity historical-record drift but an active confusion source for any future engineer reading the Chunk 1 docblocks first.
- **Risk:** A Sprint 4+ engineer reading the Chunk 1 docblocks (e.g., during a real-vendor adapter implementation) may write code against `getVerificationResult(string $sessionId)` and discover the divergence only at compile time. Low-severity (compile error fails fast) but adds friction.
- **Resolution:** A doc-cleanup pass (NOT a feature chunk — content-only) updates the three contract docblocks to reflect the actual Chunk 2-implemented shape, with a forward-pointer to the chunk-2 sub-step-3 review section explaining why the names changed. The chunk-2 sub-step 3 review section is the canonical record for the rename rationale.
- **Owner:** Dedicated doc-cleanup commit; can land alongside any future contract-touching chunk.
- **Status:** open. Surfaced by Refinement 1 in the Sprint 3 Chunk 2 plan-approval.

---

## Residual Playwright-retry flakiness on chunk-7.1 + chunk-7.6 + chunk-3 wizard specs

- **Where:** [`apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts`](../apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts) → `spec #19 — 2FA enrollment + sign-in › full enrollment + re-sign-in flow`. [`apps/admin/playwright/specs/admin-mandatory-mfa-enrollment.spec.ts`](../apps/admin/playwright/specs/admin-mandatory-mfa-enrollment.spec.ts) → `D7 deep-link to /settings is preserved across the MFA enrollment redirect`. [`apps/main/playwright/specs/creator-wizard-happy-path.spec.ts`](../apps/main/playwright/specs/creator-wizard-happy-path.spec.ts) → `full wizard traversal …` at the Step 7 (payout) → Step 8 (contract) hop.
- **What we accepted in Sprint 3 Chunk 2 sub-step 1 push (CI run #76, commit `6c76425`):** Both Playwright specs occasionally fail on first attempt and pass on retry under Playwright's `retries: 2`. Job exit code is 0 (CI green) but GitHub Actions surfaces the first-attempt failures as run annotations (the chunk-2 sub-step-1 push showed 2 errors / 4 warnings / 2 notices despite a green job). The underlying surfaces these specs cover were closed in Sprint 1 chunk 7.1 (spec #19 — see this file's chunk-7.1 entry above) and chunk 7.6 (the admin D7 deep-link — see the e2e-admin / admin-mandatory-mfa entry above). What's flaky now is the test layer itself, not the system under test — likely a race condition between Playwright's navigation expectations and the SPA's bootstrap-vs-redirect interleaving.
- **What we accepted in Sprint 3 Chunk 4 post-merge CI annotation review (CI run 25934883993, commit `93e751a` doc-only push):** The chunk-3 wizard happy-path spec timed out at the payout → contract hop on attempt #1 (`TimeoutError: page.waitForURL: Timeout 30000ms exceeded`) and passed on retry, marked `1 flaky` in the Playwright summary. The chunk-3 fix commit `7fcb43f` had already pinned this hop as `Promise.all([waitForURL, click])` with a 30s budget, naming the "click during re-render flush + router push lost the race" symptom from chunk-3 CI run 25896340741. **The recurrence on run 25934883993 reveals the chunk-3 fix targeted the wrong layer.** The first cut at a root cause was **Vite dev-server cold-chunk compile latency stacked on top of the guard's `bootstrap()` call**: the payout → contract hop is the spec's first navigation into the `Step8ContractPage` route chunk, which has a heavy transitive import graph (`ContractStatusBadge` from `@catalyst/ui`, `ClickThroughAccept`, `useVendorBounce`). First mitigation: bump the `waitForURL` timeout from 30s → 60s on the payout → contract hop only (commit `8b35a3e`), with a docblock naming the cold-chunk + bootstrap framing so the next maintainer doesn't repeat the chunk-3 fix's mis-diagnosis.
- **What we accepted in Sprint 3 Chunk 4 post-merge addendum #2 (CI run 25936109470, commit `8b35a3e`):** The 60s budget did NOT catch the flake. Same spec, same hop, same `1 flaky` annotation. The CI log's `waitForURL` trace exposes the actual signal — three identical `navigated to "http://127.0.0.1:5173/onboarding/payout"` entries inside the 60s wait window, where one would expect zero (the page is already on `/onboarding/payout` when the click fires). **That's a re-entrant navigation pattern, not chunk-compile latency.** The first-cut "cold-chunk + bootstrap" framing was directionally right (something async stalls the navigation) but missed that the URL is being actively re-asserted as `/onboarding/payout` during the wait, which a longer timeout cannot fix. The `trace.zip` Playwright generates for the failed attempt was on disk in the runner's `apps/main/test-results/.../trace.zip` but never uploaded (the CI workflow's `Upload Playwright report` step was gated on `if: failure()` and the job's overall conclusion was `success` because attempt #2 passed under `retries: 2`), so post-hoc forensic inspection of the failed attempt's network calls + router events was not possible. Two-fold chunk-4 follow-up mitigation:
  1. **In-spec retry on the payout → contract hop** (commit subject `test(playwright): in-spec retry on payout-contract hop + always-upload artifacts` — see the docblock comment at [`creator-wizard-happy-path.spec.ts`](../apps/main/playwright/specs/creator-wizard-happy-path.spec.ts) around the `advanceToContract` helper). The pattern preserves the chunk-3 race-safe `Promise.all([waitForURL, click])` shape with a 30s leg-budget AND folds in the step-contract visibility assertion, then retries the same block once on `catch`. Effect: the spec passes on its FIRST Playwright attempt (the second click reliably navigates once whatever async state caused the re-entrant navigation has settled, AND the second assertion catches the bounce-variant where URL advances to /contract briefly before bouncing back to /payout), so Playwright's `retries: 2` doesn't trigger and the `github` reporter doesn't emit the `##[error]` annotation that was surfacing on the run-details page even when CI was green. Leg-budget stays honest at 30s + 10s × 2 = 80s total so a real navigation regression still surfaces fast. Local forensic trace exposed a SECOND variant beyond the CI signature — URL bounces /payout↔/contract within ~30ms after the click (timestamps 22008.828/22008.854/22032.098/22035.808 in `apps/main/test-results/.../trace.zip`); wrapping the visibility assertion inside the retry block catches both variants.
  2. **Always upload Playwright artifacts** ([`.github/workflows/ci.yml`](../.github/workflows/ci.yml) — both the `e2e-main` and `e2e-admin` `Upload Playwright report` steps now use `if: always()` + an extended path that includes `test-results/` alongside `playwright-report/`, with `if-no-files-found: ignore`). `retain-on-failure` traces are kept on disk for failed ATTEMPTS even when the overall test is flaky-but-passed, so the artifact bundle will now contain the next flake's `trace.zip` automatically without a re-push. This unblocks the structural-root-cause investigation (option (4) below) — the next time the spec flakes on `main`, the trace will be inspectable directly from the run's artifacts.
     Both changes are deliberately additive: they suppress the immediate annotation-noise symptom AND provision for the next round of investigation, without committing to a specific structural fix until we have the trace evidence to choose between the candidate root causes (cold-chunk compile, guard re-entrancy, Vuetify v-btn double-fire, or something else).
- **Risk:** Two-fold:
  1. **Diagnostic noise in CI annotations.** Reviewers (Claude in particular) reading a green CI summary still see "2 errors" in red and have to double-click into the annotations to confirm they're retry-passes. This adds a small but recurring cognitive-overhead tax on every chunk-close review.
  2. **Latent regression masking.** With `retries: 2`, a real regression in either spec's surface needs to fail THREE consecutive times to surface — a regression with a 30% reproduction rate would show up green on CI ~66% of the time. Acceptable today because the underlying surfaces have orthogonal Pest coverage (`TwoFactorEnrollmentService` Pest tests + `IssueTotpController` Pest tests + the chunk-7.6 `admin-mandatory-mfa-enrollment.spec.ts` has chunk-7.1-saga isolation per its kickoff) but worth tightening when the trigger condition fires.
- **Mitigation today:** Chunk-4 addendum-#2 mitigation supersedes the first cut: the chunk-3 wizard spec's payout → contract hop is now wrapped in an in-spec retry (`advancePayout()` helper, 30s × 2 budget) so the spec passes on its first Playwright attempt and the `github` reporter never emits a per-attempt `##[error]` annotation. The earlier 60s single-leg budget is reverted as part of the same change — it didn't catch the flake and obscured the per-leg signal. CI's `Upload Playwright report` step is now `if: always()` for both SPAs, so the next flake's `trace.zip` (and `error-context.md`, video, screenshots) is automatically uploaded for forensic inspection. The chunk-7.1 + chunk-7.6 specs remain untouched — their failure pattern is a navigation-vs-bootstrap race (not chunk-fetch latency, not the chunk-3 re-entrant-navigation pattern), and increasing their budget OR wrapping them in spec-side retries would mask real regressions in the redirect chain. Logged here so the pattern doesn't get lost across chunks.
- **Triggered by:** Either (a) a new chunk that touches the 2FA enrollment flow OR the admin deep-link redirect surface (forces investigation of the spec's first-attempt failure mode before stacking new behaviour on top), OR (b) the next dedicated test-infrastructure-hardening sub-chunk (forensic pass on the first-attempt failure traces from the last ~10 CI runs to identify the race), OR (c) an actual regression slipping past the retries (highest-cost trigger; would surface as a real failure on all 3 attempts), OR (d) another wizard route chunk's first-navigation hop starts flaking the same way the payout → contract hop did — Vite cold-chunk latency is route-chunk-specific and the next heavy route added to the wizard could hit the same wall.
- **Resolution options (any one is sufficient):**
  1. **Tighten the spec's selectors / waits.** Replace bare `getByRole(...).click()` patterns with `await expect(locator).toBeAttached()` + explicit `waitForURL(...)` before driving the next interaction. Both specs predate the chunk-7.1 saga conventions for shared fixtures + per-spec rate-limiter neutralisation; bringing them up to current standards may be enough. (Applies to the chunk-7.1 / chunk-7.6 race-flake leg; the chunk-3 wizard leg's root cause is structural — see option (4) below.)
  2. **Drop `retries` to 0 or 1 once the underlying flake is fixed.** This re-tightens the regression-detection budget back to the original chunk-7.1 design.
  3. **If the flake is a Playwright/Vite-dev-server interaction that can't be eliminated:** add a per-spec stability harness that re-attempts only the navigation step, not the entire spec, so a real regression in the assertion phase still surfaces immediately.
  4. **For the chunk-3 wizard cold-chunk leg specifically:** pre-warm the route chunks before the wizard traversal. Two ways: (a) issue a `page.goto('/onboarding/contract')` (and any other heavy route chunks) once before the actual traversal to force Vite to compile them, then navigate back to `/onboarding` — costs a few seconds upfront but eliminates per-hop chunk-fetch latency stacking; or (b) configure `vite preview` in the Playwright `webServer` block instead of `vite` dev, so the chunks are pre-built. The (b) option also makes the spec exercise the production-shape bundle, not the dev-only HMR-instrumented one.
- **Owner:** Whichever sprint hits the trigger condition first. Likely Sprint 4+ when the real-KYC adapter or admin SPA chunks land. The cold-chunk leg specifically becomes a stronger ownership signal if a second wizard route starts exhibiting the same first-hop flake — at which point option (4)(b) (vite preview in CI) becomes the structural fix worth landing.
- **Status:** open. Surfaced by the chunk-2 sub-step-1 CI annotations review; extended by the chunk-3 wizard payout → contract recurrence on the Sprint 3 Chunk 4 post-merge CI annotation review (run 25934883993); extended again by the same recurrence on run 25936109470 despite the 60s mitigation, which exposed the re-entrant-navigation signal (three same-URL `navigated to /onboarding/payout` entries inside the wait window) and reframed the suspected root cause. Chunk-4 addendum-#2 mitigation (in-spec retry + always-upload artifacts) is in place. The structural root-cause work is unblocked but deferred until the next flake's trace artifact lands on `main` — at which point the trace's network panel + router events should let us pick between the candidate causes (cold-chunk compile, guard re-entrancy, Vuetify v-btn double-fire). Route-chunk pre-warm OR `vite preview` in CI (option (4)) remains the structural fallback if the trace confirms cold-chunk latency.

---

## SimulateKycWebhookJob + SimulateEsignWebhookJob have no unit tests for their `handle()` glue

- **Where:** [`apps/api/app/Modules/Creators/Jobs/SimulateKycWebhookJob.php`](../apps/api/app/Modules/Creators/Jobs/SimulateKycWebhookJob.php) + [`apps/api/app/Modules/Creators/Jobs/SimulateEsignWebhookJob.php`](../apps/api/app/Modules/Creators/Jobs/SimulateEsignWebhookJob.php). The corresponding `tests/Feature/Modules/Creators/Jobs/SimulateKycWebhookJobTest.php` + `SimulateEsignWebhookJobTest.php` are absent — the simulate jobs are never exercised at the unit-of-the-job-handle layer in the chunk-2 test suite. Surfaced by the Sprint 3 Chunk 2 pre-merge spot-check S3 (Q-mock-webhook-dispatch implementation verification).
- **What we accepted in Sprint 3 Chunk 2 sub-step 5:** The simulate-job glue layer is integration-tested end-to-end via two adjacent test surfaces — [`MockVendorPagesTest`](../apps/api/tests/Feature/Modules/Creators/MockVendor/MockVendorPagesTest.php) asserts the controller dispatches the right job with the right `creatorUlid` + `outcome` shape (`Bus::assertDispatched(SimulateKycWebhookJob::class, fn ($job) => $job->creatorUlid === ... && $job->outcome === 'verified')`), and [`KycWebhookControllerTest`](../apps/api/tests/Feature/Modules/Creators/Webhooks/KycWebhookControllerTest.php) + [`EsignWebhookControllerTest`](../apps/api/tests/Feature/Modules/Creators/Webhooks/EsignWebhookControllerTest.php) assert the receive-side controller flow through `InboundWebhookIngestor::ingest(...)`. What's NOT exercised is `$job->handle($ingestor, $provider)` itself — the glue closure that synthesises the payload, signs it with `MockKycProvider::webhookSecret()`, and calls the ingestor with the right shape (provider name, signatureVerifier, payloadParser, jobDispatcher closures).
- **Risk:** A regression in the simulate-job glue layer surfaces only via the integration-tested end-to-end bounce, not at the job level itself. Concrete failure modes the gap allows:
  1. **Wrong provider name** — a typo like `provider: 'esign'` in `SimulateKycWebhookJob::handle()` would land an esign IntegrationEvent row from a KYC simulation. The receive-side controller test wouldn't catch it because `KycWebhookControllerTest` doesn't drive through the simulate path; the dispatch-side `MockVendorPagesTest` wouldn't catch it because it stops at `Bus::assertDispatched` (the job is faked). The error would only surface in production when the row's `provider` column doesn't match the URL the webhook came in on, or in a hypothetical chunk-3 Playwright spec that drives the full bounce.
  2. **Wrong field name on the parsed event** — e.g., `parseWebhookEvent($p)->providerEventId` rename to `eventId` without updating the simulate path's `InboundWebhookPayload` construction. Same diagnostic blast radius.
  3. **Missing or wrong `jobDispatcher` closure** — e.g., the simulate path dispatches `ProcessEsignWebhookJob` instead of `ProcessKycWebhookJob`. The dispatched job's signature would differ at runtime; the wizard-completion-state-update path would silently do the wrong thing.
- **Mitigation today:** The integration-tested coverage at the controller + page layers covers the surface end-to-end (mock-vendor button → simulate job dispatch shape → controller webhook receipt → ingestor → process job dispatch). A regression in the simulate-job glue would surface — but at the integration layer, not at the unit-of-the-job-itself layer, which makes diagnosis costlier. PHPStan level 8 catches signature-incompatible changes (e.g., wrong type passed to `$ingestor->ingest(...)`).
- **Triggered by:** The next chunk that touches the simulate-job glue layer — likely Sprint 4+ when real-KYC vendor adapters land and need to mirror the simulate path's shape OR Sprint 7 if the payout flag flip uncovers a Stripe-mock simulate-path requirement OR any chunk that adds a new mock vendor (the simulate-job pattern would be copied; the unit coverage gap propagates).
- **Resolution:** Add `apps/api/tests/Feature/Modules/Creators/Jobs/SimulateKycWebhookJobTest.php` + `SimulateEsignWebhookJobTest.php` that:
  1. Run `$job->handle($ingestor, $provider)` against the real `InboundWebhookIngestor` (with `Bus::fake()` to capture the downstream `Process*WebhookJob` dispatch), and assert the right `IntegrationEvent` row was inserted with `provider === 'kyc'` (or `'esign'`), the expected `event_type`, and a `provider_event_id` matching the synthesised `mock_evt_kyc_*` / `mock_evt_esign_*` shape.
  2. Assert the dispatched `ProcessKycWebhookJob` (or `ProcessEsignWebhookJob`) carries the expected `IntegrationEvent::id` payload — the wire between the ingestor and the per-provider process job.
  3. Include a negative case that explicitly proves a typo like `provider: 'esign'` in `SimulateKycWebhookJob::handle()` would fail the assertion — i.e., the test asserts on the provider value with a strict-equality check that would surface the typo, not a loose `IntegrationEvent::query()->count() > 0` check that would pass either way.
- **Owner:** the sprint that hits the trigger condition first. Likely Sprint 4+ when the real-vendor adapter pattern needs the simulate-shape regression baseline.
- **Status:** open. Surfaced by the Sprint 3 Chunk 2 pre-merge spot-check S3.

---

## Avatar requirement in CompletenessScoreCalculator vs Step 2 form validation

- **Where:** [`apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php`](../apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php) → `isProfileComplete()` requires `avatar_path !== null` as one of its five gating conditions. [`apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`](../apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue) → the `AvatarUploadDrop` field is optional in the form; the `profile-submit` button has no client-side gate on `avatar_path` being set. The two layers disagree on what "Step 2 complete" means: the SPA accepts a profile with no avatar and advances to Step 3, while the backend's wizard-completeness calculator considers the same profile incomplete.
- **What we accepted in Sprint 3 Chunk 3:** The Chunk 3 happy-path Playwright spec ([`apps/main/playwright/specs/creator-wizard-happy-path.spec.ts`](../apps/main/playwright/specs/creator-wizard-happy-path.spec.ts)) was failing CI (run 25896340741) because it submitted Step 2's text fields without uploading an avatar, traversed every subsequent step client-side, and then failed at Step 9 because `incompleteSteps` kept `'profile'` in the list — leaving the Submit button perpetually disabled past the `toBeEnabled` poll. Spec-side fix landed in commit `7fcb43f`: a `seedAvatar(page)` helper that primes an avatar via `POST /api/v1/creators/me/avatar` before the wizard traversal, mirroring the existing `seedPortfolioImage(page)` shape. Product-level fix (aligning the two layers' definition of "Step 2 complete") is deferred.
- **Risk:** A creator using the SPA who completes Step 2 without an avatar can reach Step 9 with the Submit button perpetually disabled and no clear UX cue why. The Step 2 row in the Review summary shows as complete-looking (the row's display logic mirrors form-submit semantics), but `incompleteSteps`' derivation from the backend calculator still includes `profile`. Diagnostic blast radius: a confused creator either abandons the wizard or contacts support, and support has to read the bootstrap response's `wizard.steps[].is_complete` array to figure out which gating field is missing.
- **Mitigation today:** The spec fix unblocks CI. Real-world creators hitting this would see a stuck Submit button without an inline explanation. Frequency depends on whether creators tend to upload avatars during onboarding — likely most do (the field is visually prominent in Step 2), but there is no enforcement, so a meaningful tail will hit the failure mode.
- **Triggered by:** A real creator hits the stuck-Submit failure mode (probable trigger; surfaces as a support ticket), OR a Sprint 4 UX polish pass picks it up during onboarding-funnel analytics review, OR Chunk 4's bulk-invite UI surfaces a similar wizard-traversal pattern that forces consolidation of the per-step completeness contract.
- **Resolution:** Three options, any one is sufficient:
  1. **Make avatar optional in `CompletenessScoreCalculator::isProfileComplete()`** — simplest; aligns the layers downward; trusts the SPA's form validation. Drops the avatar weight off the profile-step completion check; the `score()` 0–100 derivation already handles missing optional fields. Lean.
  2. **Make avatar required in Step 2's form validation** — tightens the SPA to match the backend. UX speed bump (creators cannot advance Step 2 without an avatar), but the contract is enforced at the earliest layer and the stuck-Submit failure mode is impossible by construction.
  3. **Surface "Step 2 incomplete (avatar missing)" in Step 9's review UI with a deep-link back to Step 2.** Keeps both layers' current semantics but adds a self-service diagnostic path so the stuck-Submit state is recoverable without a support ticket.
- **Owner:** Sprint 4 polish OR whichever chunk surfaces the failure mode in production first.
- **Status:** open. Surfaced by CI run 25896340741 during Sprint 3 Chunk 3 close.

---

## BulkInvitePage exposes `onFileSelected` to bypass Vuetify VFileInput in unit tests

- **Where:** [`apps/main/src/modules/creator-invitations/pages/BulkInvitePage.vue`](../apps/main/src/modules/creator-invitations/pages/BulkInvitePage.vue) — `defineExpose({ onFileSelected })` at the bottom of the `<script setup>` block. Consumed by [`BulkInvitePage.spec.ts`](../apps/main/src/modules/creator-invitations/pages/BulkInvitePage.spec.ts) via `wrapper.vm.onFileSelected(file)` in its `selectFile` test helper.
- **What we accepted in Sprint 3 Chunk 4 sub-step 11:** Vuetify 3's `<v-file-input>` fires `update:modelValue` from internal native-input change handlers that JSDOM cannot simulate cleanly. Stubbing the component via vue-test-utils' `stubs` option doesn't work either — Vuetify registers the component globally via its plugin install, and the stub map only intercepts locally-resolved components. The pragmatic fix is to expose `onFileSelected` from the page so the unit spec can drive the parse → preview → submit → poll flow directly, sidestepping the file-input plumbing entirely. The page also polyfills `File.prototype.text()` on each test fixture File because JSDOM's File implementation returns an empty string regardless of construction payload. The Playwright E2E spec drives the real `<input type="file">` via `setInputFiles`, so the production code path is still covered end-to-end.
- **Risk:** Two-fold:
  1. **Public API surface drift.** `onFileSelected` is now part of the page's compiled exports. A consumer (template ref) could call it and bypass the file-input UI. Unlikely in practice — the page is a leaf route — but the API surface is wider than the template-level contract demands.
  2. **Refactor friction.** Renaming or restructuring `onFileSelected` requires updating both the page and its unit spec. The Vue/TypeScript compiler does NOT enforce the cross-file contract because the test cast is `as unknown as { onFileSelected?: ... }`. A typo would surface only at test runtime.
- **Mitigation today:** The `defineExpose` block carries an explicit inline comment naming the test-only purpose so a future maintainer doesn't expand it. The Playwright critical-path spec (`bulk-invite-creators.spec.ts`) exercises the v-file-input through Playwright's real DOM interaction, so the production path has end-to-end coverage; the unit spec covers the parse/poll state machine without the file-input plumbing.
- **Triggered by:** Vue/Vuetify version bump that changes the v-file-input internals (forces re-evaluating the stub strategy), or a new Vue Test Utils release that adds proper plugin-component stubbing.
- **Resolution options (any one is sufficient):**
  1. **Refactor BulkInvitePage to extract the parse state machine into a composable** (e.g., `useBulkInviteFlow`) that the unit spec can test directly without mounting the component. The page becomes a thin shell over the composable.
  2. **Drive the file selection through Playwright only** (drop the unit spec's file-selection coverage; rely on the Critical-path E2E plus the `useBulkInviteCsv` composable's existing unit coverage).
  3. **Wait for a Vue Test Utils release that lands plugin-component stubbing.** Replace the `defineExpose` + `selectFile` helper with a proper component stub.
- **Owner:** the next chunk that touches `BulkInvitePage` substantively (e.g., the per-row failure-list polish referenced in the Sprint 3 Chunk 4 review). Otherwise rolled into a Sprint 4+ test-infrastructure-hardening sub-chunk.
- **Status:** open. Surfaced by Sprint 3 Chunk 4 sub-step 11.

---

## `seedAgencyAdmin` test-helper hand-rolls recovery codes instead of calling `RecoveryCodeService`

- **Where:** [`apps/api/app/TestHelpers/Http/Controllers/CreateAgencyWithAdminController.php`](../apps/api/app/TestHelpers/Http/Controllers/CreateAgencyWithAdminController.php) → `generateRecoveryCodes()` private method, which emits 8 `bin2hex(random_bytes(5))` strings into `users.two_factor_recovery_codes` when `enroll_2fa=true`. The production `RecoveryCodeService` is bypassed.
- **What we accepted in Sprint 3 Chunk 4 sub-step 11:** The bulk-invite critical-path E2E spec needed an agency admin with 2FA already enrolled (the bulk-invite route is gated by `requireMfaEnrolled`). Calling `TwoFactorEnrollmentService::start()` + `confirm()` from a test-helper would mean serialising the full enrollment lifecycle (`identity:2fa:enroll:` cache key, code-confirmation flow), which is heavier than this seam needs. We trade shape parity with the production recovery-code format (`XXXXX-XXXXX` hyphenated decimal pairs in `RecoveryCodeService::generate()`) for simplicity — the recovery codes are never CONSUMED by the spec (the spec mints a TOTP code, not a recovery code), so the format mismatch is invisible at runtime. The 2FA TOTP secret IS generated via the production `TwoFactorService::generateSecret()` for shape parity with `mintTotpCodeForEmail`'s decoder path.
- **Risk:** A future spec that needs to consume a recovery code from a seeded admin would receive a hex string the production `RecoveryCodeService::consume()` doesn't recognise (the matcher expects the hyphenated decimal shape). The failure would be loud — a clear "recovery code does not match" assertion — not silent, so the diagnostic blast radius is small. The bigger risk is gradual drift: if `RecoveryCodeService` changes its emission format (e.g., adds a checksum), the test-helper continues emitting the old shape and the divergence becomes harder to spot.
- **Mitigation today:** None applied. The recovery-code column is non-null with the right cardinality (8), which is the only invariant `User::hasTwoFactorEnabled()` depends on.
- **Triggered by:** The next spec that needs a recovery code consumable by the production service from a seeded admin. Or a `RecoveryCodeService` refactor that adds a generation invariant (checksum, shape change, length change).
- **Resolution:** Replace `generateRecoveryCodes()` with a call to `app(RecoveryCodeService::class)->generate()` (or whatever the production seam exposes for fresh-codes generation). The implementation already lives in `apps/api/app/Modules/Identity/Services/RecoveryCodeService.php`; the helper just needs to wire it through.
- **Owner:** the next chunk that needs recovery-code consumption from a seeded admin, OR a dedicated test-helper-hardening commit.
- **Status:** open. Surfaced by Sprint 3 Chunk 4 sub-step 11.

---

## Country-code list curations not enforced by architecture test

- **Where:** [`apps/admin/src/modules/creators/config/field-edit.ts`](../apps/admin/src/modules/creators/config/field-edit.ts) (`COUNTRY_OPTIONS` + `LANGUAGE_OPTIONS`) and [`apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`](../apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue) (the wizard's Step 2 country / language dropdowns). The admin per-field-edit dropdown and the wizard's dropdown are two independent TS curations; they share docstrings claiming structural alignment but no test enforces it.
- **What we accepted in Sprint 3 Chunk 4 sub-step 9:** The `field-edit-config-parity.spec.ts` architecture test pins `EDITABLE_FIELDS`, `REASON_REQUIRED_FIELDS`, and `CATEGORY_ENUM` between the frontend and backend. Country and language codes were intentionally left out of the parity scope because there is no backend source of truth to mirror — [`AdminUpdateCreatorRequest::rules()`](../apps/api/app/Modules/Creators/Http/Requests/AdminUpdateCreatorRequest.php) validates `country_code`, `primary_language`, and `secondary_languages.*` as `size:2` strings with no `Rule::in(...)` enum; same in [`UpdateProfileRequest`](../apps/api/app/Modules/Creators/Http/Requests/UpdateProfileRequest.php) for the wizard. The admin SPA's `COUNTRY_OPTIONS` is a curated 9-code list (IE / GB / PT / IT / ES / FR / DE / US / CA) with `allowCustomCode: true` on the `select` control as an escape valve. The pre-merge spot-check audit explicitly verified the parity test's scope and named this carve-out so the chunk-4 review prose doesn't overclaim coverage.
- **Risk:** If the two TS list curations drift, an agency admin could create or shape a creator with a country / language the wizard's dropdown doesn't surface (or vice versa). The backend accepts either path, so the divergence manifests only as a UX inconsistency — admin sees `LV` available but the wizard does not, or a wizard-curated `NO` is missing from the admin dropdown so an admin can't edit the country after creator submission without using the custom-code escape valve. Materially low-impact while both surfaces stay small; growth risk increases as either list expands and the manual sync drifts.
- **Mitigation today:** Inline docstrings on both `COUNTRY_OPTIONS` declarations claim structural alignment with the wizard's Step 2 list. The admin `select` control's `allowCustomCode: true` flag lets an admin type in a 2-letter code outside the curated list so backend round-trips still work even when the dropdowns disagree.
- **Triggered by:** Sprint 4+ work that adds a backend country enum (e.g., for shipping / tax-residence logic that requires a closed set of codes), OR a UX issue where the two surfaces visibly disagree, OR an i18n / localisation pass that wants a single canonical list of supported markets.
- **Resolution:** Three options, any one is sufficient:
  1. **Introduce a backend `COUNTRY_CODES` constant** on a shared service / value-object module and pin all three layers via an extension to `field-edit-config-parity.spec.ts`. This is the symmetric option that matches how `CATEGORY_ENUM` is handled today.
  2. **Add a frontend-only architecture test** that source-inspects both TS curations (admin `field-edit.ts` and wizard `Step2ProfileBasicsPage.vue`) and asserts the two `value` arrays are bit-identical. Cheaper than (1) but ignores the backend layer.
  3. **Document the divergence as intentional** (admin and wizard curate independently because admin has the `allowCustomCode` escape valve and wizard does not). Lift the structural-alignment docstring claim and accept the two-list-of-curations posture.
- **Owner:** The sprint that introduces a backend country enum need (likely Sprint 4+ tax / payout work), OR a dedicated test-infrastructure-hardening commit during the next chunk that touches either dropdown.
- **Status:** open. Surfaced by the Sprint 3 Chunk 4 pre-merge spot-check audit (S2d) — the chunk-4 review's first draft implied country-code parity was enforced; the correction is now applied and this entry documents the gap.

---

## `composer stan` (PHPStan / Larastan level 8) is not in the local pre-commit verification loop

- **Where:** Local pre-commit verification for `apps/api` runs `composer pint:test` and `composer test` (via the Husky / lint-staged hook and the manual chunk-close verification loop), but does NOT run `composer stan`. CI does (see [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) `backend` job, the `Larastan (typecheck, level 8)` step).
- **What we accepted in Sprint 3 Chunk 4 close:** The chunk-4 work commit ([`eeb7d2b`](https://github.com/pedram-kh/Engine/commit/eeb7d2b)) introduced 48 PHPStan level-8 errors across 6 files (3 production, 3 test) — none caught locally because Pest, Vitest, and `vue-tsc` all ran green and `composer stan` was not part of the verification checklist. The errors surfaced only after `git push origin main` triggered CI run 25931807066, which failed the `Larastan (typecheck, level 8)` step. A follow-up fix commit ([`a924e55`](https://github.com/pedram-kh/Engine/commit/a924e55) — `fix(api): resolve 48 Larastan level-8 errors from chunk-4 work commit`) resolved all 48 errors and restored green CI.
- **Risk:** Two-fold:
  1. **CI-loop latency.** A push that compiles + tests locally still fails CI on type strictness, costing a ~5-minute round-trip on the CI runner before the developer sees the failure. Multiply by N pushes-before-clean and the cost adds up.
  2. **Hidden type bugs reach `main`.** Until the fix commit lands, `main` is in a state where `composer stan` fails — any other developer pulling and pushing during that window gets a CI failure rooted in code they didn't write. Sprint 3 Chunk 4's window was ~50 minutes between the failing push (`09dc221`) and the fix push (`a924e55`); a multi-developer team would amplify the cost.
- **Mitigation today:** None applied at the tooling layer. The chunk-4 review's verification table is now expected to include a `composer stan` row going forward (Sprint 3 Chunk 4's table did NOT — that is recognised as a self-review oversight and documented in the chunk-4 review's "Post-merge CI finding" section). Developers manually running `composer stan` before push is the soft-enforcement until the hook is wired.
- **Triggered by:** Any sprint that wants to drop the CI-loop latency on type-strictness errors, OR any close future where a multi-developer team hits the "main is red" amplification cost, OR a `lint-staged` config refresh that adds the missing step.
- **Resolution:** Three options, any one is sufficient:
  1. **Add `composer stan` to the `apps/api` lint-staged config** alongside `vendor/bin/pint`. The hook would run PHPStan against staged PHP files on every commit. Performance cost: PHPStan's incremental cache ([`.phpstan.cache`](../apps/api/.phpstan.cache) if enabled) keeps the per-file run sub-second after the first run; cold start is ~10-30s on the full suite. Scoping to staged files only would keep the hook fast.
  2. **Add a `pre-push` git hook that runs the full `composer stan` + `composer test`** before the push completes. Heavier (~60s) but catches everything CI would catch with one extra wait at push time. Reuses the same scripts CI runs, so divergence between local and CI is impossible by construction.
  3. **Add a `composer verify` Composer script** that runs `pint:test` + `stan` + `test` in sequence, and include it in the standing chunk-close verification checklist in `PROJECT-WORKFLOW.md § 3`. Lowest tooling investment, highest discipline reliance.
- **Owner:** Sprint 4 kickoff sub-step-0 (tooling hardening) OR the next chunk that surfaces a similar CI-loop-latency cost.
- **Status:** open. Surfaced by the Sprint 3 Chunk 4 post-merge CI failure (run 25931807066) — fix landed in [`a924e55`](https://github.com/pedram-kh/Engine/commit/a924e55); this entry documents the tooling gap that allowed the regression to reach `main`.

---

## Warm-gray `neutral` primitives deprecated in favour of `zinc` (Engine C v2)

- **Where:** [`packages/design-tokens/src/tokens.ts`](../packages/design-tokens/src/tokens.ts) (`neutral` scale) + its CSS mirror in [`packages/design-tokens/tokens.css`](../packages/design-tokens/tokens.css) (`--neutral-*`).
- **What we accepted in Sprint 3.5 Chunk 1 (May 31, 2026):** the Engine C v2 brand layer migrated the canonical surface/border/text neutral palette from the warm-gray `neutral` scale to the true-neutral `zinc` scale (Decisions D4 dark + D5 light). [`semantic.ts`](../packages/design-tokens/src/semantic.ts) now references `zinc.*` exclusively for `bg` / `border` / `text` roles. The old `neutral` scale is left exported-but-deprecated: `brand.cream` / `brand.ink` still live in its tonal world (logo + brand surfaces, Sprint 3.5 Chunk 5), and the Vuetify semantic-chip foregrounds (`on-info` / `on-success` / `on-warning`) still reference `neutral[0]` / `neutral[900]` (white / near-black) under the D1/D2 "preserve single-value semantics" reinterpretation.
- **Risk:** two neutral scales coexist in the token package. A future contributor could reach for `neutral.*` (warm) when they meant `zinc.*` (true-neutral), producing a subtly-off surface. Low blast radius today — `semantic.ts` is the only theme-facing consumer and it is fully on zinc.
- **Mitigation today:** the migration is complete at the theme layer; `neutral` survives only for the brand-color + semantic-chip-foreground cases that legitimately want its warmth. Comments in `tokens.ts` + `semantic.ts` mark `neutral` as deprecated.
- **Triggered by:** Sprint 3.5 Chunk 4 (component-override / visual-regression sweep) once warm-gray consumers are audited, OR any chunk that touches the brand-surface tokens.
- **Resolution:** ~~audit every `neutral.*` / `--neutral-*` consumer; migrate brand surfaces + semantic-chip foregrounds onto explicit literals or `zinc.*` as appropriate; delete the `neutral` scale + `--neutral-*` vars.~~ Done across Chunks 4–5: Chunk 4 severed the last runtime consumer (`vuetify.ts` semantic-chip foregrounds → `#FFFFFF` / `zinc[950]` literals, regression-locked by the `vuetify.spec.ts` severance test); Chunk 5 (W1) deleted the `neutral` const + `NeutralTokens` type from `tokens.ts` and the `--neutral-*` block from `tokens.css`. `brand.cream` / `brand.ink` confirmed to be standalone hex literals (they never referenced the `neutral` scale — the prior docblock prose was corrected).
- **Owner:** ~~Sprint 3.5 Chunk 4 OR the brand-surface consolidation chunk.~~
- **Status:** **closed** in Sprint 3.5 Chunk 5 (W1). Severed Chunk 4; primitive + CSS deleted Chunk 5. Surfaced by Sprint 3.5 Chunk 1, May 31, 2026.

---

## Tri-state `'system'` theme preference dropped — stale localStorage values linger

- **Where:** [`apps/main/src/composables/useThemePreference.ts`](../apps/main/src/composables/useThemePreference.ts) + [`apps/admin/src/composables/useThemePreference.ts`](../apps/admin/src/composables/useThemePreference.ts) (`readStoredPreference`), keyed by `catalyst.main.theme` / `catalyst.admin.theme`.
- **What we accepted in Sprint 3.5 Chunk 1 (May 31, 2026):** chunk 8.2 shipped a tri-state preference (`light` / `dark` / `system`) where `system` consulted `prefers-color-scheme`. Sprint 3.5 dropped `'system'` entirely (Q `tri_state_disposition` = "drop_system") — the v2 brand is dark-first and the toggle is binary. A user who previously selected `system` has the literal string `'system'` persisted in `localStorage`. The new composable treats any unrecognised stored value (including `'system'`) as **unset**, falling back to the SPA default (`dark`), via a passive-on-read migration: storage is read but NOT rewritten during initialisation (no write side effect in a getter). The stale row is overwritten the next time the user explicitly toggles, or lingers harmlessly.
- **Risk:** cosmetic + storage hygiene only. The stale `'system'` row occupies one `localStorage` key until the user next toggles; it is never read as a valid preference (the binary guard rejects it). No correctness impact — the fallback-to-default behaviour is the intended one.
- **Mitigation today:** passive-on-read coercion (`raw === 'light' || raw === 'dark'` guard) is the migration; it is unit-tested in both SPAs' `useThemePreference.spec.ts` ("legacy 'system' value (passive-on-read migration)" describe block) — including the assertion that storage is NOT rewritten on read.
- **Triggered by:** a future preference-schema change that wants active migration, OR a storage-hygiene sweep.
- **Resolution:** optionally add a one-shot active migration (on read, if the value is the legacy `'system'`, `removeItem` it) — deliberately NOT done now to avoid a write side effect during composable initialisation (anti-pattern). If ever desired, gate it behind an explicit `migrate()` call from `App.vue` bootstrap. Estimated effort: ~20 minutes including the test flip.
- **Owner:** optional — only if a storage-hygiene or preference-schema chunk lands.
- **Status:** open (low priority). Surfaced by Sprint 3.5 Chunk 1, May 31, 2026.

---

## Dormant `--color-*` semantic CSS variables still on warm-gray (not migrated to zinc)

- **Where:** [`packages/design-tokens/tokens.css`](../packages/design-tokens/tokens.css) — the `:root[data-theme='light']` / `:root[data-theme='dark']` blocks defining `--color-bg-*`, `--color-text-*`, `--color-border-*`, `--color-action-*`.
- **What we accepted in Sprint 3.5 Chunk 1 (May 31, 2026):** the authored `--color-*` semantic variables map to the warm-gray `--neutral-*` primitives (and `--brand-ink` / `--brand-cream`). They were NOT migrated to zinc in Chunk 1. Reason: a chunk-8.2 grep confirmed **zero** consumers of `var(--color-*)` across both SPAs — the variables are dormant. The live theme path is the Vuetify `theme.colors` layer (now on zinc); the `--color-*` CSS layer is a parallel, unused authored surface inherited from the original `docs/01-UI-UX.md` token extraction.
- **Risk:** if a future component starts consuming `var(--color-bg-app)` etc., it would render the OLD warm-gray surface, diverging visibly from the Vuetify-driven zinc surface the rest of the app uses. Zero risk while consumer count stays at 0.
- **Mitigation today:** none needed — dormant. The `data-theme='dark'` block is also referenced by the new `<html data-theme="dark">` attribute (decorative; the SPAs aren't PWA-configured and nothing reads `--color-*`).
- **Triggered by:** the broader `tokens.css` `--color-*` removal-or-migrate decision (already tracked from chunk 8.2), OR the first component that consumes a `--color-*` variable.
- **Resolution:** ~~either (a) delete the dormant `--color-*` blocks entirely (they duplicate the Vuetify theme layer), or (b) migrate their neutral references to `--zinc-*`.~~ Resolved via option (a): Chunk 5 (W1) deleted both `:root[data-theme='light']` / `:root[data-theme='dark']` `--color-*` blocks (and the chunk-8.2 `@media`-removal note that only documented them) from `tokens.css`. The deletion audit re-confirmed zero `var(--color-*)` consumers across both SPAs' source. The Vuetify `theme.colors` layer (zinc) is the sole color path; `tokens.css` now carries only `--brand-*` / `--radius-*` / `--space-*` / `--font-*` / `--catalyst-typography-*`.
- **Owner:** ~~Sprint 3.5 Chunk 4 / brand-surface consolidation chunk.~~
- **Status:** **closed** in Sprint 3.5 Chunk 5 (W1). Surfaced (re-confirmed) by Sprint 3.5 Chunk 1, May 31, 2026.

---

## `docs/01-UI-UX.md` is stale vs the Engine C v2 brand layer

- **Where:** [`docs/01-UI-UX.md`](01-UI-UX.md) — the design-system source-of-truth doc.
- **What we accepted in Sprint 3.5 Chunk 1 (May 31, 2026):** Chunk 1 landed the v2 brand layer (aurora accent, zinc neutrals, Inter self-hosting, binary dark-first theme toggle) in code, but `docs/01-UI-UX.md` still documents the v1 system (warm-gray neutrals, teal→violet-only brand, tri-state theme intent). Per the Sprint 3.5 kickoff, documentation updates are explicitly **deferred to Chunk 5**. Chunk 1 is a code-first landing; the doc refresh is its own chunk so the prose can describe the _settled_ v2 system rather than chasing in-flight chunks.
- **Risk:** a reader treating `01-UI-UX.md` as current would mis-describe the neutral scale, the brand accent, and the theme model. Bounded — the code + the Sprint 3.5 chunk reviews are the accurate record in the interim.
- **Mitigation today:** the Sprint 3.5 Chunk 1 review ([`reviews/sprint-3-5-chunk-1-review.md`](reviews/sprint-3-5-chunk-1-review.md)) documents the as-built v2 decisions (D1–D7, R1, the five reinterpretations); the design-tokens source + the `color-system-parity` architecture test are self-describing.
- **Triggered by:** Sprint 3.5 Chunk 5 (documentation chunk) per the kickoff plan.
- **Resolution:** ~~rewrite `docs/01-UI-UX.md` §2 (colour) + §3 (typography).~~ Done in Chunk 5 (W2): §2 rewritten wholesale (§2.1–2.7 — zinc neutrals, teal co-brand, aurora utility/D7, single-value semantics, container/variant tokens, binary dark-default theme model, and the correct Vuetify-theme-layer consumption path); §3 corrected (self-host path → `packages/ui/assets/fonts/`, static weights); the theme-model framing fixed in §1/§10/§14; the stale `var(--color-*)` consumption guidance reconciled in §2.7 + §12 + `02-CONVENTIONS.md` §3.8. The `color-system-parity` test is cross-linked as the enforcement artifact.
- **Owner:** ~~Sprint 3.5 Chunk 5.~~
- **Status:** **closed** in Sprint 3.5 Chunk 5 (W2). Surfaced by Sprint 3.5 Chunk 1, May 31, 2026.

---

## Shared status-badge / display chips not consolidated into a single `CStatusBadge`

- **Where:** [`packages/ui/src/components/`](../packages/ui/src/components/) — the five near-identical v-chip shells: `ContractStatusBadge.vue`, `KycStatusBadge.vue`, `PayoutMethodStatus.vue`, `TaxProfileDisplay.vue`, and the chip portion of related displays.
- **What we accepted in Sprint 3.5 Chunk 2 (May 31, 2026):** the five status chips were left purpose-specific (Decision § 1.7). A generic `CStatusBadge` is technically possible but was deliberately not built this chunk. Each chip encodes its own domain semantics (KYC status enum → label + colour, payout boolean → label + colour, contract status enum → label + colour, etc.). A generic badge would push that enum→label→colour mapping into every call site, making the call sites noisier without a real maintainability gain at the current count of five.
- **Risk:** low. Five small, stable components with overlapping structure (v-chip + size + variant). The only cost is mild duplication of the chip shell; there is no behavioural or theming risk (all consume Vuetify `color` props, so they re-theme automatically with the zinc swap).
- **Mitigation today:** none needed — the duplication is shallow and the components are individually tiny.
- **Triggered by:** the call-site count growing (a sixth/seventh status chip appearing), OR a shared-component-library consolidation pass.
- **Resolution:** introduce `CStatusBadge` taking `{ label, color, size?, variant? }` and migrate the five chips' templates to it, keeping the domain enum→label→colour mapping in each consuming module (or a shared map). Estimated effort: ~2-3 hours including the co-located specs.
- **Owner:** open.
- **Status:** open (deferred by design). Surfaced by Sprint 3.5 Chunk 2 read pass, May 31, 2026.

---

## packages/ui has no test harness

- **Where:** [`packages/ui`](../packages/ui) (no Vitest/jsdom/@vue/test-utils config; `package.json` test script is a placeholder `echo 'no tests yet'`).
- **What we accepted in Sprint 3.5 Chunk 2:** `CEmptyState` + `CButton` specs were co-located in [`apps/main/tests/unit/`](../apps/main/tests/unit/) rather than standing up a package-level test harness this chunk. The "tests live in the consuming SPA" convention is documented in each spec's header comment, and the cross-package architecture tests (`typography-consumption.spec.ts`) inspect `packages/ui/src` via `fs` from the SPA suites.
- **Risk:** shared components without package-level coverage rely on the consuming SPAs' tests for verification. Coverage gaps emerge if multiple SPAs consume a component differently, OR if a shared component's behaviour isn't exercised by any consuming SPA test.
- **Mitigation today:** `CButton` + `CEmptyState` are covered by co-located specs in `apps/main/tests/unit/`. The convention is recorded in the spec header comments.
- **Resolution:** set up Vitest + jsdom + `@vue/test-utils` in `packages/ui` per the shared-package extraction pattern from `02-CONVENTIONS.md §1`. Migrate the co-located SPA specs back into the package. Estimated effort: ~4-6 hours of infrastructure work.
- **Triggered by:** the next chunk that adds 2+ shared components, OR an explicit decision to invest in package-level testing.
- **Owner:** Sprint 4 Chunk 1 (sub-step 1a).
- **Status:** **CLOSED** — Sprint 4 Chunk 1 sub-step 1a (2026-05-31). `packages/ui` now has its own Vitest harness: `vitest.config.ts` (jsdom env, `@vitejs/plugin-vue`, `vuetify` inlined), `tests/setup.ts` (the Vuetify jsdom polyfills mirrored from `apps/main`), and a theme-aware `tests/helpers/mountThemed.ts`. The `test` script is a real `vitest run` (was `echo 'no tests yet'`), so the package now runs under the existing root `test:frontend` job via its `--filter './packages/*'` glob — **no new CI job**. The two co-located specs (`CButton`, `CEmptyState`) were migrated to `packages/ui/tests/components/` and rewired to the helper; their "tests live in the consuming SPA" docblocks + this entry's citation were removed. Verified: 22/22 package specs green; `apps/main` 599/599 green (incl. `typography-consumption`, which still scans `packages/ui/src` for `.vue` and is unaffected by the spec relocation).

---

## Component-test harness renders under Vuetify's stock theme, not the Catalyst zinc themes

- **Where:** [`apps/main/tests/unit/helpers/mountAuthPage.ts`](../apps/main/tests/unit/helpers/mountAuthPage.ts) (and the admin SPA's equivalent harness). The harness calls `createVuetify({ components, directives })` with **no `theme` option**, so every mounted component renders under Vuetify's built-in default `light` theme — never the Catalyst `lightTheme` / `darkTheme` exports (zinc neutrals, registered container/variant tokens, etc.).
- **What we accepted in Sprint 3.5 Chunk 3 (May 31, 2026):** the visual-regression sweep across both SPAs in light AND dark mode was done **eyes-on** because there is no automated coverage of dark-mode rendering. Component unit tests assert structure/behaviour/string output, all under the stock light theme. The Chunk 1 `color-system-parity` architecture tests pin the theme token VALUES (so a wrong hex is caught), but nothing renders a component against the dark theme and asserts the result — so a regression that only manifests when the dark theme is applied (e.g. a hardcoded color that happens to look fine on stock-light but wrong on zinc-dark, or a missing `on-*` foreground) would pass CI and only surface in a manual sweep.
- **Risk:** medium. Dark mode is the product default (binary dark-first since Chunk 1), so the un-covered theme is the one most users see. The mitigations below keep the risk bounded, but each visual chunk currently costs a manual eyes-on pass.
- **Mitigation today:** (1) `color-system-parity` pins every theme slot's per-mode value (incl. the Chunk 3 container/variant tokens), so the token layer itself is regression-locked; (2) `no-hard-coded-colors` + `no-inline-color-styles` architecture tests forbid the most common drift source; (3) the per-chunk eyes-on sweep (Chunk 3) is the human backstop.
- **Resolution:** register the Catalyst themes in the harness (`createVuetify({ ..., theme: { defaultTheme, themes: { light: lightTheme, dark: darkTheme } } })`) and add an option to mount a component under a chosen theme, enabling targeted dark-mode rendering assertions (e.g. snapshot or computed-style probes on the highest-CSS surfaces — onboarding cluster, PortfolioGallery, dialogs). Estimated effort: ~3-4 hours including a first batch of dark-mode rendering specs.
- **Triggered by:** the next visual/theming chunk, OR a dark-mode rendering regression slipping past CI into a manual sweep.
- **Owner:** Sprint 4 Chunk 1.
- **Accurate-residual correction (Sprint 4 Chunk 1):** the original framing ("nothing renders a component against the dark theme") was already partly stale at write time — `CButton.spec.ts` mounted `createVuetify({ theme: { defaultTheme: 'dark', themes: { light, dark } } })` inline. The true gap was that the **shared `mountAuthPage` helper** (and the admin equivalent) builds Vuetify with no `theme` option, so every page-level component mounted via the helper rendered stock-light.
- **Status:** **CLOSED (narrowed) — Sprint 4 Chunk 1 (2026-05-31).** Closed for the two NEW theme-aware harnesses this chunk introduces: (1) `packages/ui/tests/helpers/mountThemed.ts` (1a) — mounts shared components under the real `light`/`dark` Catalyst themes, dark-default, mode-parameterized, with the first systematic dark-vs-light rendering assertion (`CButton` carries `v-theme--dark` / `v-theme--light`); (2) the theme-aware dashboard mount helper added in 1b. **Deliberately NOT closed for `apps/main/tests/unit/helpers/mountAuthPage.ts` or the admin shared helpers** — re-theming the established auth/creator/onboarding specs is out of this chunk's scope and carries a real destabilization risk (D-c1-11). Those helpers intentionally remain stock-theme; the `color-system-parity` value-locks + `no-hard-coded-colors` / `no-inline-color-styles` guards + per-chunk eyes-on sweep remain their mitigation. A future chunk may retrofit `mountAuthPage` deliberately. Surfaced by Sprint 3.5 Chunk 3 visual-regression sweep, 2026-05-31.

---

## Main-SPA app routes live in the auth module's `routes.ts`

- **Where:** [`apps/main/src/modules/auth/routes.ts`](../apps/main/src/modules/auth/routes.ts) exports `appRoutes` (the `/` dashboard, `/brands`, `/agency-users`, `/settings`, …) alongside `authRoutes`. The other feature modules (`creators`, `onboarding`) own their own `routes.ts` imported into the central aggregator, but the cross-cutting "app" routes are housed in the auth module rather than per-feature or in a dedicated `core` route file.
- **What we accepted in Sprint 4 Chunk 1:** when repointing `/` from `DashboardPlaceholderPage` to the new dashboard module's page, we changed only the route record's lazy `component` import in place. Moving `app.dashboard` into a new `modules/dashboard/routes.ts` was deliberately NOT done — it would churn the central aggregator and the source-scanned `agency-routes-mfa-guard.spec.ts` for no functional gain this chunk.
- **Risk:** low. Cosmetic/organizational; the route table is correct and tested. The smell is that "where does route X live" is non-obvious (app routes hide in the auth module).
- **Resolution:** extract `appRoutes` to a `modules/dashboard/routes.ts` (or `core/router/appRoutes.ts`) and have each feature module own its records, imported into the aggregator — matching the `creators`/`onboarding` pattern. Update `agency-routes-mfa-guard.spec.ts`'s `ROUTES_PATH` accordingly.
- **Triggered by:** the next chunk that meaningfully restructures the main-SPA route table, OR the dashboard module growing its own multi-route surface.
- **Owner:** open (low priority).
- **Status:** open (deferred by design). Surfaced by Sprint 4 Chunk 1 read pass, 2026-05-31.

---

## Dashboard KPI counts exclude `is_blacklisted` via the boolean only (scope-aware counting deferred to Sprint 7)

- **Where:** the agency dashboard summary endpoint (`GET /api/v1/agencies/{agency}/dashboard/summary`, Agencies module) — the `creators_in_roster` and `pending_creator_applications` counts.
- **What we accepted in Sprint 4 Chunk 1:** both KPI counts apply a plain `agency_creator_relations.is_blacklisted = false` clause. The relation row already carries richer blacklist columns (`blacklist_scope`, `blacklist_type`, `blacklisted_at`, …) whose real semantics (agency vs platform vs campaign scope) are NOT yet designed — full blacklisting is Sprint 7. The boolean is the conservative, obviously-correct interim interpretation of "active roster / this agency's pending applications": a creator the agency has blacklisted should not inflate either workspace-home KPI. This is a deliberate deviation from the chunk-1 kickoff's "no blacklist filter" prose (D-c1-7), taken because the field already ships and Sprint 7 could be months out — surfaced at plan-pause and approved.
- **Risk:** low. If Sprint 7 introduces scope-aware blacklisting where a _campaign-scoped_ or _platform-scoped_ block should NOT exclude a creator from an agency's roster KPI, the plain-boolean filter would be too aggressive (or not aggressive enough) and the counts would need revisiting.
- **Resolution:** when Sprint 7 designs blacklisting scope semantics, revisit the dashboard KPI denominators to honor scope (e.g. only agency-scoped blacklists exclude from the agency roster count) rather than the flat boolean.
- **Triggered by:** Sprint 7 blacklisting.
- **Owner:** Sprint 7 (blacklisting).
- **Status:** ✅ **RESOLVED — Sprint 7 (2026-06-04, [review](reviews/sprint-7-review.md)).** Both KPI counts (`creators_in_roster`, `pending_creator_applications` in `DashboardSummaryController`) now apply the scope-aware predicate `NOT (is_blacklisted = true AND blacklist_scope = 'agency')` instead of the flat `is_blacklisted = false`. Only an **agency-wide** blacklist drops a creator from the count; a **brand-scoped** blacklist (which lives in `brand_creator_blacklists`, never on the relation — D-2) does NOT, since it is not an agency-wide exclusion. Pinned by `BlacklistEnforcementTest` (B3): an agency-wide blacklist reduces the count, a brand-scoped one leaves it unchanged (break-revert: the old boolean would wrongly drop brand-scoped too). Originally surfaced + accepted by Sprint 4 Chunk 1, 2026-05-31.

---

## Dashboard activity feed enrichment is deferred (subject-relevance via stamping + allowlist only) — D-c1-8

- **Where:** the agency dashboard activity feed (`GET /api/v1/agencies/{agency}/dashboard/activity`, Agencies module) + `App\Modules\Agencies\Support\DashboardActivityFeed`.
- **What we accepted in Sprint 4 Chunk 1 (1c):** the feed's subject-relevance scoping v1 is "agency-stamped `audit_logs` rows (`agency_id = {agency}`) whose action is in a curated `ACTION_ALLOWLIST`", newest-first, capped at 15. This establishes the _mechanism_ (stamped rows + curated allowlist + per-action metadata whitelist) from day one, but it is deliberately NOT enriched:
  - **Tenant-less events are excluded by construction.** Creator wizard events (`creator.wizard.*`, `creator.submitted`, …) stamp `agency_id = null`, so they never reach an agency's feed even when that agency rosters the creator. Surfacing "your rostered creator completed KYC" would require either back-filling `agency_id` on those emissions or a creator→agency join at read time — out of scope for this chunk.
  - **Row copy is template-only.** Rows render a localized per-action template + the whitelisted metadata; there is no deep-link to the subject, no subject display-name resolution beyond the actor label, and no grouping/"X and 3 others" collapsing.
  - **The allowlist favours signal over churn** (lifecycle events, not field-level updates) — see `DashboardActivityFeed`'s curation rationale. Re-including churn actions (e.g. `agency_creator_relation.updated`) would need a noise-control strategy (debounce / group) first.
- **Risk:** low. The feed is correct + PII-safe for what it shows; the debt is _coverage/richness_, not correctness. An agency won't see creator-side lifecycle moments in v1.
- **Resolution:** a later chunk can (a) decide which tenant-less creator events should appear in a rostering agency's feed and back-fill `agency_id` (or add a read-time join), (b) add subject deep-links + name resolution, and (c) expand the allowlist with a churn-control strategy. Each allowlist/whitelist change is already guarded by `DashboardActivityAllowlistTest`.
- **Triggered by:** the chunk that invests in workspace-home activity richness, OR a product decision that agencies must see creator-side lifecycle events.
- **Owner:** open.
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 4 Chunk 1, 2026-05-31.

---

## Sprint-10 payments: escrow + money-movement webhooks + the `Modules\Payments` home (D-c2-1/2 deferral)

- **Where:** the payments integration surface. Today: `App\Modules\Creators\Integrations\Contracts\PaymentProvider` (the 4-method onboarding + inbound-webhook contract — `createConnectedAccount`, `getAccountStatus`, `verifyWebhookSignature`, `parseWebhookEvent`), its DTOs (`PaymentAccountResult`, `AccountStatus`, `PaymentsWebhookEvent`), `MockPaymentProvider` + the real [`StripePaymentProvider`](../apps/api/app/Modules/Creators/Integrations/Stripe/StripePaymentProvider.php), the `/api/v1/webhooks/stripe` ingestor → [`ProcessStripeWebhookJob`](../apps/api/app/Modules/Creators/Jobs/ProcessStripeWebhookJob.php) handling `account.updated` only, and the `creator_payout_methods` columns. All of it lives **under the `Creators` module**.
- **What we accepted in Sprint 4 Chunk 2:** the chunk implemented the real Stripe Connect **onboarding** adapter against the _built_ (narrow, Sprint-3) `Creators` contract rather than standing up the spec's broad `Modules\Payments\Contracts\PaymentProviderContract` (`06-INTEGRATIONS §2`). Concretely deferred:
  - **Escrow / money-movement methods** — `fundEscrow`, `releaseEscrow`, `refundEscrow` and friends are NOT on the contract. The Chunk-2 contract is onboarding + `account.updated` only.
  - **The 8 money-movement webhooks** — `charge.*`, `transfer.*`, `payout.*`, dispute/refund events. Only `account.updated` is wired; the ingestor + `integration_events` `(provider, provider_event_id)` dedup generalize to the rest, but no handler/route exists for them yet.
  - **The `payments` / `payment_events` model** — no money-movement ledger. `integration_events` is webhook-dedup/audit only, deliberately distinct from the Sprint-10 `payment_events` ledger (D-c2-4).
  - **`campaign_assignments` FK resolution** — the assignment→payout linkage (`03-DATA-MODEL §9`) is unbuilt.
  - **The module home.** The real adapter sits at `app/Modules/Creators/Integrations/Stripe/StripePaymentProvider.php`, a **deliberate divergence** from `06-INTEGRATIONS.md:122` (`Modules/Payments/Integrations/Stripe/`). The spec path presumes the spec's contract home, which D-c2-1 defers; co-locating with the mock it implements is the honest interim. When Sprint 10 builds `Modules\Payments`, the adapter + contract + DTOs migrate with it.
- **Risk:** low and bounded. The onboarding flow is complete and correct for what it does; the debt is purely _scope_ (money movement is a whole Sprint-10 workstream) and _placement_ (the adapter lives in `Creators`, not `Payments`). The webhook ingestor + dedup index already generalize, so Sprint 10 extends rather than rebuilds.
- **Resolution:** at Sprint 10, build `Modules\Payments` per `03-DATA-MODEL §9` + `06-INTEGRATIONS §2`: stand up the broad `PaymentProviderContract` (escrow + money movement), the `payments`/`payment_events` model, `campaign_assignments` FK resolution, the 8 money-movement webhook handlers, and **migrate the onboarding adapter + the `PaymentProvider` contract + DTOs out of `Creators` into `Modules\Payments`** (updating the resolver binding, the `Step7PayoutPage` import, and the webhook route home). Flip `payment_processing_enabled` per the feature-flag schedule.
- **Triggered by:** Sprint 10 (payments / money movement).
- **Owner:** Sprint 10.
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 4 Chunk 2, 2026-06-02.

---

## Real in-app notification subsystem (D-c3-1 deferral)

- **Where:** there is no notifications surface anywhere today — no `notifications` table, no channel, no unread badge/inbox, no per-event push. The creator-facing signals that exist are the four dashboard status banners on [`apps/main/src/modules/creators/pages/CreatorDashboardPage.vue`](../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue) (pending / approved / rejected / incomplete) plus the two lifecycle emails ([`CreatorApprovedMail`](../apps/api/app/Modules/Creators/Mail/CreatorApprovedMail.php) + [`CreatorRejectedMail`](../apps/api/app/Modules/Creators/Mail/CreatorRejectedMail.php)).
- **What we accepted in Sprint 4 Chunk 3 (D-c3-1):** `20-PHASE-1-SPEC.md:186` reads the approval/rejection outcome as "in-app + email." We satisfied the "in-app" half with the **existing** rejected dashboard banner — Chunk 3 (Cluster 5) just wired `rejection_reason` through to the creator-facing `/creators/me` resource so the banner can render the reason, and (Cluster 6) added the "Update & resubmit" affordance on that same banner. This is a **deliberate divergence** from a literal reading of "in-app notification": there is no notification record, no read/unread state, and no notification for any event other than the application outcome the creator sees when they next open the dashboard. The email half is the active push (deferred to the log mailer — see the email-posture note in [`services.md`](services.md)).
- **Risk:** low for Phase 1. The creator learns their outcome via email (push) and sees it reflected on the dashboard (pull). The gap is that there is no general-purpose notification primitive: future events (a brand message, a campaign invite, an agency action) have nowhere to land in-app, and the creator has no notification history. Building one ad-hoc per event would fragment the surface.
- **Mitigation today:** none beyond the banner + email. The audit log records the lifecycle transitions for compliance, but it is admin-only and is not a user-facing notification feed.
- **Resolution:** when a real notification subsystem is needed, design it as a first-class primitive — a `notifications` table (polymorphic subject + actor + read_at + type), a backend dispatch seam that the approve/reject/verify actions (and future emitters) write to, a SPA inbox/badge surface, and the i18n + architecture-test parity the other surfaces carry. Migrate the dashboard banner to read from it rather than from `application_status` directly.
- **Triggered by:** the first product requirement for an in-app notification beyond the application-outcome banner (e.g. brand messaging, campaign invites), OR a deliberate notifications-subsystem chunk.
- **Owner:** open (post-Phase-1 / notifications chunk).
- **Status:** in-progress (S11.0 — notification subsystem mini-sprint). Chunk 1 (June 6, 2026) built the first-class primitive this resolution called for: the `notifications` table (polymorphic subject + nullable actor + `read_at` + indexable `type`), the `NotificationService` dispatch seam, the per-user `/me/notifications` feed + unread-count + mark-read endpoints, and per-user preferences — see [`03-DATA-MODEL.md`](03-DATA-MODEL.md) §14. **Chunk 2 (June 6, 2026)** wired the approve/reject sites to **emit `creator.approved` / `creator.rejected` notification rows** ([`AdminCreatorController`](../apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php)) alongside the lifecycle emails — so the durable in-app record now exists. **The `:945` dashboard banner itself is NOT yet repointed:** [`CreatorDashboardPage.vue`](../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue) still reads `application_status` directly (the "fake" persists), because repointing it to read the notification is a **frontend** change deliberately held for Chunk 3 (the SPA notification centers + badge) — Chunk 2 is backend-only. The remaining bucket-c emitters + the banner repoint are tracked in the entry below; full closure is Chunk 3. **Chunk 3a (June 6, 2026)** shipped the first user-facing surface — the shared `NotificationCenter` (dropdown + paginated page) in both shells, the unread badge + steady poll, and the client-side localized renderer — so the creator now has an in-app notification feed + history + read/unread state for the live event types. **Chunk 3b (June 6, 2026)** added the per-user **notification-preferences** read + write — the product's first user self-write surface (`GET`/`PATCH /me/notification-preferences`, sparse store: divergence → row, return-to-default → delete, preserve-current contract intact) — plus a minimal in-app prefs page mounted off the user menu in both shells (in-app channel only; the 8 live-emit types, grouped). **The user-facing-surface portion of the subsystem is now complete.** **Still open** (no longer user-facing-surface): the bucket-c verb-mints (the entry below) and the `:945` banner repoint (deferred — see the deep-link entry). **The `digest` channel exposure is now PARTIALLY CLOSED (Sprint 11, June 6, 2026):** Messaging is the digest channel's first consumer — the daily `messages:send-digest` job consumes it and the prefs page was lifted from a single hardcoded `in_app` channel to a per-type "supported channels" notion (D-10), so the two messaging types now surface BOTH `in_app` + `digest` toggles, co-delivered with the consumer (no dead control). The lift is general: any future type that declares a `digest` consumer gets its toggle the same way. **Still deferred:** the `email` channel exposure — email still rides independently of prefs (no immediate-email centralization seam); messaging deliberately has NO per-message email (D-8 divergence — the digest IS its email path). Surfaced + accepted by Sprint 4 Chunk 3, 2026-06-02.

---

## Notification deep-link-to-subject is deferred (Ch3a row click marks read in place, no navigation)

- **Where:** the notification center — [`NotificationCenter.vue`](../apps/main/src/modules/notifications/components/NotificationCenter.vue). The `NotificationResource` already carries a structured `subject` (`{type, ulid}`, plumbed through Ch1's resource), but the FE does not navigate on it.
- **What we accepted in S11.0 Chunk 3a (June 6, 2026):** clicking a notification row marks it read **in place** (idempotent `PATCH …/{ulid}/read`) and does NOT navigate to the subject. There is no uniform `subject_type → SPA route` map: creator-facing rows point at a `CampaignAssignment` (which has a creator route, `/creator/assignments/:ulid`) but the agency fan-out rows carry a `campaign_ulid` and the agency shell has **no per-assignment detail route** to land on — so a naive deep-link would dead-end half the feed. Shipping read-in-place keeps the surface honest rather than wiring a partial, asymmetric navigation.
- **Risk:** low. The body templates already name the campaign/creator, so the row is self-describing; the user just can't click through to the subject yet.
- **Resolution:** add a uniform `subject_type → route` resolver (per-shell, since the same subject maps to different routes in the agency vs creator shell) once an agency assignment-detail route exists, then make the row (or an explicit control) navigate on click while still marking read.
- **Triggered by:** the first agency-side assignment-detail route, OR a dedicated per-type route-map pass. Naturally adjacent to the `:945` banner repoint (both need the feed-read FE that Ch3a now provides).
- **Owner:** the notification-subsystem workstream (Chunk 3b / a later FE pass).
- **Status:** open (deferred by design). Surfaced + accepted by S11.0 Chunk 3a, 2026-06-06.

---

## Message deletion + moderation deferred — `messages.deleted_at` ships column-only (Sprint 11, D-14)

- **Where:** the Messaging module — [`Message`](../apps/api/app/Modules/Messaging/Models/Message.php) uses `SoftDeletes` and the `messages` table carries a `deleted_at` column (`03-DATA-MODEL.md` §11), but **no endpoint writes it**. There is no delete/redact route on either the agency or creator surface, and no moderation/abuse-report path.
- **What we accepted in Sprint 11 (June 6, 2026):** the soft-delete column ships **present-but-unwritten** (the Sprint-9 review-trail-columns pattern) so the SoftDeletes scope (which already filters deleted rows out of the feed, unread counts, and the digest) is wired and the future delete endpoint needs no migration. A sender cannot retract a message, an agency cannot redact one, and there is no report/flag affordance this sprint.
- **Risk:** low at Phase-1 volumes. Threads are append-only between two known, contracted parties (creator ↔ their agency) — not a public/open surface — so the abuse/moderation surface is small. The gap is purely the absence of correction/retraction, not data loss.
- **Triggered by:** the first product requirement for message retraction, redaction, or moderation (e.g. a creator sends something to the wrong thread, or an agency needs to remove abusive content), OR a trust-and-safety pass.
- **Resolution:** add a delete endpoint (soft-delete, audited) on the owning surface + a moderation/report path; the column + SoftDeletes scope are already in place, so this is purely additive (route + policy + audit verb).
- **Owner:** open (post-Phase-1 / trust-and-safety).
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 11, 2026-06-06.

---

## Admin PII drill-in for encrypted KYC / tax-profile decision data (D-c3-2 deferral, vendor-era)

- **Where:** the admin creator drill-in — [`apps/admin/src/modules/creators/pages/CreatorDetailPage.vue`](../apps/admin/src/modules/creators/pages/CreatorDetailPage.vue) + [`CreatorResource::withAdmin()`](../apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php) (the `admin_attributes` block). The encrypted-at-rest fields live on the KYC verification rows + `creator_tax_profiles` (`decision_data`, `failure_reason`, encrypted tax identifiers per `05-SECURITY-COMPLIANCE.md` §4 / `03-DATA-MODEL.md` §23).
- **What we accepted in Sprint 4 Chunk 3 (D-c3-2):** the admin review pane shows the already-visible profile / social / portfolio fields + the KYC verification **history summary** (`{id, provider, status, started_at, completed_at, expires_at}` — PII stripped, established Sprint 3 Chunk 3) + (Chunk 3, Cluster 4) the manual identity-verify affordance. It does **not** surface the encrypted `decision_data` / `failure_reason` drill-in that `20-PHASE-1-SPEC.md:439–440` anticipates. With manual + mock KYC there is no rich `decision_data` to show — the manual-verify judgment is made against the visible profile fields — so the drill-in has nothing meaningful to render yet. The `CreatorResource` comment already scopes this to "Sprint 4+ when the approval queue ships": Chunk 3 ships the queue (Cluster 3) and defers the PII drill-in to the vendor era.
- **Risk:** low today. The admin can make + audit a manual identity decision without the drill-in (the four fields + the audit trail are load-bearing and present). The gap only bites once a **real** KYC vendor lands and produces structured `decision_data` (document-match scores, liveness results, failure reasons) that a reviewer would want to inspect before overriding or escalating.
- **Mitigation today:** none needed — there is no rich decision data to drill into under manual + mock KYC.
- **Resolution:** ship alongside the real vendor adapter — add a decrypt-on-read, audit-logged admin drill-in for `decision_data` / `failure_reason` (access itself is a compliance-sensitive read and must emit an audit row), gated behind `platform_admin` + surfaced in the detail-page identity section. Pair with the vendor-verify affordance that ships disabled today (D-c3-6).
- **Triggered by:** the real KYC vendor adapter landing (the same trigger that activates the disabled "Request vendor verification" affordance).
- **Owner:** vendor-KYC chunk (Sprint 4+).
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 4 Chunk 3, 2026-06-02.

---

## Auto-blocks on assignment acceptance (Sprint 5 spec `:197`/`:204` acceptance criterion — deferred to Sprint 8)

- **Where:** the spec's Sprint 5 line items include "Auto-blocks created when creator accepts an assignment (linked to assignment_id)" ([`docs/20-PHASE-1-SPEC.md:197`](20-PHASE-1-SPEC.md)) and the matching acceptance criterion "Auto-blocks happen on assignment acceptance" ([`docs/20-PHASE-1-SPEC.md:204`](20-PHASE-1-SPEC.md)). The schema is ready: [`creator_availability_blocks.assignment_id`](../apps/api/database/migrations/2026_05_14_100003_create_creator_availability_blocks_table.php) (nullable, FK deferred until the target table exists) + the [`Kind::AssignmentAuto`](../apps/api/app/Modules/Creators/Enums/Kind.php) case (reserved, NOT creator-settable).
- **What we accepted in Sprint 5 Chunk A (2026-06-03):** the auto-block-on-acceptance behaviour is **forward-blocked** and explicitly deferred to **Sprint 8**. `campaign_assignments` is `NOT FOUND` (Sprint 5 read-only inventory B3) — there is no assignment entity and no acceptance event to fire the auto-block from. Chunk A ships everything buildable on today's schema (manual CRUD + the weekly-recurrence engine + conflict-detection + the agency read-view) and names this criterion as deferred rather than silently dropping it. `Kind::AssignmentAuto` is reserved system-side now (a creator cannot manually mint an "assignment auto" block — D-a2) so the kind is ready the moment the emitter exists.
- **Risk:** low. No creator-facing capability is missing in Phase-1 availability terms — creators still block time manually. The gap is purely the _automatic_ block on acceptance, which has no trigger to attach to yet.
- **Resolution:** in Sprint 8, when `campaign_assignments` + an acceptance event exist, add the FK constraint on `assignment_id`, and emit an `assignment_auto` hard block (linked via `assignment_id`) from the acceptance handler. The conflict-detection service built in Chunk A already treats hard blocks (including future auto-blocks) as conflicts, so no detection rework is needed.
- **Triggered by:** Sprint 8 (`campaign_assignments` + the assignment-acceptance flow).
- **Owner:** Sprint 8 assignments workstream.
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 5 Chunk A, 2026-06-03 ([review](reviews/sprint-5-chunk-a-review.md)).

---

## Conflict-warning modal trigger (Sprint 5 spec `:202` — detection ships now, trigger deferred to Sprint 8)

- **Where:** spec line "Conflict warnings: agency tries to invite creator during a hard block → warning modal" ([`docs/20-PHASE-1-SPEC.md:202`](20-PHASE-1-SPEC.md)). The detection half ships in Sprint 5 Chunk A as a standalone, unit-tested service: [`AvailabilityConflictService`](../apps/api/app/Modules/Creators/Services/Availability/AvailabilityConflictService.php) (given a creator + a date range, does any HARD block — one-off or expanded-recurring — overlap?).
- **What we accepted in Sprint 5 Chunk A (2026-06-03):** the **detection logic** is complete and tested now (D-a5). The **modal trigger** — the agency invite-to-assignment surface that would call the service and render the warning — is deferred to **Sprint 8** because that surface does not exist (inventory B7: roster is list-only, no agency-side invite-to-assignment flow until the assignments work). Building detection standalone now means Sprint 8 only wires the trigger; the overlap logic, the hard-vs-soft distinction, and the single-expansion-source guarantee are already proven.
- **Risk:** low. Nothing regresses — there is no invite flow to warn within yet. The service is dead-code-adjacent until Sprint 8 wires it, but it is fully covered so it cannot rot silently.
- **Resolution:** in Sprint 8, call `AvailabilityConflictService::detect()` from the agency invite/assignment surface and render the warning modal on `hasConflict === true` (the result already carries the overlapping hard occurrences for display).
- **Triggered by:** Sprint 8 (the agency invite-to-assignment surface).
- **Owner:** Sprint 8 assignments workstream.
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 5 Chunk A, 2026-06-03 ([review](reviews/sprint-5-chunk-a-review.md)).

---

## Availability recurrence: spec-vs-data-model contradiction (resolved by keeping weekly; ceiling = weekly)

- **Where:** the Sprint 5 spec lists "Recurring blocks (basic — once per week)" as in-scope ([`docs/20-PHASE-1-SPEC.md:199`](20-PHASE-1-SPEC.md)), while the `creator_availability_blocks` data-model marks `is_recurring` + `recurrence_rule` as **P2 (column from P1)** ([`docs/03-DATA-MODEL.md:288-289`](03-DATA-MODEL.md)). Those two readings contradict: one says build recurrence in Phase 1, the other says the columns are Phase-2 activation.
- **How Sprint 5 Chunk A resolved it (2026-06-03):** **kept** weekly recurrence in scope, per the weekly-recurring market signal (creators predominantly have weekly-recurring availability; biweekly "every other week" is common too — see the INTERVAL decision below). Built a server-side expansion engine ([`AvailabilityExpansionService`](../apps/api/app/Modules/Creators/Services/Availability/AvailabilityExpansionService.php)) on [`rlanvin/php-rrule`](../apps/api/composer.json) with a hard **weekly ceiling** enforced at validation ([`WeeklyRecurrenceRule`](../apps/api/app/Modules/Creators/Rules/WeeklyRecurrenceRule.php)): `FREQ=WEEKLY` + optional `INTERVAL` (every N weeks) + optional `BYDAY` (plain weekday codes) + optional `UNTIL`. Everything else — daily/monthly/yearly, `BYMONTHDAY`, `BYMONTH`, `BYSETPOS`, `COUNT`, numeric-prefixed `BYDAY` (`2MO`), embedded `DTSTART` — is rejected. The library can parse full RFC 5545 RRULE; _we_ only accept/emit weekly.
- **Risk:** low. The ceiling is enforced at validation (a `FREQ=DAILY` cannot reach storage or expansion — break-revert tested), so the P2 columns now carry only weekly rules. Lifting the ceiling later (daily/monthly/custom) is additive: widen `WeeklyRecurrenceRule`'s allowlist + the validation, no schema change (`recurrence_rule` already stores any RRULE string).
- **Resolution:** none required for Phase 1 — the contradiction is resolved in favour of the spec's in-scope reading, bounded to weekly. If a future product need wants daily/monthly/custom recurrence, widen the rule allowlist; the engine + storage already support arbitrary RRULE.
- **Triggered by:** a product requirement for non-weekly recurrence (no current trigger).
- **Owner:** Sprint 5 Chunk A (resolved); future-recurrence work unowned.
- **Status:** resolved (documented). Recorded by Sprint 5 Chunk A, 2026-06-03 ([review](reviews/sprint-5-chunk-a-review.md)).

---

## External calendar sync for availability blocks (`external_calendar_id` / `external_event_id`) — still P2

- **Where:** [`creator_availability_blocks.external_calendar_id` / `external_event_id`](../apps/api/database/migrations/2026_05_14_100003_create_creator_availability_blocks_table.php), marked **P2 (column from P1)** in [`docs/03-DATA-MODEL.md:290-291`](03-DATA-MODEL.md) ("If synced from Google Calendar").
- **What we accepted in Sprint 5 Chunk A (2026-06-03):** untouched — Google Calendar (and similar) two-way sync remains a P2 feature. Chunk A neither reads, writes, nor validates these columns; availability blocks are created/edited entirely in-app. The columns stay nullable + dormant, exactly as shipped in Sprint 3 Chunk 1.
- **Risk:** none today. The columns are inert.
- **Resolution:** a dedicated P2 external-calendar-sync chunk (OAuth into the provider, a sync job, conflict reconciliation between synced events and in-app blocks, and the `external_*` columns as the idempotency key).
- **Triggered by:** the P2 external-calendar-sync feature being scheduled.
- **Owner:** P2 / unscheduled.
- **Status:** open (P2, untouched). Re-confirmed by Sprint 5 Chunk A, 2026-06-03.

---

## Availability calendar WEEK view deferred to a follow-on chunk (month view shipped)

- **Where:** the creator availability calendar UI. Month view ships in Sprint 5 Chunk B: [`AvailabilityCalendar.vue`](../apps/main/src/modules/creators/availability/components/AvailabilityCalendar.vue) over the shared [`CMonthGrid`](../packages/ui/src/components/CMonthGrid.vue) primitive. There is **no week view**.
- **What we accepted in Sprint 5 Chunk B (2026-06-03):** D-b1 phased the calendar deliberately. **Effort read (inventory B2):** the **6×7 month grid is tractable hand-rolled work** — a flat calendar matrix with day-level entries, no intra-day geometry. The **week view is a different order of complexity**: it needs timed-lane layout + **overlap-packing math** (computing how many concurrent blocks share a time slot and how to lay them out side-by-side without collision). That overlap geometry is disproportionately heavy relative to the rest of the calendar and deserves its own build + eyes-on pass rather than being rushed into this chunk. The month view is a complete, useful product on its own (it shows every block as a day-level bar, multi-day blocks span their covered days); the week view is a power-user zoom.
- **Risk:** low. Month view covers the core need — a creator can see, create, edit, and delete blocks across the month, with recurrence. The week view is an enhancement, not a gap in the core flow. No data model or API work is deferred (the backend already returns the occurrence shape a week view would consume).
- **Honest-deviation guard held:** the month-grid rendering was kept strictly day-level — multi-day blocks paint each covered day (end-exclusive at midnight), never intra-day lanes. The moment all-day-vs-timed rendering would have pulled in week-view-grade overlap math, that was the deferred view leaking in; it was kept out.
- **Resolution:** a focused follow-on chunk builds the week view — the timed-lane layout + overlap-packing algorithm (and, if a headless layout helper is wanted purely for the packing geometry, that dependency decision belongs to _that_ chunk, not this one — D-b2). It can reuse this chunk's `availability.api.ts`, tz helpers (`datetime.ts`), recurrence helpers (`recurrence.ts`), and the create/edit dialog as-is.
- **Triggered by:** the follow-on week-view chunk being scheduled.
- **Owner:** Sprint 5 week-view follow-on / unscheduled.
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 5 Chunk B, 2026-06-03 ([review](reviews/sprint-5-chunk-b-review.md)).

---

## Availability block-not-found 404 is Laravel's default `{message}`, not the canonical error envelope

- **Where:** [`CreatorAvailabilityController::resolveBlock()`](../apps/api/app/Modules/Creators/Http/Controllers/CreatorAvailabilityController.php) — `firstOrFail()` on `update`/`destroy` of a non-existent (or non-owned) block ULID. `ModelNotFoundException` → Laravel's stock `{ "message": "..." }` 404, **not** the `{ errors: [{ code, detail, source }] }` envelope the SPA's `ApiError`/`extractFieldErrors` parse. The SPA renders it as a generic unknown-error.
- **What we accepted in Sprint 5 Chunk B (2026-06-03):** flagged but **not fixed** — out of scope for a calendar UI chunk. It is an **edge case** (the block was deleted in another tab between load and the edit/delete call) and the structural owner-only 404 it produces is correct behaviour; only the envelope shape is off. The SPA already shows a sane generic error for it.
- **Risk:** low. Cosmetic at the error-copy level on a rare race; no security or data issue (the 404 itself is the correct owner-only guard, Chunk A).
- **Why not fixed here:** the right fix is an **API-wide `ModelNotFoundException` → canonical-envelope renderer** (in the global exception handler), so every `firstOrFail()` across the monolith emits the standard envelope — not a calendar-local catch that would diverge from the platform pattern. That is a backend cross-cutting concern, not a Chunk-B (frontend) deliverable.
- **Resolution:** register a `ModelNotFoundException` handler in the API exception renderer that emits `{ errors: [{ status: '404', code: '<resource>.not_found', detail }] }`, then drop any per-controller workarounds.
- **Triggered by:** the next API error-envelope hardening pass, or the first user-facing report of a confusing delete-in-another-tab error.
- **Owner:** backend platform / unowned.
- **Status:** open (accepted — cosmetic edge case). Surfaced by Sprint 5 Chunk B, 2026-06-03 ([review](reviews/sprint-5-chunk-b-review.md)).

---

## Agency availability read endpoint — consumer loop CLOSED (Sprint 5 Chunk A → Sprint 6 Chunk 2a)

- **Where:** [`AgencyCreatorAvailabilityController`](../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorAvailabilityController.php) + [`AgencyAvailabilityResource`](../apps/api/app/Modules/Agencies/Http/Resources/AgencyAvailabilityResource.php) (`GET /agencies/{agency}/creators/{creator}/availability`). Built standalone in Sprint 5 Chunk A (D-a6) **ahead of any SPA consumer** — the Chunk-A review noted "Sprint 6's creator-detail page will consume it."
- **What closed in Sprint 6 Chunk 2a (June 3, 2026):** the per-creator detail view (D-2a-9) now consumes the endpoint via a **read-only** agency calendar — [`AgencyAvailabilityCalendar.vue`](../apps/main/src/modules/roster/components/AgencyAvailabilityCalendar.vue) reusing the `CMonthGrid` primitive + the pure `datetime.ts` bucketing helpers (NOT the creator-coupled `AvailabilityCalendar.vue`, which carries the creator-self API + edit dialog), behind a new agency-scoped wrapper [`agencyAvailability.api.ts`](../apps/main/src/modules/roster/api/agencyAvailability.api.ts). The Sprint-5 deferral's loop is closed: the endpoint built-ahead-of-consumer now has its consumer.
- **The `reason` omission, kept type-clean WITHOUT a "reason-optional" change (divergence 1):** the kickoff floated loosening `AvailabilityOccurrenceAttributes.reason` to optional so the agency path (which omits `reason`) would type-check. **Rejected in favour of a dedicated FE type** — [`AgencyAvailabilityOccurrenceAttributes`](../packages/api-client/src/types/agency.ts) where `reason` is structurally **absent** (not optional). This **mirrors the backend's existing dedicated `AgencyAvailabilityResource` discipline** (separate shape so `reason` cannot leak through a shared type) rather than weakening the creator-self path's `reason` guarantee. The creator-self `AvailabilityOccurrenceAttributes` is untouched — its `reason` stays non-optional.
- **Risk:** none — this is a closure note, not an accepted gap. Recorded so the cross-sprint handoff (build-ahead-of-consumer → consumer) is auditable.
- **Status:** **CLOSED** — Sprint 6 Chunk 2a, 2026-06-03 ([review](reviews/sprint-6-chunk-2a-review.md)).

---

## Creator-side connection-requests: no pending-count badge on the nav (deferred nicety)

- **Where:** the creator surface nav/topbar. The Sprint 6.6c inbox ([`CreatorDashboardPage.vue`](../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue) approved branch) renders incoming agency requests inline, but there is **no count badge** anywhere (e.g. a `<v-badge>` on a nav item showing "3 pending").
- **What we accepted in Sprint 6.6c (2026-06-03, D-d):** deferred deliberately. There is **no reusable creator-side badge primitive** (no `CStatusBadge`/creator-bell to hang it on — it would be hand-rolled), and a count badge would introduce the **first always-on creator-side fetch** (the requests would have to be counted on every navigation, not just on the dashboard landing). The requests are already visible on the dashboard — the creator's natural landing — so the "know without hunting" value is marginal at v1.
- **Risk:** none. The inbox is fully functional; the badge is purely an at-a-glance affordance.
- **Resolution:** when a creator-side nav/notification surface exists (or the creator surface grows past its deliberately-thin 2 items), add a `<v-badge>` + a lightweight count source (a `?count_only=1` on the list endpoint, or reuse of the list call). Pairs naturally with the creator-side connections page below.
- **Triggered by:** the creator surface gaining a notification/nav affordance, or product wanting the at-a-glance pending count.
- **Owner:** future creator-surface chunk / unscheduled.
- **Status:** open (deferred nicety). Surfaced + accepted by Sprint 6.6c, 2026-06-03 ([review](reviews/sprint-6-6c-review.md)).

---

## Creator-side connections/roster page (post-accept has no click-through destination)

- **Where:** the creator surface. After a creator **accepts** a request in the Sprint 6.6c inbox, the relation becomes `roster` — but there is **no creator-side page that lists the agencies they're connected with**. The accept UX is "the row disappears + a toast naming the agency" ([`CreatorDashboardPage.vue`](../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue), D-d6), with **no click-through** to a connections list because no such destination exists.
- **What we accepted in Sprint 6.6c (2026-06-03, D-d6):** the creator surface is deliberately thin (dashboard + availability only). A post-accept click-through would have no honest destination, so "row disappears + toast" is the complete, honest v1. Building a connections page was explicitly out of scope for this chunk.
- **Risk:** low. The accept succeeds and is persisted (the agency sees the creator on its roster); the creator simply has no in-app view of _their_ side of the relationship yet. The data already exists (the `roster` relations are queryable creator-side, the same way the inbox queries `pending_request`).
- **Resolution:** a future creator-surface chunk adds a connections/roster list (the creator's `roster` relations, agency name + connected-since), at which point the 6.6c accept toast can gain a "View connections" click-through and the pending-count badge above gets its home.
- **Triggered by:** the creator-side connections page being scheduled.
- **Owner:** future creator-surface chunk / unscheduled.
- **Status:** open (deferred — future surface). Surfaced + accepted by Sprint 6.6c, 2026-06-03 ([review](reviews/sprint-6-6c-review.md)).

---

## Brand-scoped blacklist is recorded-now, enforced-at-campaign-matching-later (Sprint 8)

- **Where:** [`brand_creator_blacklists`](../apps/api/database/migrations/2026_06_04_100000_create_brand_creator_blacklists_table.php) + [`CreatorBlacklistController`](../apps/api/app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php) (the brand-scoped write path, D-2/A3).
- **What we accepted in Sprint 7 (2026-06-04, B1):** Sprint 7 ships the **full brand-scoped write surface** — the table, model (audited, soft-deleted), the blacklist/un-blacklist endpoints, and the dialog's brand picker. But a brand-scoped blacklist **does NOT yet bite on any read surface**:
  - **Discovery is agency-level (no brand context)** — the discovery exclusion (B1) is deliberately scoped to **agency-wide hard** blacklists on the relation only; a brand-scoped row never affects whether a creator appears in an agency's discovery. Confirmed by `BlacklistEnforcementTest` ("a brand-scoped blacklist does NOT affect discovery").
  - **The KPI counts are agency-level** — brand-scoped rows never touch the roster/pending counts (B3, same test file).
  - The brand-scoped blacklist's **only effect today is "recorded"** (an audited row, surfaced nowhere as a filter). Its **matching effect bites at campaign-matching time**, which does not exist until Sprint 8.
- **Why deferred:** there is **no brand-level campaign matching yet** to enforce against. Building a brand-scoped exclusion read path now would have no consumer (and would tempt building Sprint 8's matching early — explicitly out of scope per the kickoff). The table + write path ship now so the data + audit trail accrue from day one; the enforcement lands when brand-level matching exists.
- **Risk:** low-to-moderate. The data is captured correctly and isolated (cross-agency via `brands.agency_id`); the gap is purely "recorded but not yet enforced." If Sprint 8 ships campaign matching without wiring the brand-scoped exclusion, a brand-scoped blacklist would be silently inert — the matching chunk MUST consume `brand_creator_blacklists` (hard = exclude from that brand's campaign matches; soft = warn).
- **Resolution:** Sprint 8 (brand-level campaign matching) consumes `brand_creator_blacklists` at match time — `blacklist_type='hard'` excludes the creator from that brand's matchable pool, `soft` surfaces a warning. The agency→brand derivation is `brands.agency_id` (no `agency_id` on the blacklist table by design — D-2).
- **Triggered by:** Sprint 8 brand-level campaign matching.
- **Owner:** Sprint 8 (campaign matching).
- **Status:** ✅ **RESOLVED — Sprint 8 Chunk 2 (2026-06-05, [review](reviews/sprint-8-chunk-2-review.md)).** The agency invite create-path (`POST agencies/{agency}/campaigns/{campaign}/assignments`) now runs the two-tier gate's **hard-blacklist predicate** ([`AssignmentInviteGate::isHardBlacklisted`](../apps/api/app/Modules/Campaigns/Services/AssignmentInviteGate.php)), which composes BOTH scopes — agency-wide hard on `agency_creator_relations` **and** a hard `brand_creator_blacklists` row for `(campaign.brand_id, creator_id)`. A hard brand-scoped row for THIS campaign's brand now returns `422 assignment.blacklisted`; a row for a DIFFERENT brand does not block (per-brand scope, `brands.agency_id` derivation). Pinned by `CampaignAssignmentInviteTest` (the deferred-promise break-revert: dropping the brand predicate makes the invite wrongly succeed). Soft (either scope) does not block.

---

## Pool-side "Add creators" is FE-only (no server exclusion, no batch add, no server-side picker search)

- **Where:** the pool detail page picker [`AddCreatorsToPoolDialog.vue`](../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.vue), wired into [`PoolDetailPage.vue`](../apps/main/src/modules/pools/pages/PoolDetailPage.vue). It reuses the existing idempotent, relation-gated `store` (`TalentPoolMembershipController::store`) via `talentPoolsApi.addCreator`, sourced from the roster (`rosterApi.list`), with **zero backend changes**.
- **What we accepted in the pool-add chunk (2026-06-04, D-1..D-5):** the discoverability gap (the pool page could list/remove members but had no add entry point) was closed **frontend-only** by taking the small version of every call. Three server-side enhancements were deliberately deferred:
  1. **Per-pool `is_member` roster annotation (server-side exact exclusion).** Today current-member exclusion is **client-side and page-local** (D-3): the picker fetches the pool's members (`talentPoolsApi.members`, paginated at 25) and subtracts them from the roster in the FE. On a large pool (>25 members) the exclusion is **partial** — an already-member creator beyond the first members page may still be offered. Re-adding them hits the idempotent `firstOrCreate` `store`, so it is a **harmless no-op** (no duplicate, no error), making the partial filter only ever _cosmetic_.
  2. **A batch add endpoint.** Multi-add **loops the single `store`** (D-4) — N requests for N selected creators. No batch route exists; one would be net-new backend (out of scope).
  3. **Server-side picker search (`?q=` FTS).** Search is **client-side** over a single wide roster page (D-5, `per_page: 100`). The server `?q=` full-text filter is fully plumbed through `rosterApi.list` but unused here; creators beyond the fetched page are not searchable.
- **Risk:** low. The add path is correct-by-construction (every roster creator has an `AgencyCreatorRelation`, so the `requireRosterRelation()` gate can never reject a roster-sourced add) and idempotency makes the partial exclusion safe. The gaps are cosmetic / scale-of-volume, not correctness.
- **Triggered by:**
  1. **Annotation** — the client-side partial exclusion on large pools becomes **visibly confusing** (already-members repeatedly shown). Promote to a pool-aware `is_member` flag on the roster query for exact, page-independent exclusion.
  2. **Batch add** — agencies routinely add **many** creators at once and the per-creator request loop is slow/janky. Promote to a single batch-add endpoint.
  3. **Server-side search** — rosters grow large enough that **client-local search misses creators beyond the fetched page**. Promote by threading the existing `rosterApi.list` `?q=` FTS into the picker.
- **Resolution:** the respective promote-trigger chunk owns each enhancement; until then the FE-only version is the complete, honest v1.
- **Owner:** future talent-pools workstream / unscheduled.
- **Status:** open (deferred by design — FE-only v1). Surfaced + accepted by the pool-add chunk, 2026-06-04 ([review](reviews/pool-add-creators-review.md)).

---

## Blacklist visibility in talent pools (a blacklisted creator could sit in a staffing pool with no indication)

- **Where:** the pool member list [`PoolDetailPage.vue`](../apps/main/src/modules/pools/pages/PoolDetailPage.vue) + the add-creators picker [`AddCreatorsToPoolDialog.vue`](../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.vue); the member resource [`TalentPoolMemberResource`](../apps/api/app/Modules/TalentPools/Http/Resources/TalentPoolMemberResource.php) + its query in [`TalentPoolMembershipController::index`](../apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php).
- **The footgun (surfaced by the blacklist-in-pools read-pass inventory):** the pool member list rendered creators with **no blacklist indication**, and the picker let you add a blacklisted creator with no warning — so a blacklisted creator could be staffed onto a pool silently. Engagement enforcement still bites at the connection-request layer (a hard-blacklisted creator can't be sent a request), but the **pool surface gave no visibility** where you'd actually act on it.
- **✅ RESOLVED — blacklist-in-pools chunk (2026-06-05, [review](reviews/blacklist-in-pools-review.md)). Decision: WARN, don't remove.** A blacklisted creator **stays** a pool member (no silent removal, no hard block — enforcement stays at the connection-request layer); the blacklist is made **visible**:
  - **Member-list badge** (the scoped backend extension): `TalentPoolMemberResource` gained `is_blacklisted` + `blacklist_type` (status + hard/soft, **NOT the reason** — 2a parity), fed by two `addSelect` subqueries on `agency_creator_relations` **scoped to `agency_id = pool.agency_id`** (D-4 — the per-agency privacy invariant: agency A's blacklist is invisible in agency B's pool, pinned by `TalentPoolMembershipTest`).
  - **Picker per-row flag + hard-only confirm-on-add** (pure FE): every picker row shows the blacklist flag (hard + soft) before selecting; `addSelected` fires a confirm **only for HARD**-blacklisted creators (soft shows the flag but no confirm — friction only where the mistake is costly).
  - **Shared `BlacklistBadge`** ([`packages/ui`](../packages/ui/src/components/BlacklistBadge.vue)) was extracted (the 4th use — roster list + 2a detail migrated behavior-preservingly, + pool member list + picker rows) — i18n-free, hard=`error`/soft=`warning` tonal map.
- **Out of scope (logged):** silent removal / hard block of blacklisted pool members (the decision is warn-don't-remove); a "remove all blacklisted from this pool" bulk action (a future nicety — log if it comes up); the brand-scoped blacklist's effect on pools (brand-scoped is recorded-now / enforced-at-Sprint-8 and never touches the relation flag, so a brand-scoped-only blacklist does NOT set `is_blacklisted` → no pool badge, consistent — revisit if a reviewer wants brand-scoped to surface in the agency pool).
- **Owner:** resolved (blacklist-in-pools chunk).

---

## Pre-existing Larastan red in `AgencyCreatorRosterTest.php` (`collect()` template-type resolution)

- **Where:** [`tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php`](../apps/api/tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php), the `exposes blacklist_type (hard/soft/null)…` test (the single `collect(...)` call near the end of the file).
- **What it was:** `composer stan` (Larastan, level 8) reported **2 errors** on the one line — `Unable to resolve the template type TKey in call to function collect` + `… TValue …` (identifier `argument.templateType`). The test passed `collect($response->json('data'))` a `mixed` (`TestResponse::json()` returns `mixed`), so Larastan couldn't infer `collect()`'s `TKey`/`TValue`. Backend CI runs Larastan **before** Pest, so this gated the whole backend job (Pest never ran) — the working-tree/CI red on `1e26f39` (and surfaced again on the blacklist-in-pools push) was **this**, not the feature chunks. Pre-existing, untouched by the recent chunks.
- **✅ RESOLVED — 2026-06-05, commit `774168d`.** Cast the `mixed` to `array` — `collect((array) $response->json('data'))` — mirroring the `(array)` idiom the membership tests already use, so `collect()` resolves concrete template types. Verified: full `composer stan` is **0 errors** (526 files) and CI on `774168d` is green (all four jobs). No production code touched — a one-line test-only fix.
- **Note:** the original intent was to _log_ this as open red with a "tidy whenever something next touches the file" trigger; by the time the housekeeping pass ran it had already been fixed in the CI-unblock commit above, so it is recorded here as **resolved** rather than open. The unrelated `block_type` column on `creator_availability_blocks` (data-model §6/availability) is a legitimate as-built name, not part of this red.

---

## Assignment board is event-emitting but listener-less (the board sprint is purely additive)

- **Where:** the state machine [`CampaignAssignmentStateMachine`](../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) dispatches `AssignmentTransitioned` (implementing [`AssignmentEventContract`](../apps/api/app/Modules/Campaigns/Events/AssignmentEventContract.php)) on **every** legal transition, carrying `{from, to}`, the board-key `eventKey()` (an [`AuditAction`](../apps/api/app/Modules/Audit/Enums/AuditAction.php)), and `triggeredByUserId`. There is **no listener** registered for it anywhere.
- **What we accepted in Sprint 8 Chunk 1 (2026-06-05, D-9):** the machine **double-writes** — it logs to `audit_logs` directly **AND** dispatches a Laravel domain event — but ships **no consumer**. The event is the **board's future vocabulary, declared early**: the audit-verb catalogue and the event-key catalogue were deliberately aligned (D-9), so the board sprint can subscribe a listener without the machine changing at all. Three verbs intentionally differ from their landing-state because they match the **board event-key catalogue** ([`10-BOARD-AUTOMATION.md`](10-BOARD-AUTOMATION.md)) not the status enum: `assignment.draft_approved` (→`approved`), `assignment.posted_by_creator` (→`posted`), `assignment.payment_funded` (→`payment_held`).
- **Risk:** very low. A dispatched event with no listener is a no-op; nothing depends on it yet. The only cost is that the event class set must stay in step with the verb catalogue (pinned by the state-machine tests + `AuditActionEnumTest`).
- **Triggered by:** the board-automation sprint — it registers the listener that turns these events into card movements.
- **Resolution:** the board sprint owns the listener; the emitter + vocabulary are already in place (purely additive).
- **Owner:** future board-automation workstream.
- **Status:** open (deferred by design — emitter shipped, consumer deferred). Surfaced + accepted by Sprint 8 Chunk 1, 2026-06-05 ([review](reviews/sprint-8-chunk-1-review.md)).

---

## Counter-offer is single-shot, not a negotiation loop

- **Where:** [`CampaignAssignmentStateMachine::counter()`](../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) (`invited → countered`) + the `countered_fee_minor_units`/`countered_fee_currency` columns on `campaign_assignments` ([`03-DATA-MODEL.md §7`](03-DATA-MODEL.md)).
- **What we accepted in Sprint 8 Chunk 1 (2026-06-05, D-7):** `counter()` records **one** creator counter-offer in the net-new `countered_fee_*` columns (preserving the agency's original `agreed_fee_*` so the delta is inspectable). There is **no multi-round back-and-forth** — `countered` is a single landing state from which the next legal move is accept/decline; the machine has no `countered → countered` re-counter edge and no per-round history table.
- **Risk:** low. The single-counter model captures the common case (one number comes back); a richer negotiation is a Phase-2+ concern. The columns + the `assignment.countered` board verb are in place, so promoting to a loop is additive.
- **Triggered by:** product demand for true multi-round negotiation (re-counter / counter-the-counter), or a negotiation-history requirement.
- **Resolution:** add a `countered → countered` edge (or a `assignment_fee_offers` history table) when the loop is needed; until then single-shot is the honest v1.
- **Owner:** future campaigns/negotiation workstream.
- **Status:** open (deferred by design — single counter, no loop). Surfaced + accepted by Sprint 8 Chunk 1, 2026-06-05 ([review](reviews/sprint-8-chunk-1-review.md)).

---

## Vendor-gated assignment transitions are built + guarded but unreachable (social verification + escrow parked)

- **Where:** [`CampaignAssignmentStateMachine`](../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) — the `verifyLive()` (`posted → live_verified`), `holdPayment()` (`live_verified → payment_held`), and `releasePayment()` (`payment_held → payment_released`) transitions, each fronted by an `assertVendorAvailable()` gate that throws [`AssignmentTransitionGatedException`](../apps/api/app/Modules/Campaigns/Exceptions/AssignmentTransitionGatedException.php). `accepted → contracted` is separately gated on the `ContractSigningEnabled` Pennant flag.
- **What we accepted in Sprint 8 Chunk 1 (2026-06-05, D-6):** these transitions have full source-guards + audit + event wiring, but the vendor gate **refuses every call** — social verification is parked (the social adapter is not integrated) and escrow funding/payout is Sprint 10. So `live_verified`, `payment_held`, and `payment_released` are **states no manual path can reach** this chunk; the contract step is reachable only when the `ContractSigningEnabled` mock flag is on. This is verified by the state-machine tests (the gated transitions throw `AssignmentTransitionGatedException`; no legal path lands on the gated states with the gate closed).
- **Risk:** low and fail-closed — the gate is a hard refusal, not a silent skip, so a premature call surfaces as a typed error rather than a half-finished state. The cost is that the post→verify→pay tail of the lifecycle is inert until its vendors land.
- **Triggered by:** (1) the social-integration chunk wires real post verification → flip the `verifyLive` gate open; (2) Sprint 10 escrow wires funding/payout → flip the `holdPayment`/`releasePayment` gates open; (3) contract e-sign goes live → default `ContractSigningEnabled` on.
- **Resolution:** each vendor chunk owns flipping its own gate; the transitions + guards + board verbs are already in place (additive).
- **Owner:** future social-integration + Sprint-10 payments workstreams.
- **Status:** open (deferred by design — gated unreachable). Surfaced + accepted by Sprint 8 Chunk 1, 2026-06-05 ([review](reviews/sprint-8-chunk-1-review.md)).

---

## S10 payment-release gate must consume `isPaymentEligible()`, not the literal `live_verified`

- **Where:** [`AssignmentStatus::isPaymentEligible()`](../apps/api/app/Modules/Campaigns/Enums/AssignmentStatus.php) — returns true for **both** `live_verified` and `manually_verified`. Added by the verification-resolution chunk so the agency's manual override of a failed auto-verification (`manually_verified`) is payment-eligible alongside a real auto-verification.
- **What we accepted (verification-resolution chunk, D-3):** `manually_verified` is a payment-eligible state **today**, but no payment is built this chunk (escrow is Sprint 10, still vendor-gated). The predicate is **proven now** — a tripwire test asserts both `live_verified` and `manually_verified` satisfy `isPaymentEligible()` and `posted` does not — so the contract is locked before the consumer exists. The risk is purely forward: when S10 wires the "Release payment" button + the auto-release listener, it MUST gate on `isPaymentEligible()` (or `holdPayment()`'s source guard must accept both states), NOT on a literal `status === 'live_verified'` check — otherwise a manually-verified assignment dead-ends at payment (the exact failure-relocation this chunk exists to prevent). `holdPayment()`'s source guard currently accepts only `live_verified` (it is vendor-gated + unreachable, so harmless today); S10 must widen it to the payment-eligible set when it flips the escrow gate open.
- **Risk:** low now (no consumer), medium at S10 if missed — a silent dead-end rather than a hard error. Mitigated by the predicate + tripwire being in place and this note.
- **Triggered by:** Sprint 10 escrow wiring the release flow.
- **Resolution:** S10 consumes `isPaymentEligible()` in the release-gate + widens `holdPayment()`'s source set to `{live_verified, manually_verified}`.
- **Owner:** Sprint-10 payments workstream.
- **Status:** open (deferred by design — predicate proven, consumer is S10). Surfaced + accepted by the verification-resolution chunk, 2026-06-05.

---

## `campaign_drafts` + `campaign_posted_content` tables deferred to Sprint 9

- **Where:** spec'd at [`03-DATA-MODEL.md §7`](03-DATA-MODEL.md) (`campaign_drafts`, `campaign_posted_content`); **no migration** builds them in Sprint 8 Chunk 1.
- **What we accepted in Sprint 8 Chunk 1 (2026-06-05, D-4):** both tables are deferred. The finding that made this safe: `campaign_assignments` carries **no FK** to either table — they are **children** of the assignment (each points _up_ via `assignment_id`), and `draft_submitted` is only an `AssignmentStatus` enum value. Nothing in Chunk 1 reads or writes a draft or posted-content row, so deferring them is a pure no-op for everything shipped this chunk.
- **Risk:** low. The dependency direction (children → assignment) means the parent table is complete without them; building them later is additive and touches no Chunk-1 code.
- **Triggered by:** the Sprint 9 chunk that builds the draft-submission/review flow (`campaign_drafts`) and posted-content verification (`campaign_posted_content`).
- **Resolution:** Sprint 9 owns both migrations + their models/flows; the `draft_submitted`/`posted` states they hang off already exist in the enum + state machine.
- **Owner:** Sprint 9 (drafts + posted content).
- **Status:** ✅ **CLOSED — built by Sprint 9 Chunk 1, 2026-06-05** ([review](reviews/sprint-9-chunk-1-review.md)). Both tables migrated (`campaign_drafts`, `campaign_posted_content`) with the assignment-CASCADE FKs + their `CampaignDraft`/`CampaignPostedContent` models, factories, enums, the creator-self submission endpoints, and `Sprint9MigrationTest` pinning the full column set. Chunk 1 wires the submission side (drafts submit/resubmit, posted-content) through `posted`/`verification_status=pending`; the review trail + verification job are Chunk 2.

---

## Assignment fee is NOT validated against the campaign budget (Sprint 8 Chunk 2)

- **Where:** [`InviteAssignmentRequest`](../apps/api/app/Modules/Campaigns/Http/Requests/InviteAssignmentRequest.php), [`ReinviteAssignmentRequest`](../apps/api/app/Modules/Campaigns/Http/Requests/ReinviteAssignmentRequest.php), [`CounterAssignmentRequest`](../apps/api/app/Modules/Creators/Http/Requests/CounterAssignmentRequest.php) (the three fee-bearing FormRequests).
- **What we accepted in Sprint 8 Chunk 2 (2026-06-05, D-8):** the `agreed_fee_*` (invite/re-invite) and `countered_fee_*` (creator counter) validation enforces only **shape** — a positive integer in minor units + an ISO-3 currency matching the campaign's `budget_currency` when set. It is deliberately **NOT** constrained against `campaign.budget_minor_units`: neither the per-assignment fee nor the sum of all assignment fees is checked against the campaign budget. Per-assignment-vs-budget (and aggregate-spend-vs-budget) is a **business-tracking** concern, not a per-field validation rule, so it is out of scope this chunk.
- **Risk:** low. Over-committing a campaign's budget is an agency-internal financial concern, not a tenancy/security boundary; the fees are recorded faithfully (in minor units, currency-checked) so a future budget-tracking surface has clean data to aggregate. Nothing silently breaks — an agency can simply invite past the budget today.
- **Resolution:** a future budget-tracking chunk surfaces committed-vs-budget (sum of `agreed_fee_minor_units` across non-terminal assignments vs `campaign.budget_minor_units`), as a soft warning or a hard cap per product call — TBD.
- **Triggered by:** the budget-tracking / campaign-spend workstream (unscheduled).
- **Owner:** Sprint 8+ (campaign finance).
- **Status:** open (deferred by design). Surfaced + accepted by Sprint 8 Chunk 2, 2026-06-05 ([review](reviews/sprint-8-chunk-2-review.md)).

---

## Nullable `campaign.budget_currency` weakens the assignment-fee currency check (Sprint 8 Chunk 2)

- **Where:** the same three fee FormRequests above; the campaign currency source is `campaigns.budget_currency`, which is **nullable**.
- **What we accepted in Sprint 8 Chunk 2 (2026-06-05, divergence #2):** D-8 says the fee currency must equal the campaign's single currency. But `budget_currency` is nullable (a campaign can exist with no budget set). The rule therefore validates `fee_currency === campaign.budget_currency` **only when `budget_currency` is set**; when it is `null`, any valid ISO-3 code is accepted (there is no campaign currency to match against). This keeps invite/counter buildable on budget-less campaigns rather than hard-blocking them.
- **Risk:** low. The window is narrow (a budget-less campaign) and the fee currency is still shape-valid (ISO-3); the only gap is that two assignments on the same budget-less campaign could in principle carry different currencies. No security/tenancy impact.
- **Resolution:** if/when `budget_currency` becomes required at campaign creation (or a campaign-level currency is split out from the budget), tighten the rule to always match. Until then the conditional match is the correct behaviour for the nullable column.
- **Triggered by:** a future change making campaign currency mandatory.
- **Owner:** Sprint 8+ (campaigns).
- **Status:** open (deferred by design — conditional on a nullable column). Surfaced + accepted by Sprint 8 Chunk 2, 2026-06-05 ([review](reviews/sprint-8-chunk-2-review.md)).

---

## Agency re-invite UI was backend-ready but UI-pending (Sprint 8 Chunk 2 → re-invite UI chunk)

- **Where:** [`CampaignDetailPage.vue`](../apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue) Creators tab — the assignment list shipped read-only in Chunk 1 and gained invite in Chunk 2, but no row surfaced `countered_fee_*` or triggered `campaignsApi.reinvite()` even though the endpoint, api-client types, wrapper, and backend tests all shipped in Chunk 2.
- **What it was:** the counter→re-invite loop was whole at the backend (`countered → invited` via `reinvite()`) but broken at the agency UI — a creator could counter into a dead end. Surfaced by the Sprint-8 eyes-on walk.
- **✅ RESOLVED — 2026-06-05, re-invite UI chunk.** [`ReinviteDialog.vue`](../apps/main/src/modules/campaigns/components/ReinviteDialog.vue) + Creators-tab row enrichment (both fees on countered rows, status chip, fail-closed `countered && canInvite` re-invite action, post-success `loadAssignments()` + snackbar). Frontend-only — zero backend change. See [review](reviews/reinvite-ui-review.md).

---

## Campaign-detail Drafts tab orphaned (Phase 1 spec §Sprint 8)

- **Where:** [`20-PHASE-1-SPEC.md`](20-PHASE-1-SPEC.md) lists Drafts as a campaign-detail tab; S9 shipped the review machinery (drawer + three endpoints) but the tab stayed a coming-soon empty state.
- **What we accepted:** the Creators-tab review path covered the operational workflow; a campaign-wide draft queue was specced but not wired.
- **✅ RESOLVED — campaign-detail Drafts tab chunk.** `GET …/campaigns/{campaign}/drafts` + `CampaignDraftListItemResource` (summary, no signed media) + `DraftsTab` on campaign detail (lazy-mounted, filterable, paginated). Reuses `ReviewDraftDrawer` + existing review endpoints unchanged.

---

## Denormalize `campaign_id` onto `campaign_drafts` if the campaign-drafts query is slow at volume

- **Where:** [`CampaignDraftController::index`](../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignDraftController.php) — the campaign-wide list uses a two-hop join (`campaign_drafts → campaign_assignments` filtered by `campaign_id` + `agency_id`). Indexes today: `unique_assignment_campaign_creator` (left-prefix on `campaign_id`) + `idx_drafts_assignment_review_status`.
- **What we accepted:** at Phase-1 volume (tens to low-hundreds of drafts per campaign) the join is fine; no preemptive denormalization.
- **Risk:** low now; grows if campaigns routinely carry thousands of draft versions.
- **Triggered by:** the campaign-drafts list query becomes a measurable slow path (p95 latency or explain-plan regression at realistic volume).
- **Resolution:** add nullable `campaign_id` to `campaign_drafts`, backfill from `assignment_id`, index `(campaign_id, review_status)`, simplify the list query to a single-table filter.
- **Owner:** campaigns (when triggered).
- **Status:** open (deferred by design — logged at ship time).

---

## No agency-side campaign-detail Playwright E2E (re-invite UI chunk)

- **Where:** [`CampaignDetailPage.vue`](../apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue) — Vitest component coverage exists (`CampaignDetailPage.spec.ts`, `ReinviteDialog.spec.ts`); no Playwright harness exercises the campaign-detail Creators tab end-to-end.
- **What we accepted in the re-invite UI chunk (2026-06-05):** the counter→re-invite round-trip is pinned by Vitest only (mocked API). A browser E2E would need a seeded countered assignment + agency staff session — heavier than this chunk's scope.
- **Risk:** low. Vitest pins the fail-closed gates, fee display, dialog wiring, and 422 binding; the backend reinvite path is already feature-tested in [`CampaignAssignmentInviteTest`](../apps/api/tests/Feature/Modules/Campaigns/CampaignAssignmentInviteTest.php).
- **Triggered by:** the next chunk that materially extends the campaign-detail frontend (e.g. per-row cancel, expanded assignment detail, or a dedicated campaign-detail Playwright harness).
- **Resolution:** add a counter→re-invite round-trip Playwright E2E when the campaign-detail surface grows enough to warrant its own browser harness.
- **Owner:** Sprint 8+ (campaigns FE).
- **Status:** open (deferred by design — Vitest-only this chunk). Surfaced + accepted by re-invite UI chunk, 2026-06-05 ([review](reviews/reinvite-ui-review.md)).

---

## Accepted → contracted gap (eyes-on finding — CLOSED)

- **Where:** Sprint 9 eyes-on walk found `accepted` was a dead-end: `CampaignAssignmentStateMachine::contract()` existed but no HTTP/UI called it.
- **What we shipped in the contract-bridge chunk (2026-06-05):** agency attach (`POST …/contract/attach`) + creator accept (`POST …/contract/accept`) + manual two-party UI. Accept drives `contract()` and stops at `contracted`; existing draft submit handles the rest.
- **Status:** CLOSED — 2026-06-05 ([review](reviews/contract-bridge-review.md)).

---

## Brand-side contract acceptance (P2)

- **Where:** two-party contract design in Phase 1 spec; `brand_users` table has no P1 actor.
- **What we accepted in contract-bridge chunk (2026-06-05, D-1):** P1 = agency attaches + creator accepts only. Brand click-accept deferred.
- **Triggered by:** P2 brand portal / `brand_users` actor exists.
- **Owner:** future brand workstream.
- **Status:** open.

---

## `requires_per_campaign_contract` gating (P2)

- **Where:** `campaigns.requires_per_campaign_contract` column + Settings toggle; no runtime wiring.
- **What we accepted in contract-bridge chunk (2026-06-05, D-8):** informational only — agency may attach regardless; no gate on attach/accept.
- **Triggered by:** product rule that attach is mandatory when flag is set.
- **Owner:** future campaigns workstream.
- **Status:** **closed** in the contract-gate-decouple chunk (2026-06-05, D-8/D-9). The column is now a **runtime switch**: `CampaignAssignmentContractController::proceedWithoutContract()` reads `$campaign->requires_per_campaign_contract` and refuses the contract-less advance (422 `assignment.per_campaign_contract_required`) when it is `true`, so a required-contract campaign genuinely cannot reach `contracted`/`producing` without an accepted contract. `false` campaigns may advance via the proceed-without-contract endpoint OR attach + accept (D-10: "not required" ≠ "not allowed"). The full agency UI polish (a dedicated requires-toggle redesign) remains P2; this chunk wired the **gating**, not a redesign.

---

## Embedded PDF contract viewer (nice-to-have)

- **Where:** creator assignment detail — `ContractResource.view_url` is a presigned download link (D-5).
- **What we accepted in contract-bridge chunk (2026-06-05):** no in-app PDF viewer; open/download via signed URL.
- **Triggered by:** UX request for inline preview without leaving the app.
- **Owner:** future creators FE workstream.
- **Status:** open.

---

## Sprint 10 (Payments & escrow) deferred — build resequenced to after Sprint 11

- **What:** Sprint 10 (Payments & escrow) is deferred. Build order resequenced — Sprint 11
  (Messaging) is built next; Sprint 10 slots back in after it.
- **Why:** S10 is the platform's money sprint and the highest-risk surface (operation
  idempotency, the honest payment mock, escrow correctness). It is mock-buildable today
  (flag-OFF, `MockPaymentProvider`) — **no Stripe account is needed to BUILD it** — but building
  it now leaves the money path validated only against the mock for an extended period before real
  Stripe test-mode keys exist. We prefer to build payments close to when we can exercise the full
  round-trip against real Stripe test mode, so latent vendor-contract bugs surface at build time
  rather than months later. Sprint 11 has no vendor dependency it doesn't already have
  (assignments ✓, pre-signed S3 ✓, email = Postmark), so it is fully buildable and validatable now.
- **Not a blocker, a sequencing choice.** The earlier decision was "build S10 now against the
  mock; the Stripe account is a go-live concern, not a build blocker." That remains true — this
  deferral is build-when-validatable, not an admission the mock build was blocked.
- **Stripe is now the gating item to RESUME S10.** Finish the Stripe Connect platform application;
  land test-mode keys + the webhook signing secret in AWS Secrets Manager; configure
  `/api/v1/webhooks/stripe` in the Stripe dashboard (see `services.md`). Start now so the approval
  clock overlaps Sprint 11.
- **How far S10 can slip:** must land **before Sprint 13** (Admin Panel Core — its
  payment-investigation / refund / dispute-resolution surface, `09-ADMIN-PANEL §6.6`, has nothing
  to operate on without S10) and **before go-live**. Sprint 12 (Boards) is unaffected — the
  `assignment.payment_released → Paid` board verb/column can be catalogued without S10; the column
  simply won't auto-populate until payments ship.
- **Already prepared, reusable on resume:** the S10 pre-kickoff inventory is written and run; the
  chunk-count call (2 chunks, fund-IN / release-OUT, contract-migration as Chunk A's foundation
  slice) and the write-real-mock-bound provider call are made; the Chunk A kickoff is drafted
  (pending the D-8 auto-fund-grain + D-9 off-state confirms). No re-inventory needed on resume
  unless the codebase moves materially under it (e.g., Sprint 11 touches the assignment surface).
- **Related deferral:** the `isPaymentEligible()` release-gate consumption note (verification-
  resolution chunk) is pushed out with S10 — its consumer no longer lands imminently.
- **Triggered by:** Stripe account + test-mode keys available, OR reaching Sprint 13 / go-live prep
  (whichever first).
- **Owner:** the Sprint 10 payments workstream (when resumed).
- **Status:** open (deferred).

---

## Digest + agency-invite emails are English-only (deliberate)

- **Where:**
  - `UnreadMessagesDigestMail` dispatched from `SendMessageDigests.php:33`
  - `InviteAgencyUserMail` dispatched from `AgencyInvitationService.php:75`
- **What we accepted (2026-06-27, Sprint 11 mail-locale audit):** both mailables send without
  `->locale(...)` and render in `en` for all recipients regardless of `User::preferred_language`.
  This is a deliberate product decision, not an oversight.
- **Why the digest is harder to fix than a normal mailable:** its `$lines` strings are built inside
  `MessageDigestService` (`::204`, `::212`, `::220`) using `__()` while the Artisan command runs in
  console context (also `en`). Those strings are baked into the queued payload before the mailable
  is ever dispatched, so chaining `->locale(...)` at the send site alone is insufficient — a future
  fix must localize at line-build time, iterating per-recipient with the correct locale set before
  each `__()` call.
- **`InviteAgencyUserMail` note:** `InvitationMailTest` proves the template renders correctly in
  `en`/`pt`/`it` via manual `App::setLocale()`; nothing at the dispatch site selects per-invitee
  locale. No false docblock claim (nothing to correct in the class itself).
- **Triggered by:** product need for per-recipient localized digest or invite emails.
- **Owner:** future Messaging / Identity polish workstream.
- **Status:** open (by design).

---

## Hidden onboarding steps (kyc/tax/payout) — Sprint-10-gated re-introduction + tax backfill obligation

- **Where:** `WizardStep::WIZARD_HIDDEN_STEPS` (`apps/api/.../Creators/Enums/WizardStep.php`),
  mirrored by `WIZARD_HIDDEN_STEPS` in `packages/api-client/src/wizard.ts` (lockstep parity test
  `wizard.spec.ts`).
- **What we accepted (2026-06-27, AH-003):** the Identity-verification (`kyc`), Tax-information
  (`tax`), and Payout-method (`payout`) steps are **build-time HIDDEN** — excluded from the wizard
  rail, the 01…0N numbering, the completeness denominator, and the submit gate. In particular the
  submit gate **no longer requires `tax_profile_complete`** while `tax` is hidden (Q1 — the
  alternative was a literal deadlock: an always-required step that the creator can never reach).
  This is a reversible hide via a static registry, NOT a Pennant flag (the flag semantics —
  runtime/per-tenant — are wrong for a "not built yet" gate).
- **Re-introduction trigger:** Sprint 10 (Payments/Escrow) + automated KYC land (same trigger for
  all three). Re-introduction = remove the step id(s) from `WIZARD_HIDDEN_STEPS`; for kyc/payout
  also flip their Pennant flags ON. The visible-step list then re-derives numbering/progress/
  geometry automatically (AH-003 made the wizard derive these from the list, so no magic-number
  edits are needed).
- **Tax backfill obligation (must NOT be a surprise at Sprint 10):** creators who onboard during
  the hidden window submit with **no tax profile**. Tax data is **legally required before a first
  payout**, so Sprint 10 cannot simply re-show the step for new creators — it must **collect tax
  from the already-onboarded backlog before anyone is paid**. Plan a backfill/blocking path: gate
  the first payout on tax completeness and prompt the existing cohort to fill it, rather than
  assuming the wizard step alone covers everyone.
- **Triggered by:** Sprint 10 + automated KYC.
- **Owner:** Sprint 10 payments workstream + onboarding.
- **Status:** open (deferred, by design).

---

## Portfolio upload — resume / presign-expiry / storage cost (AH-004 plan carry-overs)

- **Where:** the creator portfolio upload path (single presigned S3 `PUT`; `PortfolioUploadService`
  / the wizard portfolio sub-section). Logged during the AH-004 plan-pause (Q3).
- **What we accepted:** AH-004 keeps the **single presigned `PUT`** (video already proves it at
  500 MB, so the planned uniform 500 MB ceiling for all file types needs no multipart rewrite) and
  adds **upload progress + a client timeout**. The following are explicitly deferred as
  quality-of-life / cost items, not built in AH-004:
  - **Resumable / multipart uploads** — on a flaky connection a large (hundreds-of-MB) `PUT` that
    drops restarts from zero. Multipart/resumable presigning would let it resume. Deferred until a
    real failure-rate signal justifies it.
  - **15-minute presign expiry** — a slow uploader on a bad connection can outrun the URL's TTL and
    get a hard failure late in a large upload. Consider a longer TTL for the portfolio path or a
    re-presign-on-expiry handshake.
  - **S3 storage cost at scale** — up to 30 files × 500 MB ≈ ~15 GB/creator of durable object
    storage. At creator scale this is a real recurring cost; name it in capacity/cost planning
    (lifecycle rules, storage class, or a per-creator soft quota are all options, none built here).
- **Triggered by:** measured upload failure rates (resume/expiry) or storage-cost review (S3).
- **Owner:** AH-004 portfolio workstream / infra-cost review.
- **Status:** open (deferred).
- **Build-time addendum (2026-06-27, AH-004 landed) — CORRECTED:** the **legacy** direct-multipart
  `POST /creators/me/portfolio/images` endpoint (`PortfolioController::uploadImage`) is still
  mounted unconditionally (prod-reachable, behind the `creators/me` auth), but it is **NOT** an
  EXIF/GPS-stripping bypass — an earlier note here claimed it was, which was wrong. It delegates to
  `PortfolioUploadService::uploadImage()` → `AvatarUploadService::upload()`, which decodes the
  upload and **re-encodes it through Intervention (`strip: true` + render-from-raster)**, so
  EXIF/GPS is stripped on this path exactly as on the AH-004 worker path. The EXIF guarantee holds
  on **both** routes. The real residual difference (a fidelity gap, not a security one): the legacy
  path runs the **avatar** re-encoder, so it (a) **downscales to 1024px** longest edge rather than
  retaining full resolution, (b) caps at 10 MB, and (c) does not generate a portfolio thumbnail or
  set `processing_status` (it defaults to `ready`). New SPA uploads no longer call it (they use the
  presigned `images/init` → `PUT` → `images/complete` worker flow); the endpoint remains for the
  Playwright `seedPortfolioImage` helper + any pre-AH-004 caller, and the `usePortfolioUpload`
  docblock still describing image uploads as "direct-multipart POST to /portfolio/images" is now
  stale. Retiring the endpoint (or routing it through the full-res worker for parity) is deferred;
  because EXIF is stripped either way, deferral is safe.

## Relationship-message attachment orphans — uploaded-then-abandoned (AH-012 / S3-hygiene family)

- **Where:** the relationship-messaging attachment path
  (`apps/api/app/Modules/Messaging/...` — `attachmentInit` presign → `PUT` → send).
  Surfaced during the AH-012 plan-pause (Q1) and confirmed at build.
- **What we accepted:** AH-012 made thread provisioning **lazy on intent** — opening a
  conversation alone never persists a `relationship_threads` row (D1), but **either** the
  first sent message **or** an attachment upload provisions the thread (both are intent).
  The attachment-upload leg provisions because the presigned S3 key is **scoped under the
  thread ULID** (the per-thread isolation guard), so the thread must exist before the
  object can be keyed. We deliberately did **not** re-architect that key scheme.
- **The residual orphan:** a user who **uploads a file then abandons** (never sends) leaves
  an **empty thread row + an orphaned S3 object**. D2 (the inbox shows only threads with
  ≥1 message) **hides** this from both inboxes — but the row and the object are still real;
  D2 is a display filter, **not** a cleanup. So this is genuine residue, not resolved.
- **Fix (deferred):** an orphan sweep / delete-on-abandon in the same family as the AH-004
  S3 storage-hygiene carry-overs — e.g. a scheduled sweep that deletes message-less
  relationship threads older than a TTL **and** their prefixed S3 objects, or a
  delete-on-navigate-away handshake. None built here.
- **Triggered by:** an S3 storage-cost / hygiene review, or measured orphan volume.
- **Owner:** the messaging workstream / infra-cost review (AH-004 S3-hygiene family).
- **Status:** open (deferred).

## AH-001 i18n completeness — English fragments inside translated values (parity-invisible)

- **Where:** the AH-001 machine-translation locale baseline across `apps/main` / `apps/admin`
  (`src/core/i18n/locales/**`). Surfaced during the AH-004 i18n pass.
- **What we found:** at least ~10 locales (`bg`, `et`, `fi`, `el`, `hu`, `ga`, `lv`, `lt`, `mt`,
  `ro`) carry **English fragments inside otherwise-translated values** — e.g. the creator portfolio
  `description` string still contains English wording like "up to … that represent your style"
  under a foreign-language key. AH-004 only touched the `10 → 30` number in those strings (in
  scope), so this predates and is independent of AH-004.
- **AH-005 addendum (2026-06-28):** the AH-005 contact-detail labels were regenerated across all 24
  locales (parity green), but the `mt` (Maltese) and `ga` (Irish) translations of the new keys were
  flagged for a native-speaker pass. Both locales are already on the list above; this reaffirms them
  as the highest-priority candidates for the content cleanup.
- **Why the gates don't catch it:** the i18n CI gate is **keyset/placeholder/plural parity** — it
  proves a key _exists_ in every locale with matching interpolation/plural shape, but it can
  **never** prove a value isn't still English. "English text under a foreign label" is structurally
  invisible to parity. The AH-001 review already flagged the MT baseline as
  structurally-validated, **not** meaning-verified (per-market human review is a go-live gate, not
  a merge gate) — this is concrete evidence of that gap, not a regression.
- **Fix:** a content cleanup pass (human / higher-quality MT) over the affected locales' values,
  ideally with a heuristic lint (e.g. flag values that are byte-identical to `en`, or that match an
  English-token dictionary) to surface untranslated strings that parity cannot.
- **Triggered by:** per-market localization QA / go-live review.
- **Owner:** i18n / localization workstream.
- **Status:** open (deferred; not a merge blocker).

---

## AH-005 contact details — no dedicated post-onboarding "profile settings" surface (wizard-as-settings)

- **Where:** the creator self-edit path — the onboarding wizard's profile-basics step
  ([`apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`](../apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue))
  - its backend write (`UpdateProfileRequest` → `CreatorWizardService::updateProfile`).
- **What we accepted (AH-005, 2026-06-28):** the new optional contact fields (phone, WhatsApp,
  street, postal code) are edited in the SAME place every other profile field is — the wizard step.
  There is **no separate post-onboarding "Profile settings" page**: an approved creator who wants to
  change their phone re-enters the wizard surface. This matches how `display_name` / `bio` /
  `categories` are already edited today (the wizard step doubles as the settings page), so AH-005
  adds no new debt category — it inherits the existing posture.
- **Why it's fine for now:** the wizard step is reachable post-approval and the write path is
  idempotent; contact details are low-churn. A dedicated settings IA is a broader UX project than a
  four-field add.
- **Trigger:** a product decision to give creators a first-class "Account / Profile settings" page
  distinct from the onboarding flow.
- **Owner:** future creator-account UX.
- **Status:** open (by design; not a blocker).

---

## AH-005 contact details — mailing address reuses `region` as the city/locality line

- **Where:** the creators table contact columns ([migration `2026_06_28_120000`](../apps/api/database/migrations))
  - the address composition on the agency roster-detail surface
    ([`CreatorDetailPage.vue`](../apps/main/src/modules/roster/pages/CreatorDetailPage.vue)).
- **What we accepted (AH-005, 2026-06-28):** the mailing address is composed from the EXISTING
  `country_code` + `region` columns plus two NEW lines (`address_street`, `address_postal_code`).
  No dedicated `address_city` column was added — `region` (labelled "Region or city" in the wizard)
  doubles as the locality line. This deliberately avoids duplicating a city field that overlaps the
  profile-level `region`, at the cost of a slightly loose semantic (a creator whose `region` is a
  province rather than a city yields a less precise mailing line). It also intentionally does NOT
  mirror the richer, encrypted `creator_tax_profiles.address` value-object
  (`country_code`/`city`/`postal_code`/`street`) — the contact address is a lightweight, plaintext,
  agency-visible convenience, not a billing/legal address.
- **Trigger:** a need for a precise, structured mailing address (e.g. shipping product to creators)
  that can't tolerate `region`-as-city.
- **Resolution:** add a dedicated `address_city` column (+ wizard field + resource key) and stop
  overloading `region`; optionally converge on the tax-profile address value-object shape.
- **Owner:** future creator-profile / logistics workstream.
- **Status:** open (by design; not a blocker).

---

## AH-006 — agency-side social-metrics/empty-state copy presumes future social integration

- **Where:** `apps/main/src/core/i18n/locales/en/app.json`
  - `app.roster.detail.social.empty` = "No social accounts connected."
  - `app.roster.detail.metrics.empty.heading` = "Social metrics will appear once accounts are connected"
  - `app.roster.detail.metrics.empty.body` = "…they'll show here when the social integrations land."
- **What we accepted (AH-006, 2026-06-28):** these strings were identified during the
  Connect→Add copy sweep but left untouched because they live on the **agency-side roster-detail
  view** (out of scope) and because the `metrics.empty` copy explicitly anticipates a future
  social-metrics integration — "connected" is correct future-state language there.
- **Trigger:** social verification / metrics integration shipping (Sprint 5 or equivalent).
  When OAuth-linked social accounts land, audit these three strings and update to reflect
  whether "connected" = "OAuth-linked" (keep) or "manually added" (change to "added").
- **Owner:** future Social verification workstream.
- **Status:** open; intentionally deferred.
