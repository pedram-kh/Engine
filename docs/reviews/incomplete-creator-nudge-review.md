# Incomplete-creator email nudge — Review (backend + i18n + docs)

**Status:** Draft — awaiting independent review + merge. Push held.

**Reviewer:** drafted by Cursor (implementation); awaiting independent review.

**Reviewed against:** the read-only I1–I7 inventory (the evidence base), the Kickoff locked decisions **D1–D8 + Q1–Q3**, the two plan-clearance answers (the **suspended-exclusion** extension of D2 + the **verify-URL replication** guard), the existing precedents cited below (`SendMessageDigests`/`MessageDigestService` scheduler+console shape, `VerifyEmailMail`/`SignUpService` verification-mail + URL-mint pattern, `CreatorApprovedMail` localized-mailable shape, the `AdminFeatureFlagController` registry + reason-audit flow, `KycVerificationEnabled` default-OFF flag shape, `LangParityTest` 24-locale en-SOT gate), and `PROJECT-WORKFLOW.md §5` (5.3 real-render mailable tests, 5.4 transactional audit, 5.32 intent-over-mechanism, 5.34 negative-case pinning, 5.35 break-revert).

The feature: a daily, flag-gated, **once-only** onboarding email to **self-serve** creators sitting `application_status = incomplete` for **48h+**, split into two variants — a verify-email variant (unverified) and a finish-profile variant (verified). Ships **flag-OFF**.

---

## What shipped (files)

**Feature (feat commit):**

- Migration [`2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php`](../../apps/api/database/migrations/2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php) — nullable `creators.incomplete_nudge_sent_at` (the `notification_sent_at` precedent). No index (D7).
- [`Creator`](../../apps/api/app/Modules/Creators/Models/Creator.php) — property doc + fillable + `'datetime'` cast for the new column.
- Flag [`IncompleteCreatorNudgeEnabled`](../../apps/api/app/Modules/Creators/Features/IncompleteCreatorNudgeEnabled.php) (`incomplete_creator_nudge_enabled`, default-OFF Closure — the `KycVerificationEnabled` shape) + registration in [`CreatorsServiceProvider::registerFeatureFlags()`](../../apps/api/app/Modules/Creators/CreatorsServiceProvider.php).
- Admin registry row in [`AdminFeatureFlagController::FLAGS`](../../apps/api/app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php) — label "Incomplete-creator nudge", English (the house non-i18n admin pattern). The flip inherits the reason-required audit flow for free.
- Variant enum [`IncompleteCreatorNudgeVariant`](../../apps/api/app/Modules/Creators/Enums/IncompleteCreatorNudgeVariant.php).
- Eligibility [`IncompleteCreatorNudgeEligibility`](../../apps/api/app/Modules/Creators/Services/IncompleteCreatorNudgeEligibility.php) — the D2/D3/Q1 predicate + the two-variant split.
- Mailable [`IncompleteCreatorNudgeMail`](../../apps/api/app/Modules/Creators/Mail/IncompleteCreatorNudgeMail.php) + Blade templates [`incomplete-nudge-verify`](../../apps/api/resources/views/mail/creators/incomplete-nudge-verify.blade.php) / [`incomplete-nudge-finish`](../../apps/api/resources/views/mail/creators/incomplete-nudge-finish.blade.php) — `tags: ['creators','onboarding-nudge']`.
- Report DTO [`IncompleteNudgeReport`](../../apps/api/app/Modules/Creators/Support/IncompleteNudgeReport.php).
- Service [`IncompleteCreatorNudgeService`](../../apps/api/app/Modules/Creators/Services/IncompleteCreatorNudgeService.php) — flag gate + send/stamp + `--dry-run` preview + URL builds.
- Command [`SendIncompleteCreatorNudges`](../../apps/api/app/Console/Commands/SendIncompleteCreatorNudges.php) (`creators:send-incomplete-nudges {--dry-run} {--limit=}`) + `->daily()` registration in [`bootstrap/app.php`](../../apps/api/bootstrap/app.php).
- i18n: `creators.incomplete_nudge.*` added to **all 24** `lang/*/creators.php`.
- Tests: `IncompleteCreatorNudgeEligibilityTest`, `IncompleteCreatorNudgeMailTest`, `IncompleteCreatorNudgeCommandTest`; extended `CreatorFeatureFlagsTest` + `AdminFeatureFlagTest`.

