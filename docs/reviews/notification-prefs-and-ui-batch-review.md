# AH-101 — notification preference matrix, completion notice, third UI batch — review

**Status:** Half A built and gated; Half B in progress (this file gains a Half B section when it lands).
**Push is held; Pedram's device walk is acceptance.** Live push state is `origin/main`.
Inventory: [`notification-prefs-and-ui-batch-inventory.md`](notification-prefs-and-ui-batch-inventory.md) ·
Plan-pause: [`notification-prefs-and-ui-batch-plan.md`](notification-prefs-and-ui-batch-plan.md).

## 0. Two lines for the standing board

> ⚠ **STANDING-BOARD ITEM, independent of this chunk:** if production's `SOCIAL_PROVIDER` is **unset**, `social_verification_enabled` defaults **ON over the `mock` driver**, so posted content may be getting **mock-"verified" in production today**.

> ⚠ **BEHAVIOUR CHANGE ON DEPLOY:** the new guard **honours every stored `email=false` row immediately**, including rows that arrived any way other than this page. Before deploy, read the blast radius as a number (§6).

## 1. What shipped in Half A

### A1 — one email guard (`NotificationService::isEmailEnabled`)

`isEmailEnabled(User $recipient, NotificationType $type): bool` keys on the **recipient user** and the
**type**; absence of a row means ON; a stored `email=false` row stops that mail (Q1: honoured, never
cleared). It is called **after** each site's Pennant flag, so it never replaces a kill switch. Mail with no
`NotificationType` is structurally exempt.

| Site (file)                                                        | Types guarded                                                                                                                                                           |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SendAssignmentNotifications` (private `queueMail`)                | invited, completed_on_approval, manually_verified, live_verified, draft_approved / revision_requested / draft_rejected, draft_submitted (inviter), contracted (inviter) |
| `DebouncedMessageMailer` (after flag, **before** the debounce row) | `message.received_by_creator`, `message.relationship_received_by_creator` (type derived from the mailable's `context`)                                                  |
| `CampaignApplicationNotifier::queue`                               | `campaign_application.submitted` / `accepted` / `rejected`                                                                                                              |
| `JobPostedFanOutService::queueMail`                                | `campaign.job_posted` (the once-only stamp still happens regardless)                                                                                                    |

**Not guarded, by name** (the allowlist in `EmailPreferenceGuardArchitectureTest`, each with a reason): the
message digest command, sign-up verification, password reset, agency invitations, admin member invites,
bulk invite, the incomplete-creator nudge, the blacklist and connection-request mails, `creator.approved` /
`creator.rejected` (always on, Q2), the admin relation connect/disconnect mails, the verification-failed
mail, and the contract/resolution controller mails.

### A2 — `assignment.live_verified`

The success branch of verification was the only silent one. Now: creator-only (Q5); **in-app ungated**; the
**mail is behind `post_verified_mail_enabled`, default OFF** (new flag in `Creators/Features`, registered in
`CreatorsServiceProvider`, the admin flag list and `docs/feature-flags.md`). New `PostVerifiedMail` + markdown
view; `campaigns.assignment_notifications.post_verified.email.*` in **24 locales** (real translations; the
render test asserts each non-English subject and body differs from English and keeps its interpolations and
deep link). The copy claims only that the post was found at the submitted link: no payment, no approval.
**The flag does not arm until the read-back in §6 confirms a real verification driver.**

### A3 — the matrix

`LIVE_TYPES` stays the single registry. `channels` per type now lists `email` **only** where a guarded send
site exists (12 creator types plus the agency `campaign_application.submitted`). Columns are derived by
`preferenceColumns()` from the union of channels, sorted by one constant, `PREFERENCE_CHANNEL_ORDER`
(`in_app`, `email`, `digest`) — **adding `push` for AH-102 is one entry there, the enum case and a label**.
`creator.approved` / `creator.rejected` keep their in-app toggle and show "Always on" in the email column
(`alwaysOnChannels`). The page is a grid on web at md+ and **stacked cards per type** in the native app
and at narrow web widths (Q6). **Save semantics are unchanged:** same `enabled` map, same full-visible-set
payload, same single Save. Existing `data-test` ids are kept. New strings ×24: `channels.email`, `alwaysOn`,
the reworded `description`, `typeLabels.assignment_live_verified`, `types.assignment_live_verified`.

## 2. Decisions recorded (Q1–Q10, Half A)

Q1 honour crafted rows, never clear · Q2 `creator.*` mails always on, caption only on the email cell, in-app
toggle kept · Q3 no creator-matrix cell for agency-inviter mails; guard stays generic per recipient; an
agency-side preferences surface is a named follow-up · Q4 A2 ships mail dark · Q5 completion notice to the
creator only · Q6 stacked cards on native, no new save model.

## 3. Evidence

- **Both polarities at every site** (`EmailPreferenceGuardTest`, 15 sites): no rows → every site sends; **one**
  `email=false` row → exactly that site stops and the other 14 still send. Plus: the in-app row survives an
  email opt-out; an explicit `email=true` row sends; a suppressed chat mail never writes the debounce row.
- **Break-reverts, restored and re-run green:** (1) `isEmailEnabled` forced to `false` → **17 failed** (the
  no-rows test and every dataset row); (2) the guard call removed from `JobPostedFanOutService` → **1 failed**,
  `campaign.job_posted ignored the recipient's email=false row`, the other 14 sites green; (3) an extra
  `email` channel added to `assignment.draft_submitted` in the FE registry → **5 failed** across the registry,
  parity and page specs; (4) a new unguarded `Mail::to(...)` file under `app/` → the architecture test fails
  naming the file.
