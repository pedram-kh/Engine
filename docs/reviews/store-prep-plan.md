# AH-105 / AH-111 / AH-112 — Store prep (iOS first): plan

- **Status:** **PLAN CLEARED by Pedram, 2026-10-29**, with the rulings answered inline in §9 (Deviations 1 to 3 accepted; Q1 to Q8 ruled). Build order S1 to S7; stop at S8 before the docs pass and the push; the S1 to S7 commits are not pushed until Pedram says go.
- **Inventory:** [`store-prep-inventory.md`](store-prep-inventory.md) (accepted 2026-10-29; rulings D1–D10 in its §8).
- **Stacks on:** `f21bb5fb`. Everything here rides the one pending combined deploy and TestFlight build 3 (the `deploy-log.md` PENDING entry).
- **Mode:** the writing and the iPhone-only setting are Mode B-sized. **AH-111 and AH-112 each touch an i18n key, so each is its own full-loop chunk** (`WORKING-PROCESS.md` §2, Mode A trigger); the inventory for both is §3 and §4 below.
- **PROD-DATA RISK, per item:**
  - **AH-105 code and writing: NONE.** No schema, API, route table or data. The seeder refuses to run outside local and testing.
  - **AH-111 and AH-112: NONE.** Client copy and a `mailto:` link; no API change.
  - **Demo account (Pedram's act, §6): LOW to MEDIUM** when performed. It is production data written through the admin UI.
  - **Weekly erasure (D10): the highest of the arc** at each click. It is already governed by `account-erasure.md`; this plan adds a routine, not a mechanism.
- **Hard stop-gates touched:** i18n keys (AH-111 changes one value, AH-112 adds one key). Nothing else on the list: no schema, API field, validation, policy, route, state-machine edge or scheduled job.

## 1. What is built, in order

Each sub-step ends green on the touched packages (Vitest main, `vue-tsc`, ESLint, Pint/PHPStan for the seeder, locale parity). The full board runs once at the end (§7).

| Step | Item                                      | Kind                 | Depends on                                       |
| ---- | ----------------------------------------- | -------------------- | ------------------------------------------------ |
| S1   | iPhone-only                               | native config        | none                                             |
| S2   | **AH-111**: native approved banner reword | i18n, full loop      | none                                             |
| S3   | **AH-112**: "Report a problem" link       | i18n + UI, full loop | none                                             |
| S4   | Signed-in privacy-policy link             | UI                   | none                                             |
| S5   | Store-screenshot seeder (local only)      | PHP                  | none                                             |
| S6   | Weekly-erasure runbook section (D10)      | docs                 | none                                             |
| S7   | `docs/store/*` writing deliverables       | writing              | S2 for the screenshot list; S5 for the seed spec |
| S8   | Docs pass, gates, close-out               | docs                 | all                                              |

## 2. S1: iPhone-only (D1)

- **Change:** `TARGETED_DEVICE_FAMILY = "1,2"` → `"1"` in both app-target configurations (`project.pbxproj:338` Debug, `:361` Release). Remove the now-dead `UISupportedInterfaceOrientations~ipad` block from `Info.plist`.
- **Effect:** no iPad screenshots, no iPad review. An iPhone-only app still installs on an iPad in compatibility mode, so a TestFlight tester on an iPad is not locked out (from memory). The change lands before any App Store release, which matters because dropping a device family after release is restricted (from memory; verify).
- **Proof:** a new spec beside `pushIosConfig.spec.ts` reads `project.pbxproj` and asserts both configurations say `"1"` and `Info.plist` has no `~ipad` key; **break-revert (§5.35):** set one configuration back to `"1,2"`, watch the spec fail, revert, `git diff` clean. Pedram confirms with
  `xcodebuild -showBuildSettings | grep TARGETED_DEVICE_FAMILY` on the archive.
- **Not verified:** whether any existing spec already pins the device family (I found none by grep; the check is the first act of S1).

## 3. S2: AH-111, the native approved banner (D4)

- **Inventory (done here).** One consumer: `CreatorDashboardPage.vue:269`, `creator.ui.dashboard.native.approved_body` (`en/creator.json:381`). No spec reads the string by value (grep found it only in the 24 locale files and one review doc). The banner shows to every approved creator on native.
- **Copy (proposal, for your edit):** en "You can now receive brand collaborations. Invitations and messages from agencies will appear here." No "soon", no "coming", no promise of an unbuilt feature. es is written by me and read by you.
- **Mechanism.** Same key, new value, regenerated across **all 24 locales** (the old translations of "coming soon" would otherwise linger), real MT baseline for the flaky ten (`bg el et fi ga hu lt lv mt ro`), no English fallback. Gate: `node scripts/i18n/verify-locale.mjs <loc>` for all 24, plus the parity specs.
- **Proof:** a spec pins that the en value matches neither `/soon/i` nor `/coming/i`; **break-revert:** restore the old en string, watch it fail, revert. Playwright exposure: the creator dashboard is E2E-traversed; I state the answer at S2 and run the full E2E before the push if it is (§7).
- **Q1 (ruled: no):** the web-only sibling `creator.ui.dashboard.approved.description` (`:341`, "The full creator dashboard ships soon.") is unreachable on iOS, still shows on web, and is **left unchanged**; the follow-up is in §9.
- **Not done by this chunk:** AH-110 (the ~180–205 English `creator.json` keys in the ten locales).

## 4. S3: AH-112, "Report a problem" (D8)

- **Where the thread surfaces are (read):** the creator relationship thread is `CreatorRelationshipThreadPage.vue` → `RelationshipThreadView.vue`, addressed by **agency ulid** (`route.params.agencyUlid`, `:35`); the campaign thread is `CreatorAssignmentDetailPage.vue:645` → `ChatPanel.vue`, addressed by **assignment ulid** (`route.params.ulid`, `:77`).
  Both views are **shared with the agency app** (`AgencyRelationshipThreadPage`, `BoardCardDrawer`, `ChatDialog`).
- **Reinterpretation to rule (Q2, §5.32).** Your ruling says "thread ulid". The client does not hold one: `ThreadMetaLike` is `unread_count`, `last_message_at`, `human_send_blocked` (`useMessageThread.ts:29-33`), and the pages are keyed by agency ulid and assignment ulid.
  The **intent** is that support can find the conversation. **Mechanism proposed:** the subject carries the id the page already has, with a kind word: `Catalyst app report: relationship <agency-ulid>` or `Catalyst app report: assignment <assignment-ulid>`. Support resolves the thread from the sender's account plus that id. Putting the real
  `message_threads` ulid in the subject would be an API field addition (a stop-gate) and is **not** proposed.
- **Design:** a small text link "Report a problem" in the thread header, rendered only when a new optional prop (`reportSubject`) is passed; **only the two creator pages pass it**, so agency pages render nothing (consumer surfaces named: `AgencyRelationshipThreadPage`, `BoardCardDrawer`, `ChatDialog`, `CampaignMessagesPanel`, all unchanged; a negative spec per shared view).
  `href = mailto:${SUPPORT_EMAIL}?subject=<encoded>` with `SUPPORT_EMAIL` from `core/config/support.ts:10` (default `support@catalyst-growth.com`). Shown on web and native (no `isNative` branch). The subject is a fixed English prefix for triage and carries a ulid only: no name, email or message text.
- **i18n:** **one new key** (the link label, plus its aria label if the existing pattern needs one) × 24 locales, flaky ten with real MT, `verify-locale` 24/24.
- **Proof:** specs for the exact `href` and encoding on both surfaces, absence on the agency views, and the label per locale; **break-revert:** pass the prop from an agency page, or drop the `encodeURIComponent`, watch the right spec fail.
- **Known limit:** a phone with no mail account configured does nothing useful when tapped. Accepted under D8's "minimal" (the notes and the support page also name the address). Pedram walks it once on the device (Pedram's checklist, §6).
- **Not claimed:** this is not blocking or filtering. The 1.2 argument rests on vetted B2B (§5 of the review notes), which I verify against code at writing time and cite.