**Docs (docs commit):** `docs/feature-flags.md` (registry row), `docs/runbooks/production-queue-worker.md` (§7 scheduler + first-enable), `docs/tech-debt.md` (index-deferral entry), `docs/reviews/RESUMPTION-TEMPLATE.md` (Part 2 scheduler dependency + enable procedure), this review file.

---

## Per-decision evidence

- **D1 · Flag.** `IncompleteCreatorNudgeEnabled` is a default-OFF Closure verbatim per the `KycVerificationEnabled` shape (`static fn (mixed $scope = null): bool => false`), registered in `registerFeatureFlags()`. `CreatorFeatureFlagsTest` gains "registers incomplete_creator_nudge_enabled with default OFF" **and** the flag joins the default-OFF round-trip loop. `docs/feature-flags.md` gains the registry row. Admin: one `FLAGS` entry (English label/description). **NOT** added to `CreatorResource`'s `wizard.flags` — that exact-3-key pin is untouched. The flip inherits the reason-required `feature_flag.toggled` audit flow (pinned by the extended `AdminFeatureFlagTest` list assertion).
- **D2 · Eligibility.** `IncompleteCreatorNudgeEligibility::baseQuery()`: `application_status = 'incomplete'` **AND** `created_at <= now()-48h` **AND** `incomplete_nudge_sent_at IS NULL` **AND** user not-suspended **AND** user not-soft-deleted (via `whereHas('user')` applying the User SoftDeletes scope) **AND** self-serve origin (the `NOT EXISTS(agency_creator_relations WHERE creator_id = … AND invitation_sent_at IS NOT NULL)` — see Q1). Bulk-invited / connection-requested creators are OUT.
  - **Suspended-exclusion (plan-pause extension of D2, NOT a deviation — §5.32).** Intent was "nudge only people who can act on it"; a suspended user hits the login wall, so `is_suspended = false` joins the predicate and a **seventh** negative case (case 7) pins it. Recorded here as an intent-preserving extension.
