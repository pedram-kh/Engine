# Feature flags registry — Phase 1

This document is the **single source of truth** for every Laravel Pennant feature flag in Phase 1. The registry exists so that:

- When a sprint builds a vendor-dependent feature, the code is complete against the contract — but the user-facing entry point is gated by a flag that defaults to OFF.
- When a flag is OFF, the UX gracefully degrades. The "off-state behavior" column below describes the fallback the UI must implement.
- Operators can enable a flag without a code deploy, once the vendor manual steps in [`SPRINT-0-MANUAL-STEPS.md`](SPRINT-0-MANUAL-STEPS.md) are complete.

## How to use this registry

### When you build a feature that depends on a vendor

1. Find (or add) the row below for the flag that gates it.
2. Implement the integration code complete against the contract in [`06-INTEGRATIONS.md`](06-INTEGRATIONS.md). Provide a mock implementation for tests.
3. Wrap the user-facing entry points (routes, buttons, wizard steps, navigation links) in a Pennant `Feature::active(...)` check.
4. Implement the off-state behavior described in this registry. Examples:
   - Wizard step skipped with placeholder messaging.
   - Button hidden, with a tooltip on whatever takes its place: "Available once we enable …".
   - Alternative flow engaged (e.g., "paid offline" with reference number when payment processing is off; click-through acceptance when e-sign is off; admin-can-approve-without-KYC when KYC is off).
5. Update the **Off-state behavior** and **Manual steps to enable** cells when you ship — they start as placeholders.

### When you turn a flag ON

Flags default to OFF. The operator turns them on per scope (per-tenant, per-user, or globally) by:

1. Completing the manual steps in the registry row.
2. Verifying secrets in AWS Secrets Manager match what the code expects.
3. Running the artisan command (Sprint 1+ adds `php artisan pennant:set <flag> <scope>`).

Pennant install + the actual flag definitions live in `apps/api/app/Modules/<Module>/Features/`. The package is added in **Sprint 1** (Identity), once we have authenticated users to scope flags against.

## Sprint 0 status

This document is the registry. **No flags are defined in code yet** — Pennant is added in Sprint 1. This file will be updated by every subsequent sprint that ships a vendor-dependent feature.

## Phase 1 flags