## 5. S4, S5, S6

**S4: signed-in privacy link.** One entry in the native **More** menu (`CreatorDashboardLayout.vue`, the existing More sheet) and the web avatar menu, opening `FOOTER_PRIVACY_HREF` (`footerLinks.ts:75`) through `footerLinkAttrs` (new tab on web; Safari from the Capacitor view, which the 104d walk W3 already proved for the sign-up link). **No new key:** reuse
`auth.ui.consent.privacy` ("Privacy Policy", `en/auth.json:228`, already in all 24). To confirm at build: that `auth.*` keys resolve in the creator shell. Specs: the entry's `href` and the absence of the footer links elsewhere; break-revert on the href.

**S5: the seeder.** `Database\Seeders\StoreScreenshotSeeder`, **not** called by `DatabaseSeeder`, run explicitly. Guarded exactly like `Sprint1IdentitySeeder` (throws outside `local` and `testing`); a spec asserts the throw, with a **break-revert** on the guard. Content, all fictional (invented brands, generated initials-and-gradient avatars written to local MinIO, no real faces): one approved creator (profile
strength about 85%, four portfolio items, notifications), one demo agency, three campaign invitations in different states (so the AH-107 chips differ), four listed jobs, one relationship thread of about six messages and one campaign thread, eight notifications. No new table, so the erasure registry spec is untouched. Pest + Pint + PHPStan. The same fiction is the content script for the production demo account (§6).