- **D3 · Anchor = `creators.created_at`; lossiness accepted.** The 48h floor is measured off `creators.created_at`, not a `became_incomplete_at` column (explicitly **NOT** built — v2 territory). **Reopened (rejected→incomplete) rows become immediately eligible on reopen if never nudged** — accepted: they are genuinely old + incomplete, and the once-only `incomplete_nudge_sent_at` stamp caps any repeat at exactly one. This lossiness is a conscious posture, not a bug.
- **D4 · Two variants, one command, one stamp.** `email_verified_at IS NULL` → verify variant: fresh `EmailVerificationToken::mint()` + `{frontend_main_url}/auth/verify-email?token=` (the existing mint path, nothing new). `NOT NULL` → finish variant: `{frontend_main_url}/onboarding` (no step encoding — the guard + next_step resumption routes; no magic-login). Both stamp the same `incomplete_nudge_sent_at`. Strings live in `lang/*/creators.php ×24`, `->locale($preferred_language ?: 'en')` (the verification-mail pattern, not the digest's English-only shape). §5.3 real-render tests for both variants; `tags: ['creators','onboarding-nudge']`.
- **D5 · Transactional tone (GDPR Contract basis).** See "Lawful basis" below. Copy is service framing ("finish the registration you started"), zero promotional language, no upsell, **no unsubscribe link** (the transactional posture). The `onboarding-nudge` tag keeps it out of any future marketing stream.
- **D6 · Command + schedule.** `creators:send-incomplete-nudges` is a thin handler (the `SendMessageDigests` shape) delegating to the service, registered `->daily()` in `withSchedule()`. The flag is checked **inside the service** (`Feature::active(…)`, no scope — the null-scope pin makes console + admin agree). Flag OFF → explicit no-op with a "disabled" line, exit 0. `--dry-run` lists per-variant would-send counts, mutates nothing (and ignores the flag, so an operator can preview before enabling). Idempotent: a second run sends zero.
  - **Per-run cap (production-safety addendum — we are live).** `--limit=N` caps the run, defaulting to `IncompleteCreatorNudgeService::DEFAULT_LIMIT = 50`, **oldest-first** (`IncompleteCreatorNudgeEligibility::eligible()` orders by `created_at, id` and applies `LIMIT`). The cap is a **per-run total across both variants** (one ordered+limited query, partitioned in PHP), so a backlog drains deterministically over successive daily ticks instead of a single blast. `--limit=0` / non-numeric fails loudly (`FAILURE`, nothing sent). `--dry-run` honours the same cap so the preview matches the send. The scheduled command uses the default cap.
- **D7 · Index deferred.** No new index this chunk — daily batch, status-indexed narrowing, current scale. Logged in `docs/tech-debt.md` with a named volume trigger (the campaign-detail two-hop precedent).
- **D8 · Deploy notes.** `production-queue-worker.md` **§7** gains the scheduler section (cron + systemd-timer setup) and the documented first-enable procedure (`--dry-run` → read counts → flip flag). `RESUMPTION-TEMPLATE.md` Part 2 gains the scheduler as a standing infra dependency (distinct from the two pending one-shot commands) + the flag-enable procedure.

---

## Lawful basis (D5 / I7 compliance note)

The nudge is **transactional, not marketing**, so its GDPR lawful basis is **Contract** (Art. 6(1)(b)) — completing the registration the creator themselves initiated — not Consent (Art. 6(1)(a)). Rationale:

- It is sent **only** to people who **started** creating a Catalyst creator account (self-serve origin — Q1). The message helps them finish a process they began; it is service communication, not promotion.
- The copy carries **zero** promotional content, no upsell, no cross-sell — pinned informally by the transactional tone of the `lang/en/creators.php` strings.
- Because it is transactional, it carries **no unsubscribe link** (matching every other transactional mail in the app — verification, invites, approval). If a marketing stream ever exists, the `onboarding-nudge` envelope tag is the discriminator that keeps this mail out of it.
- The **once-only** stamp caps volume at exactly one message per creator — this is not a recurring campaign.

`05-SECURITY-COMPLIANCE.md`'s transactional-vs-marketing distinction is the constraint this framing satisfies.

---

## Production posture (we are live)

This ships into a live system; the design is deliberately low-blast-radius on every axis:

- **The migration is additive-nullable only.** `2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php` adds a single `nullable()` `timestamp` column with **no default backfill, no index, no NOT NULL, no data migration** — a metadata-only `ALTER TABLE ADD COLUMN` that is instant and reversible (`down()` drops the column). No existing row is rewritten; every existing creator starts with `incomplete_nudge_sent_at = NULL` (i.e. "never nudged").
- **The flag ships OFF.** `incomplete_creator_nudge_enabled` defaults OFF (default-OFF Closure, pinned by `CreatorFeatureFlagsTest`). Until an operator explicitly flips it, the daily command is a no-op — deploying this code sends **zero** email.
- **The command's only write is the `incomplete_nudge_sent_at` stamp.** `send()` queues mail and calls `Creator::updateQuietly(['incomplete_nudge_sent_at' => now()])` — nothing else is mutated (no status change, no user write, no audit row). `--dry-run` and the flag-OFF path write **nothing at all**. The per-run cap bounds even the stamp: only the capped, oldest-first set is stamped (the §5.34 no-over-stamping case).
- **Per-run cap.** Default 50, oldest-first — a large backlog cannot be blasted in one run even after the flag is on.

---

## Verify-token safety (evidenced)

**Claim:** minting a fresh `EmailVerificationToken` for a user who may already hold an older outstanding token behaves **exactly** like the existing resend flow — no invalidation side effects, no uniqueness/collision issue.

**Evidence:**

- **The nudge uses the identical mint path as resend.** The nudge's verify variant calls `$this->tokens->mint($user)` ([`IncompleteCreatorNudgeService::dispatchVerify()`](../../apps/api/app/Modules/Creators/Services/IncompleteCreatorNudgeService.php)). The resend endpoint (`POST /auth/resend-verification` → `ResendVerificationController` → [`EmailVerificationService::resend()`](../../apps/api/app/Modules/Identity/Services/EmailVerificationService.php) `:88`) calls `$this->signUp->sendVerificationMail($user, $request)`, which itself calls `$this->tokens->mint($user)` ([`SignUpService::sendVerificationMail()`](../../apps/api/app/Modules/Identity/Services/SignUpService.php) `:273`). Same method, same argument — the nudge is a third caller of the same mint, not a new token scheme.
- **Minting has no storage side effects.** [`EmailVerificationToken::mint()`](../../apps/api/app/Modules/Identity/Services/EmailVerificationToken.php) `:37` is a **pure HMAC computation** — it builds `{user_id, email_hash, expires_at}`, base64url-encodes it, and appends `hmac_sha256(payload, APP_KEY)`. It writes **nothing** (the class docblock, `:20-24`: _"intentionally self-contained so we don't need an `email_verifications` table… single-use guarantee is carried by `users.email_verified_at`"_). There is **no server-side token store**, so a new mint cannot invalidate or collide with an older token — both remain independently valid until their own `expires_at` (24h, `LIFETIME_HOURS`).
- **No uniqueness/collision issue.** The token is deterministic in its payload: two mints at different seconds carry different `expires_at` → different tokens; a same-second re-mint yields an **identical** token, which is idempotent (the same still-valid token), not a collision — there is no unique constraint anywhere to violate.
- **Single-use is state-carried, not token-carried, and the nudge respects it.** The nudge only mints the verify variant for users with `email_verified_at IS NULL` (the eligibility split). After verification, [`EmailVerificationService::verify()`](../../apps/api/app/Modules/Identity/Services/EmailVerificationService.php) short-circuits a re-click, and `resend()` `:84` returns early once `email_verified_at !== null` — the same guard that makes an older outstanding token harmless applies identically to a nudge-minted one.

**Conclusion:** a nudge-minted verify token is behaviourally indistinguishable from a resend-minted one. The verify-variant §5.3 render test additionally pins the emitted URL shape so the link itself cannot silently drift.

---

## Q1 — self-serve-origin mechanism (accepted + rejected alternatives)

**Accepted:** exclude any creator with an `agency_creator_relations` row bearing `invitation_sent_at IS NOT NULL` (the `NOT EXISTS` subquery). That row is the durable, unambiguous marker of an agency **invite** (`prospect`) or **connection request** (`pendingRequest`) path — whose correct next step could be _accept-invite_, not _verify-email_. This is a **conservative over-exclusion**: a creator who was invited but later also behaves self-serve is skipped. Accepted because the locked intent is "nobody receives a verify-email link whose correct next step is accept-invite," and false-negatives (a missed nudge) are strictly safer than false-positives (an invited user pushed onto the wrong path).

**Rejected alternatives (recorded so a future engineer does not "simplify" the predicate into something broken):**

- **`users.password` presence / hash inspection.** Every user row carries a hashed password regardless of origin — `BulkInviteService` and `SignUpService` both persist a hash (bulk-invited users get a random one at creation). The hash **cannot** distinguish self-serve from invited; a "has a password" predicate would be uniformly true and exclude nobody. Rejected.
- **`users.last_name` nullability as an origin signal.** `last_name` is nullable and was **added later (AH-023)**; pre-AH-023 rows have null `last_name` irrespective of origin, and the column was never an origin discriminator. Using its presence/absence would mis-classify older rows and is semantically wrong. Rejected.

The `agency_creator_relations.invitation_sent_at` marker is the only signal the data actually supports for "was this an agency-initiated relationship."

---

## Q2 — verify-variant strings + the verify-URL replication guard

- **Strings location:** the verify-variant strings live in `lang/*/creators.php` under `creators.incomplete_nudge.verify.*`, **not** `auth.php` — even though the token flow is `Identity`'s. Rationale: the mailable is a `Creators`-module mail (it is about the creator's onboarding), so its copy belongs with the module's other lifecycle mails (`approved`, `rejected`, `connection_request`). Consistency of ownership over token-flow proximity.
- **Verify-URL replication (confirmed with a guard).** `SignUpService::buildVerifyUrl()` is `private`, so the two-line build (`rtrim(frontend_main_url,'/') . '/auth/verify-email?' . http_build_query(['token'=>…])`) is **replicated** in `IncompleteCreatorNudgeService::buildVerifyUrl()`. The guard: the verify-variant §5.3 render test asserts the **full link shape** `{frontend_main_url}/auth/verify-email?token=` appears in the rendered body, so any future drift between the local build and `SignUpService`'s is a **red test, not a silent 404**.
- **Extraction trigger (named):** if a **third** verify-URL mint site ever appears, that is the trigger to extract a shared `buildVerifyUrl` helper (the rule of three). Until then, two call sites + the render-body assertion is the correct cost/benefit.