| Flag                            | Gates                                                                                                                                                                                                                                                                                  | Default                                                        | Off-state behavior                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | Manual steps to enable                                                                                                                                                                                                                                                                                 |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `social_oauth_meta_enabled`     | Instagram OAuth connect button + `/oauth/meta/*` endpoints                                                                                                                                                                                                                             | off                                                            | _To be filled when Sprint 5 ships._ Expected: "Connect Instagram" button hidden; show placeholder "Coming soon" caption.                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Batch 1 §1.2 + secret `catalyst/${env}/api/oauth/meta` populated + Meta App Review approved.                                                                                                                                                                                                           |
| `social_oauth_tiktok_enabled`   | TikTok OAuth connect button + `/oauth/tiktok/*` endpoints                                                                                                                                                                                                                              | off                                                            | _To be filled when Sprint 5 ships._ Expected: "Connect TikTok" button hidden; placeholder caption.                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | Batch 1 §1.3 + secret `catalyst/${env}/api/oauth/tiktok` populated + TikTok app approved.                                                                                                                                                                                                              |
| `social_oauth_youtube_enabled`  | YouTube OAuth connect button + `/oauth/google/*` endpoints                                                                                                                                                                                                                             | off                                                            | _To be filled when Sprint 5 ships._ Expected: "Connect YouTube" button hidden; placeholder caption.                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Batch 1 §1.4 + secret `catalyst/${env}/api/oauth/google` populated + Google OAuth consent screen verified.                                                                                                                                                                                             |
| `kyc_verification_enabled`      | KYC step in creator onboarding wizard + `/integrations/kyc/*`                                                                                                                                                                                                                          | off                                                            | KYC wizard step short-circuits on initiate (`POST /wizard/kyc` returns 409 `creator.wizard.feature_disabled`) and `Skipped*Provider` is bound. On submit, `creators.kyc_status` is stamped `not_required` (Q-flag-off-1 = (a)) so the row tells the forensic story "operator-bypassed at submit time". Admin can manually approve creators without KYC.                                                                                                                                                                                                                  | Batch 2 §2.8 + secret `catalyst/${env}/api/kyc` populated + admin KYC review queue available in admin SPA.                                                                                                                                                                                             |
| `creator_payout_method_enabled` | Stripe Express onboarding for creators + payout method UI                                                                                                                                                                                                                              | off                                                            | Payout wizard step short-circuits on initiate (409 `creator.wizard.feature_disabled`) and `SkippedPaymentProvider` is bound. Submit-validation treats `creators.payout_method_set = false` as satisfied while the flag is OFF. Creator profile shows "payout setup pending."                                                                                                                                                                                                                                                                                             | Batch 1 §1.1 + Batch 3 §3.1 (Stripe Connect production approval) + secret `catalyst/${env}/api/stripe` populated with `connect_client_id`.                                                                                                                                                             |
| `contract_signing_enabled`      | E-sign envelope creation + `/integrations/esign/*` + the master-contract onboarding **wizard** (initiate / click-through fallback / completion poll / `EsignProvider` binding)                                                                                                         | off                                                            | E-sign wizard step short-circuits on initiate (409) and `SkippedEsignProvider` is bound. Click-through fallback at `POST /api/v1/creators/me/wizard/contract/click-through-accept` stamps `creators.click_through_accepted_at` (Q-flag-off-2 = (a)); submit-validation treats either `signed_master_contract_id` OR `click_through_accepted_at` non-null as satisfying the contract step. **This flag no longer gates the per-campaign assignment flow** — that moved to `per_campaign_contract_enabled` (contract-gate-decouple chunk, D-3).                            | See [`06-INTEGRATIONS.md` §4.6](06-INTEGRATIONS.md#46-e-sign-vendor-swap-checklist-when-real-e-sign-replaces-manual-click-through) for the vendor swap checklist. Batch 2 §2.9 + Batch 3 §3.3 (production e-sign keys) + secret `catalyst/${env}/api/esign` populated + production webhook configured. |
| `per_campaign_contract_enabled` | Per-campaign **manual** contract flow: agency attach (`…/contract/attach`) + creator click-accept (`…/contract/accept`) + the assignment `accepted → contracted` machine edge (`contract()`) + the agency "proceed without a contract" advance (`…/contract/proceed-without-contract`) | **on** ⚠                                                       | flag OFF → agency attach + creator accept + `contract()` + proceed-without-contract all return 422 `assignment.per_campaign_contract_disabled` (the break-revert). flag ON (default) → the manual two-party flow is live; a campaign with `requires_per_campaign_contract = true` mandates creator-accepts-a-contract (proceed-without-contract refuses 422 `assignment.per_campaign_contract_required`), `= false` campaigns may advance via proceed-without-contract. **Default-ON exception — see the Default-OFF convention note below: this flag gates NO vendor.** | **None — this flag gates no vendor** (the manual click-accept is an internal, legitimate e-signature, master agreement §10). It ships ON by default; an operator only flips it OFF to disable the per-campaign contract feature entirely.                                                              |
| `social_verification_enabled`   | Automatic social-post verification: the `VerifyPostedContentJob` + the assignment `posted → live_verified` machine edge (`verifyLive()`)                                                                                                                                               | **driver-based** ⚠ (ON under `mock`, OFF under a real adapter) | flag OFF → no job dispatched; the post stays `verification_status=pending`, the assignment stays `posted`, `verifyLive()` refuses + `SkippedSocialProvider` is bound (no vendor calls). flag ON + driver=mock → posts auto-verify against the mock (handle-in-URL → verified → `live_verified`; recognizable social URL without the handle → mismatch; otherwise not_found), and a failed verification surfaces the agency manual-resolution actions (manually verify / request resubmit).                                                                               | **None while driver=mock** (no vendor). For a real adapter: register its driver case in `CreatorsServiceProvider`, populate the vendor secret, then `Feature::activate('social_verification_enabled')`.                                                                                                |
| `payment_processing_enabled`    | Stripe Checkout + Connect transfers + `/integrations/stripe/webhook`                                                                                                                                                                                                                   | off                                                            | _To be filled when Sprint 10 ships._ Expected: brands mark campaigns "paid offline" and enter a manual reference number; transfers to creators are tracked manually.                                                                                                                                                                                                                                                                                                                                                                                                     | Batch 1 §1.1 + Batch 3 §3.1 + Batch 3 §3.2 (Stripe webhook endpoints) + secret `catalyst/${env}/api/stripe` complete with `webhook_secret`.                                                                                                                                                            |

| `incomplete_creator_nudge_enabled` | The scheduled incomplete-creator email nudge — the `creators:send-incomplete-nudges` daily command (a one-time email to **self-serve** creators sitting `application_status=incomplete` for 48h+, split into a verify-email variant for `email_verified_at IS NULL` and a finish-profile variant otherwise). Toggleable from the admin Feature-flags page. | off | flag OFF → the daily command is an explicit no-op (a "disabled" line, exit 0): no email queued, no `incomplete_nudge_sent_at` stamp written (the break-revert anchor). `--dry-run` ignores the flag so an operator can preview would-send counts before enabling. flag ON → one email per eligible creator, stamping `incomplete_nudge_sent_at` (once-only; a second run sends zero). **This flag gates NO vendor** — the nudge is internal transactional mail (GDPR Contract basis; see `docs/reviews/incomplete-creator-nudge-review.md`). | **None — gates no vendor.** Enable procedure (`docs/runbooks/production-queue-worker.md` §7): run `php artisan creators:send-incomplete-nudges --dry-run`, read the per-variant counts, then flip the flag ON from the admin Feature-flags page (a reason is recorded in the audit log). Requires the scheduler cron (`schedule:run`) + the queue worker running. |

| `job_posted_notifications_enabled` | The jobs-board **fan-out**: when an agency flips a campaign's `listed_on_jobs_board` from false → true, `SendJobPostedNotificationsJob` tells that agency's reachable roster (approved, rostered, not brand-hard-blacklisted) — in-app `campaign.job_posted` + a queued localized `JobPostedMail`, capped at 50 per run, oldest-roster-first, stamped once per `(campaign, creator)`. Toggleable from the admin Feature-flags page. | off | flag OFF → `JobPostedFanOutService::send()` is a complete no-op: nothing queued, nothing notified, nothing stamped, and the report still carries an honest `remaining` count (the break-revert anchor). The BOARD ITSELF IS NOT GATED — creators can browse and apply with the flag off; only the outbound push is. flag ON → each listing flip notifies up to 50 reachable roster members once; a re-list never re-notifies (the stamp), and `campaigns:preview-job-notifications {ulid}` drains any remainder. **This flag gates NO vendor** — the mail is internal transactional notification to rostered creators. | **None — gates no vendor.** Enable procedure: run `php artisan campaigns:preview-job-notifications {campaign-ulid} --dry-run`, read the would-notify / would-remain counts (this is the arc's first mail fan-out to the live ~279-creator base, so read them before flipping), then flip the flag ON from the admin Feature-flags page (a reason is recorded in the audit log). Requires the queue worker running; **no scheduler dependency** — the trigger is the listing flip, not a cron. |

| `application_notifications_enabled` | The jobs-board **applications** vocabulary's MAIL legs: `campaign_application.submitted` (→ the agency's admins + managers when a creator applies), `campaign_application.accepted` and `campaign_application.rejected` (→ the creator when the agency answers, the rejected copy varying on `data.cause` ∈ `agency_rejected` / `campaign_closed`). Toggleable from the admin Feature-flags page. | off | flag OFF → `CampaignApplicationNotifier` queues **no mail** at any of its four emission sites (apply, accept, reject, terminal auto-reject) — and the **in-app rows are still written** at all four. That asymmetry is the design, not a gap: the flag gates mail; in-app honours the recipient's own notification preference. The applications tab, accept and reject all work with the flag OFF; only the outbound push is gated (the break-revert anchor). flag ON → each of the four sites dual-emits (in-app + a queued localized mailable, one per recipient). **This flag gates NO vendor** — the mail is internal transactional notification to an agency's own members and to rostered creators. | **None — gates no vendor.** Enable procedure: flip it from the admin Feature-flags page (a reason is recorded in the audit log), **alongside `job_posted_notifications_enabled`** — the arc's first-enable ritual arms both jobs-board mail flags together, since a board that pushes listings but never acknowledges an application is the confusing half-state. Requires the queue worker running (and a worker **restart**, so the new mailable classes and lang keys are loaded); **no scheduler dependency** — every trigger is a user action or the terminal-flip job. ⚠ Volume note, recorded at AH-058: `submitted` is the one type whose volume is driven by creators rather than by an agency action (N applications to one listing = N mails to every admin + manager). Fine at the current base; a per-campaign digest is the named future move if a popular listing makes it an inbox pattern. |

### The jobs-board arc's combined first-enable ritual (AH-059, D7a)

The two jobs-board mail flags — `job_posted_notifications_enabled` and
`application_notifications_enabled` — are **armed together, as one procedure**, and this is the
procedure. Their rows above describe what each one gates; this section is the operator's script for
the day they are turned on.

**Why together.** A board that pushes listings to creators but never acknowledges their applications
is the confusing half-state: the creator is invited to apply by email and then hears nothing back
through the same channel. The two flags are also the same risk class — outbound mail to the live
base — so they get one read, one decision and one audit moment rather than two.

**When.** Explicitly **separable from the deploy** and later than it. The arc deploys with both flags
OFF, and at T+0 the population is provably zero: no campaign is listed, so no job-posted mail has a
recipient and no application exists to acknowledge. Arming is a decision Pedram makes when he wants
the board to start talking, not a step in shipping it.

**Preconditions.**

- The queue worker is **running and has been restarted since the deploy** (§4 of the runbook). The
  arc ships new mailable classes and new `lang/**` copy, and a long-running worker caches
  translations in memory — an un-restarted worker sends a missing-key body.
- **No scheduler dependency.** Every trigger here is a user action or the terminal-flip job; nothing
  in the jobs board waits on `schedule:run` (which is still unverified in production — see the
  standing blocker).

**The steps.**

1. **Dry-run the one flag that has a preview.** Pick a campaign that is (or is about to be) listed:

   ```bash
   php artisan campaigns:preview-job-notifications {campaign-ulid} --dry-run
   # → would notify N, would remain M
   ```

   Read both numbers. This is the arc's first outbound fan-out to the live creator base (~279 at the
   last count), and `N` is how many people the next listing flip mails. The command mutates nothing
   and ignores the flag.