**S6: the weekly routine (D10).** A new §1d in `docs/runbooks/account-erasure.md`: Monday, open Compliance → Erasure queue; read Pending and Ready; for each Ready row the pre-click checks already in §1c (Q10 rule: the claimed count is 0; the dry-run counts; hold; a fresh 2FA code); then check the support inbox.
Worst case from request to erasure is 14 days of grace plus up to 7 days of waiting, so about 21 days (inside the one-month GDPR window if nothing fails). **Start condition (Q6, ruled): "after C4 preconditions close", with no calendar date.** The routine does not start until §2 of that runbook is done (snapshot, the Postgres run of the erasure specs, the mirror prefix, the erasure worker, and `account_erasure_execute_enabled` armed for the first named account at C4). Nothing in §2 is closed today (Pedram, 2026-10-29).

## 6. S7: the writing deliverables (`docs/store/`), and what each is built from

Every number (character limits, counts) is checked by a script, not by eye. Every factual claim cites `path:line`. Nothing promises a feature that is flagged off or unbuilt (payments, escrow, contract signing, social connect, KYC). es is written by me; **you read en and es** (the AH-104d/AH-110 rule that es is real and the flaky ten are not yet).

| File                             | Contents                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Built from                                                                                                      | Final owner                           |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| `listing-en.md`, `listing-es.md` | Name candidates (ASC name already reserved: you read it), subtitle ≤30, promo ≤170, description ≤4000, keywords ≤100, copyright line, category Business, release notes not needed for 1.0                                                                                                                                                                                                                                                                                     | The shipped feature set only (invitations, jobs board, messaging, profile and portfolio, push, in-app deletion) | You enter in ASC                      |
| `review-notes.md`                | Paste-ready ASC text: creator-only sign-in, demo credentials placeholder (never the password in the repo), where deletion lives, the 14-day grace and restore, email verification needs a real inbox, reviewer sign-ups stay "under review", push optional, a one-paragraph vetted-B2B argument for 1.2 with citations (accounts admin-approved; a thread exists only between a creator and an agency connected to them; no public posting; the report link; support contact) | Inventory §4, §7; code at writing time                                                                          | You paste                             |
| `demo-account-runbook.md`        | Step-by-step through the admin UI with a **PROD-DATA RISK** line: an approved creator, no 2FA, undiscoverable, a dedicated demo agency whose roster is only that creator, one **unlisted** campaign and one invitation, an admin legal hold, the password in your password manager and rotated after review, cleanup of reviewer-created accounts, and the agency step, which is **bootstrap-only** (Q8, answered in §9)                                                      | `account-erasure.md`, admin controllers, `feature-flags.md`                                                     | You perform it                        |
| `app-privacy-answers.md`         | The nutrition-label sheet: per type, collected / linked / tracking / purpose, with the source `path:line`; D5 applied; what to re-declare when KYC, tax or payout turn on; Google's FCM mapping                                                                                                                                                                                                                                                                               | Inventory §3                                                                                                    | You enter in ASC                      |
| `privacy-policy-app-section.md`  | A "Catalyst creator app" section for `/legal/privacy`: controller, data by category, purposes and lawful bases, processors (AWS, Google Firebase Cloud Messaging, Postmark, Apple APNs), international transfers, retention (backups 7 days, logs up to 14, erased anchors kept, audit rows scrubbed), in-app deletion with the 14-day grace and "within one month", rights, no tracking, no sale, **one sentence: intended for people aged 18 or over**, contact `support@`  | `account-erasure.md` §7, PII map, inventory §3 and §5                                                           | You publish; **not legally reviewed** |
| `support-page.md` (en + es)      | Contact `support@catalyst-growth.com`, how to ask for deletion (link to `/delete-account`), how to report a problem, sign-in and verification help; no response-time promise unless you give one                                                                                                                                                                                                                                                                              | Inventory §1                                                                                                    | You publish                           |
| `deletion-texts-en-es.md`        | Side-by-side of every deletion string: `creator.json:606-626`, `:631-641`, `auth.json:208` and the `/delete-account` keys, plus the confirmation mail once located; notes where the text and the policy must agree (backups, the 14 days)                                                                                                                                                                                                                                     | The locale files                                                                                                | You read and mark                     |
| `age-rating-answers.md`          | Answers to the ASC questionnaire from the code: messaging yes, user-generated content yes, web access no, purchases none, no gambling or mature themes. **Ruled 17+**; ASC computes the tier from the answers and the report says what it shows (Q4, ruled)                                                                                                                                                                                                                   | Code                                                                                                            | You enter                             |
| `screenshot-shot-list.md`        | Six iPhone 6.9" shots (1320×2868), **English only, plain captures, no framing** (Q3, ruled); the Spanish listing text uses the same English screenshots; the data each needs from S5, the simulator and `simctl` status-bar commands, a "do not show" list, and a post-launch list that holds the Spanish screenshots                                                                                                                                                         | Inventory §2; S5                                                                                                | You approve                           |