---

## Q3 — the §5.34 negative set (7 cases, all green)

| #   | Case                                                 | Where pinned                                                                             |
| --- | ---------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | flag-OFF → no-op (nothing queued, nothing stamped)   | `IncompleteCreatorNudgeCommandTest` "(1) flag OFF → … explicit no-op" + the break-revert |
| 2   | already-stamped skip (once-only)                     | `IncompleteCreatorNudgeEligibilityTest` "(2) skips a creator already stamped …"          |
| 3   | invited-never-accepted skip (self-serve only, Q1)    | "(3) skips an invited-never-accepted creator …"                                          |
| 4   | pending / approved / rejected skip (only incomplete) | "(4) skips pending / approved / rejected creators …"                                     |
| 5   | `<48h` skip                                          | "(5) skips a creator whose created_at is younger than 48h"                               |
| 6   | soft-deleted user skip                               | "(6) skips a creator whose user is soft-deleted"                                         |
| 7   | **suspended user skip** (the D2 extension)           | "(7) skips a creator whose user is suspended …"                                          |

Plus the **positive** two-variant split (unverified → verify-only; verified → finish-only) and a 49h boundary-eligible case. Every negative builds a creator eligible in every respect **except one**, then asserts absence from **both** buckets (the disjoint-and-complete pin).