- **Inertness proof:** the existing Campaigns / Messaging / Notifications / Admin / Creators / Unit suites run
  **unedited**. The only reds are 11 in `AdminImpersonationCoreTest` and `EnforceImpersonationTest` (HTTP 500
  on the impersonation claim route), and **they fail identically on a clean checkout** (stashed this chunk's
  changes, same 11) — pre-existing, not touched here, named rather than fixed.
- **A2:** flag off → row written, no mail; flag on → mail queued with the creator's locale; creator-only; email
  opt-out stops the mail and keeps the row; in-app opt-out stops the row and keeps the mail; a _different_
  type's opt-out does not stop it; all 24 locales render.
- **Gates:** Pint clean, PHPStan clean (tests included), `vue-tsc` clean, ESLint clean on the touched trees,
  the full `apps/main` vitest run had exactly three reds, all in the notifications parity spec, which pins the
  live set and the role split (it failed first on `live_verified`, exactly as designed); updated and re-run
  green. (The registry spec, `templates.spec.ts`, reads the backend enum from source and would have caught an
  unregistered type on its own.)
- **Playwright:** `playwright/specs/notification-preferences-matrix.spec.ts` is written (grid, derived columns,
  always-on caption, default ON, an email opt-out saved, reloaded through the real API and still off, then
  restored). **It has NOT been run.** The config deliberately refuses to reuse a server already on `:8000` /
  `:5173` (to protect the dev database), and Pedram's dev servers were up; I did not stop them. Run it
  with the dev servers down: `pnpm --filter ./apps/main test:e2e -- notification-preferences-matrix`.

## 4. Deviations and caveats

- The completion-notice **in-app copy equals `manually_verified`'s** ("Your post for {campaign_name} was
  verified."). Both are true statements; the preferences labels are distinct ("A post is verified" vs "A post
  passes automatic verification").
- **Pre-existing locale debt, not touched:** several `notifications.json` locales still hold English (el, et,
  fi, ga, hu, lt, lv, mt, ro) or Czech (hr, sk, sl) placeholders for older keys. The new keys are real
  translations in all 24, so they do not add to it.
- A guard is file-level in the architecture test and per-site in the behavioural test; a second mail added
  inside an already-guarded file would be caught only if it has a row in `guardSites()`.

## 5. Out of scope (stands) and named follow-ups

Mail-inside-transaction hazard · a real social adapter · AH-102's push worker · any change to who receives a
mail · **agency-side preferences surface** (the inviter mails are guarded but have no UI) · **agency success
fan-out** for the completion notice · **a locale row in Settings** (Q10, Half B).

## 6. Production read-back (Pedram) — **before** deploy and **before** arming the flag

Run read-only on production. Table is `user_notification_preferences` (Postgres).

```sql
-- 1) The blast radius of "honour every stored row": how many rows exist at all, by channel and state.
SELECT channel, is_enabled, COUNT(*) FROM user_notification_preferences GROUP BY channel, is_enabled ORDER BY 1, 2;

-- 2) The number that matters: email opt-outs that will start suppressing mail on deploy, by type.
SELECT type, COUNT(*) AS users_opted_out FROM user_notification_preferences
WHERE channel = 'email' AND is_enabled = false GROUP BY type ORDER BY 2 DESC;
```

If query 2 returns rows, those are people who will stop receiving that mail on deploy — which is the intended
meaning of the row, but the count should be a known number, not a surprise. For the flag:

```bash
php artisan tinker --execute="echo config('integrations.social.driver').PHP_EOL; var_dump(Laravel\Pennant\Feature::active('social_verification_enabled'));"
```

- Driver **`mock`** (or unset → `mock`) with the flag **true** → the standing-board item in §0 is live: do not
  arm `post_verified_mail_enabled`, and treat existing "verified" posts as unverified.
- Arm `post_verified_mail_enabled` only once the driver is a real adapter.

## 7. Deploy obligations (Half A)

Backend deploy (code + 24 `lang/*/campaigns.php`); **no migration, no backfill, no one-shot command**.
Frontend: an `apps/main` SPA rebuild. Queue worker restart (new mailable class). One new flag, default OFF,
nothing to arm. See [`deploy-log.md`](../runbooks/deploy-log.md).

## 8. Half B

_Added when Half B lands._