## 7. S8: gates and close-out

- **Gates at HEAD:** full backend Pest serial (`-d memory_limit=2G`, with `SANCTUM_STATEFUL_DOMAINS` set as the template notes), Pint `--all`, PHPStan; full Vitest main and admin, api-client, `vue-tsc`, ESLint; `verify-locale` ×24 and the three i18n specs; **full Playwright** if S2, S3 or S4 touch an E2E-traversed surface (stated per step, run with the dev stack down).
- **Commits:** one conventional commit per theme (S1, AH-111, AH-112, S4, S5), then the docs commit (the `docs/store/` files, the runbook section, the AH-105 / AH-111 / AH-112 entries, Live Status rows, the template refresh). Push held until you clear it and say go; CI green cited at the pushed tip.
- **Deploy-log disposition:** stack on the open PENDING entry: AH-111, AH-112 and S4 ride the **web build** and build 3; S1 rides **build 3 only**; S5 and the writing ride nothing; **API: none** for any of it. Build-3 checklist additions: read `aps-environment` on the archive, run the privacy report, confirm Associated Domains in the profile, confirm no existing build 3 in ASC.
- **AH entry records:** D7 (`UIBackgroundModes` left out, warning accepted), D9 (17+ and the 18+ sentence, with no enforcement behind it, the AH-088 D8 position), and the open S3 versioning question.

## 8. Pedram's checklist (in order)

**Before the deploy**

1. Q1–Q8 are ruled (§9); nothing left to rule.
2. Read the ASC app name; check for an existing build 3 upload.
3. Confirm the legal seller name on team `LTW9262ZXP`; supply the EU trader phone number.
4. Read Postmark's message-log retention in its console; confirm the production AWS region (the repo only shows `eu-central-1` in `.env.example`); answer S3 versioning.
5. Read es and en in the listing, support page and deletion sheet; mark changes.