**Added §5.34 case — a run at the cap stamps only the capped set (no over-stamping).** `IncompleteCreatorNudgeCommandTest` "--limit caps the run oldest-first and stamps ONLY the capped set" builds three eligible creators (created 5/4/3 days ago, mixed variants), runs `--limit=2`, and asserts: exactly 2 mails queued, the two **oldest** stamped, the **newest untouched** (`incomplete_nudge_sent_at` still null) — so it is picked up by the next run, never double-sent and never over-stamped past the cap. Companion cases: `--dry-run --limit` previews the capped set + mutates nothing; a non-positive/non-numeric `--limit` fails loudly and sends nothing. Ordering itself is pinned at the query level by `IncompleteCreatorNudgeEligibilityTest` "eligible(N) returns the N OLDEST eligible creators, oldest-first".

---

## §5.3 real-render results

- **Verify variant:** subjects distinct across en/pt/it; body distinct per locale; **the full verify-link shape `{frontend_main_url}/auth/verify-email?token=` appears in the rendered body** (the Q2 drift guard) — pinned in `IncompleteCreatorNudgeMailTest`.
- **Finish variant:** subjects distinct across en/pt/it; the `{frontend_main_url}/onboarding` deep link appears in the rendered body.
- **Queued-locale assertion:** `IncompleteCreatorNudgeCommandTest` "flag ON → queues one email per variant with the recipient preferred_language" pins `Mail::assertQueued(…, fn($m) => $m->variant === Verify && $m->locale === 'pt')` and the Finish/`'it'` counterpart — i.e. the mail is queued with the recipient's `preferred_language`.
- **Locale parity:** `LangParityTest` green — all 24 `creators.php` expose exactly en's key-set with matching `:name` / `:hours` placeholders.