2. **`application_notifications_enabled` has no preview, and that is a known asymmetry.** Its volume
   is driven by human action rather than by a roster query, so there is nothing to count in advance —
   except for one case worth holding in mind: `campaign_application.submitted` goes to **every admin
   and manager of the agency**, once per application, so a popular listing is N × (admins+managers)
   mails. A per-campaign digest is the named future move if that becomes an inbox pattern.

3. **Arm both, from the admin Feature-flags page**, in this order: `job_posted_notifications_enabled`
   first, then `application_notifications_enabled`. A reason is **mandatory** on each and is written
   to the audit log as `feature_flag.toggled`. The order matters only in the sense that listings
   precede applications in time; either order is safe.

4. **⚠ Read both flags BACK.** This step exists because of a real, expensive incident (AH-059 §2): an
   eyes-on session spent an hour attributing missing application mail to a broken mail path, when the
   flag had simply never been armed. Nothing in the product distinguishes "no mail because an
   operator chose that" from "no mail because something is broken" at a glance, so confirm the arm
   landed rather than assuming the click worked:

   ```bash
   php artisan tinker --execute="\
     dump(Laravel\Pennant\Feature::active('job_posted_notifications_enabled'), \
          Laravel\Pennant\Feature::active('application_notifications_enabled'));"
   ```

   Both must read `true`. The admin page's own toggle state is a second read of the same truth; the
   audit log is the third (`feature_flag.toggled` rows, one per flag, with the reasons).