**Around the deploy** 6. Web deploy carries `/delete-account` and the AASA file (C1–C7 as already planned). Publish the policy section and a support page on the marketing site (`/legal/privacy` content; a new support URL). **Both must be live before the first external TestFlight tester**, not only before submission (from memory: Beta App Review needs a privacy URL and a demo account). 7. Create the demo account per the runbook (after the deploy, with `account_deletion_enabled` ON); read it back.

**Build 3 and after** 8. Archive on your machine with `.env.native-production`; read the archive's entitlements (`aps-environment` = production; Associated Domains); run the privacy report; confirm `TARGETED_DEVICE_FAMILY = 1`. 9. Walk: Report a problem (with and without a mail account), the privacy link, the AH-111 banner, and the existing build-3 items (W3 of AH-103d, production APNs, universal links). 10. Capture the six English screenshots from the seeded simulator; approve the set (no Spanish set for 1.0). 11. ASC entry: listing en + es, category, age rating, App Privacy, review notes, demo credentials, support and privacy URLs, trader status. Flag flips per the deploy log, then testers, then submission. 12. Start the Monday erasure routine only after the C4 preconditions in the runbook's §2 are closed (no date).

## 9. Questions and rulings (Pedram, 2026-10-29; answers inline)

Deviations of this plan from the earlier rulings, all **accepted**: **Deviation 1** (the report subject carries the ids the client holds), **Deviation 2** (age tier computed by ASC), **Deviation 3** (the Monday routine has no calendar start).

- **Q1. Reword the web-only "ships soon" key too? RULED: no.** `creator.ui.dashboard.approved.description` stays unchanged in AH-111. **Follow-up, to be logged under AH-111 at the docs pass (S8) and tied to tech-debt 17: "reword the web banner after the store release".**
- **Q2. Report subject. RULED (Deviation 1): accepted.** The subject carries the **agency ulid** (relationship thread) or the **assignment ulid** (campaign thread). No API change, no thread ulid.
- **Q3. Spanish screenshots and framing. RULED:** English screenshots only for 1.0, plain captures, no framing. The Spanish listing text uses the English screenshots. **Spanish screenshots go to the post-launch list** (held in `screenshot-shot-list.md`).
- **Q4. Age tier. RULED (Deviation 2): accepted.** I draft the age-rating answers; ASC computes the tier; I report what it shows (Pedram enters the answers; the tier is read from ASC, not assumed).
- **Q5. The 18+ policy sentence. RULED:** it stays, **with the no-enforcement record** (AH-088 D8: no minimum-age rule anywhere in the stack). The AH-105 entry and the policy-section file both say so.
- **Q6. Erasure prerequisites. RULED (Deviation 3): accepted.** Nothing in `account-erasure.md` §2 is closed. The Monday routine is written with the start condition **"after C4 preconditions close"** and no calendar date.
- **Q7. Retention sentence. RULED: yes.** The policy section includes "within one month of the grace ending".
- **Q8. Can an agency be created through the admin UI? ANSWERED (read-only check, 2026-10-29): no, it is bootstrap-only.** The admin API's agencies group is `index`, `show`, `suspend`, `reactivate` and the member 2FA actions only
  (`apps/api/app/Modules/Admin/Routes/api.php:61-84`); the only agency-creating code is the `_test/agencies/setup` helper (`TestHelpers/Routes/api.php:106`), which exists only in `local` and `testing` (`TestHelpersServiceProvider.php`), and `Sprint1IdentitySeeder`, which throws outside those environments.
  The demo-account runbook therefore names an exact `php artisan tinker` script for Pedram to run after the deploy, with a read-back step (S7).

## 10. Not verified

Apple's current screenshot sizes, trader rules, tier names, the iPhone-only-after-release restriction and the 5.1.1(v) grace position (from memory); that `mailto:` opens the system mail sheet from the Capacitor view (the delete-account page already uses a `mailto:`, but its walk result is not recorded); that `auth.*` keys resolve in the creator shell; the confirmation-mail copy; `/contact` content;
Postmark retention; the AWS region; S3 versioning; the state of the erasure prerequisites.