---

## §5.4 — N/A (no per-send audit row)

The nudge writes **no per-send audit row**. The `incomplete_nudge_sent_at` stamp **is** the send record (queryable, once-only). The stamp is written via `updateQuietly()`, which bypasses the `Audited` observer — `incomplete_nudge_sent_at` is deliberately **not** in `Creator::auditableAllowlist()`, so no audit row is produced and no reason is required. The only audit surface for this feature is the **flag flip** (`feature_flag.toggled`, reason-mandatory), which is the operator action worth auditing. §5.4's transactional-audit requirement is therefore **N/A** for the send path by design.

---

## Break-revert (§5.35) — the flag gate, verbatim

**Break:** in `IncompleteCreatorNudgeService::send()`, changed the gate to `if (false && ! Feature::active(IncompleteCreatorNudgeEnabled::NAME))` (force the no-op branch unreachable).

**Result (flag-OFF spec reds):**

```
   FAIL  Tests\Feature\Modules\Creators\IncompleteCreatorNudgeCommandTest
  ⨯ it (1) flag OFF → the command is an explicit no-op: nothing queued,… 0.97s
   FAILED  …  AssertionFailedError
  Output does not contain "is OFF".
  Tests:    1 failed (4 assertions)
```

**Revert + re-run (green):**

```
   PASS  Tests\Feature\Modules\Creators\IncompleteCreatorNudgeCommandTest
  ✓ it (1) flag OFF → the command is an explicit no-op: nothing queued,… 0.49s
  Tests:    1 passed (6 assertions)
```

**Post-restore `git status` / `git diff`:** the service file is a **new (untracked) file** (`?? apps/api/app/Modules/Creators/Services/IncompleteCreatorNudgeService.php`), so `git diff` is empty after restore — the gate line reads exactly `if (! Feature::active(IncompleteCreatorNudgeEnabled::NAME))` again, and the spec is green. This proves the flag-OFF no-op is carried by the gate, not by an incidental empty-eligibility set.

---

## Gate table (at HEAD, feat + docs applied)

| Gate                             | Command                                            | Result                                                                                                                                                                                        |
| -------------------------------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend Pint (CI-authoritative)  | `php vendor/bin/pint --test`                       | **passed**                                                                                                                                                                                    |
| Backend Larastan                 | `php vendor/bin/phpstan analyse --memory-limit=2G` | **OK — no errors** (823 files)                                                                                                                                                                |
| Backend Pest (full)              | `php -d memory_limit=512M vendor/bin/pest`         | **1869 passed, 1 skipped** (6585 assertions)                                                                                                                                                  |
| Locale parity (24-locale en-SOT) | `LangParityTest` (within Pest)                     | **green**                                                                                                                                                                                     |
| Admin FE — feature-flags spec    | `pnpm exec vitest run src/modules/feature-flags`   | **4 passed** (confirms the dynamically-listed new flag doesn't break the page; the spec mocks the API)                                                                                        |
| Frontend typecheck / lint        | —                                                  | **N/A this chunk — no frontend files changed** (the admin flags page lists flags from the API; the new flag needed no FE edit, and `CreatorResource.wizard.flags` was deliberately untouched) |

The 1 skipped test is a pre-existing skip unrelated to this chunk.