5. **What to watch, in this order.**
   - **The log.** Both mail paths now announce their own decisions. The fan-out logs
     `{"enabled":true,"notified":N,"remaining":M}` per run; the applications notifier logs one line
     per emission decision naming the type, the recipient count and the flag state (AH-059 S2 —
     added precisely so the §2 incident cannot repeat silently).
   - **The queue.** `redis-cli -p 6380 llen queues:default` (or the runbook's §5 equivalent) should
     drain rather than grow. A listing flip enqueues at most 50 mailables plus the job itself.
   - **`failed_jobs`.** Any `SendQueuedMailable` carrying `JobPostedMail` or one of the three
     `Application*Mail` classes is a real signal. ⚠ On a **developer** host this table is currently
     polluted by stale E2E jobs (tech-debt, AH-059); in production it is clean and trustworthy.
   - **The first real recipient.** Confirm one delivered mail renders in the recipient's locale with
     the interpolated campaign and brand names present — the arc's mail is localized per recipient.

**To disarm.** Flip either flag OFF from the same page, with a reason. Both are complete no-ops when
OFF: nothing queued, nothing stamped by the fan-out, and — the deliberate asymmetry — **in-app
notifications keep writing** for the applications vocabulary regardless, honouring each recipient's
own preference. Disarming stops future mail; it does not recall what was sent, and the fan-out's
once-per-`(campaign, creator)` stamps are **not** cleared, so a re-arm does not re-notify anyone who
was already notified.

## Conventions

- **Naming.** Flags use snake*case, prefixed by the domain (`social_oauth*\_`, `kyc\_\_`, `payment\_\*`). The suffix is always `\_enabled`so the active state reads naturally:`if (Feature::active('payment_processing_enabled')) { ... }`.
- **Default OFF.** Every flag defaults to OFF for every scope. We never ship a vendor-dependent feature ON by default.
  - **⚠ Documented driver-based default — `social_verification_enabled`.** Its default is computed from `integrations.social.driver`: **ON when the driver is `mock`, OFF when a real adapter (meta/tiktok/youtube) is configured.** This honours the same "No silent vendor calls" rationale: the mock provider makes no outbound calls, so while it is bound the rule does not apply and default-ON is sound (the `posted → live_verified` arc and the failure→manual-resolution arc run out of the box in dev/demo). The instant a real adapter is wired, the default flips back to OFF so an un-provisioned real-driver instance never reaches the vendor — the operator must `Feature::activate(...)` once secrets are in place. The flag resolver itself enforces this; see `SocialVerificationEnabled::default()`.
  - **⚠ Documented exception — `per_campaign_contract_enabled` defaults ON.** This is a principled, deliberate exception, not an oversight. The rule's own rationale is **"No silent vendor calls"** (see the next bullet): we default vendor-dependent features OFF so an un-provisioned instance never reaches out to a vendor. `per_campaign_contract_enabled` **gates no vendor** — the per-campaign manual flow is entirely internal: the agency attaches terms (markdown/PDF on our own storage) and the creator click-accepts, which stamps `contracts.signed_signature_data` (method + IP + UA + timestamp). The master agreement §10 declares that click-accept a **binding signature**, so this is a legitimate e-signature, not a vendor placeholder. Because the rationale behind default-OFF does not apply, default-ON is sound — it lets the manual per-campaign flow ship to production while the e-sign **vendor** flag (`contract_signing_enabled`) stays OFF (no envelopes, no vendor calls). A future reader who finds a default-ON flag must be able to find this reasoning; this note is that record.
- **No silent vendor calls.** When a flag is OFF, the application **must not** make outbound calls to the vendor. The mock provider is what's wired up; the real client is conditionally bound only when the flag is ON.
- **Tests cover both paths.** Every gated feature ships with two test paths: flag-ON happy path against the mock provider, and flag-OFF graceful-degradation path verifying the fallback UX.
- **Pennant scopes.** When the user model exists (Sprint 1+), flags can be scoped per-user, per-tenant (agency), or globally. Default scope for these Phase 1 flags is **global** — operators flip them on for the whole instance once the vendor is ready.
- **Phase 1 flag invocation pattern.** Use `Feature::active('<flag>')` (no scope arg) — operators flip flags globally; per-user / per-tenant scoping is a Phase 2+ capability. To make this pattern resolve correctly under Pennant's default-scope-is-auth-user behaviour, `CreatorsServiceProvider::configurePennantScope()` overrides the default scope resolver to `null` for the whole app. Future modules adding Pennant flags must either follow the no-scope convention or explicitly pass a scope via `Feature::for($scope)->active('<flag>')`. (Sprint 3 Chunk 2 sub-step 8.)
- **Driver convention.** Each vendor-gated provider has a per-provider `*_PROVIDER` env var (e.g., `KYC_PROVIDER=mock|<real-vendor>`) read in `config/integrations.php`. Mixed-vendor staging environments (KYC live + e-sign mock + payment mock) are tractable; closes Chunk 1's tech-debt entry 3 — Q-driver-convention in the chunk-2 plan. (Sprint 3 Chunk 2 sub-step 4.)
- **⚠ Messaging (Sprint 11) ships flagless — confirmed no flag (D-18).** Messaging is entirely internal (creator ↔ their contracted agency over our own storage + presigned S3); it calls no vendor, so the "No silent vendor calls" rationale does not apply and there is no `messaging_*` flag. The notification subsystem it consumes is likewise unflagged. **Sprint 11 also establishes the app's first scheduled command** — `messages:send-digest`, registered `->daily()` via `withSchedule()` in [`bootstrap/app.php`](../apps/api/bootstrap/app.php). Scheduling is an operational concern (a cron entry / `schedule:run`), not a feature flag; it has no off-state UX and is not gated. Future scheduled commands follow the same `withSchedule` registration.

## Phase-2-and-beyond flags

Out-of-scope for Phase 1, listed here so we know not to invent these names later:

- `marketing_landing_enabled` (Phase 2 — public marketing site)
- `analytics_advanced_enabled` (Phase 2 — full analytics dashboards)
- `multi_tenant_isolation_enabled` (Phase 3 — full white-label isolation)
- `mobile_app_enabled` (Phase 3 — native apps)

These are placeholders only; no code references them yet.
