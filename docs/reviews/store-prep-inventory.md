# AH-105 — Store prep (iOS first): inventory

- **Status:** **ACCEPTED by Pedram, 2026-10-29**, with rulings D1–D10 (§8). Read-only inventory at `f21bb5fb`. Plan: [`store-prep-plan.md`](store-prep-plan.md).
- **PROD-DATA RISK: NONE** for this inventory (reads of the repo and GETs of public URLs only). The demo account (§4) is the one later step that writes production rows; it is flagged there and in the plan.
- **Orientation:** HEAD `f21bb5fb`, level with `origin/main`, clean tree. Order from here: AH-105 → deploy → TestFlight build 3 → production walks → flag flips → testers → App Store submission. Android later.
- **Honest limits.** Apple rules, sizes and tier names below are **from memory** unless marked "read"; verify them in App Store Connect (ASC). Engineering input, not legal advice (`WORKING-PROCESS.md` §7).

## Findings that change the plan

1. **The privacy policy URL exists but does not cover the app.** `https://www.catalyst-growth.com/legal/privacy` returns 200 (fetched and read 2026-10-29). It is the marketing-site policy of Catalyst Performance Ltd, "Last updated: 1 June 2025":
   enquiries, ad platforms, cookies. It says nothing about creator accounts, date of birth, address, photos, push tokens, Firebase, the in-app deletion flow, or the 7-day and 14-day retention. The sign-up consent
   line already links to it (`apps/main/src/modules/auth/components/footerLinks.ts:75`, pinned by `SignUpPage.spec.ts:477`), so the cheap fix is new content on the same URL.
2. **The app declares iPad support.** `TARGETED_DEVICE_FAMILY = "1,2"` (`apps/main/ios/App/App.xcodeproj/project.pbxproj:338,361`): iPad screenshots, iPad review, a phone-first UI on iPad. **Ruled D1: iPhone-only.**
3. **Guideline 1.2 (user-generated content) was in no earlier doc.** Creators and agencies exchange messages and attachments; `modules/messaging` has no report or block mechanism. **Ruled D8:** a minimal "Report a problem" link plus the vetted-B2B argument in the review notes.
4. **There is no minimum age anywhere in the stack** (`apps/api/app/Modules/Creators/Http/Requests/UpdateProfileRequest.php:67-71`, "offered and declined, D8" of AH-088). **Ruled D9:** age rating 17+ (see the tier-name note in the plan) and one privacy-policy sentence (18+).
5. **Reviewers cannot reach "approved" on their own** (up to 3 business days, admin approval), so a pre-approved demo account is mandatory. The app login is creator-only (`apps/api/app/Modules/Identity/Services/AuthService.php:108-110`), so an agency login is refused and the notes must say so.
6. **"Coming soon" copy shipped to every approved creator on iOS:** `creator.ui.dashboard.native.approved_body`, `apps/main/src/core/i18n/locales/en/creator.json:381` ("More of your creator dashboard is coming soon."), the only consumer
   `CreatorDashboardPage.vue:269`. The web variant `creator.ui.dashboard.approved.description` (`:341`, "The full creator dashboard ships soon.") is unreachable on native (the `v-if` chain at `CreatorDashboardPage.vue:260-293`). **Ruled D4: reword (AH-111).**
7. **The deletion copy reads as automatic; erasure is a manual click** (`docs/runbooks/account-erasure.md` §1; "After that, it cannot be undone", `creator.json:617`). Someone has to work the queue after the grace ends. **Ruled D10: Pedram, weekly, Monday.**
8. **Live URL state on 2026-10-29.** `/.well-known/apple-app-site-association` on the platform host returns the SPA shell as `text/html` (532 bytes; expected before the web deploy). `/delete-account` there is the same until the web deploy.
   `https://www.catalyst-growth.com/support` returns **404**. `/contact` returns 200 (not read for `support@`).

## 1. ASC listing

| Field                                                                         | State                                                                                                                                                                         | Owner                                  |
| ----------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- |
| App name (30)                                                                 | The ASC record exists (builds 1 and 2 are in TestFlight), so a name is reserved. Home-screen name is "Catalyst" (`Info.plist`, `CFBundleDisplayName`). I cannot see ASC.      | Pedram reads it                        |
| Subtitle (30), promo text (170, optional), description (4000), keywords (100) | Not in the repo                                                                                                                                                               | **Writing (mine)**                     |
| Category                                                                      | **Ruled D2: Business** (primary). Avoid Social Networking.                                                                                                                    | Pedram enters                          |
| Age rating                                                                    | **Ruled D9: 17+**, answers drafted from the code                                                                                                                              | Writing + Pedram                       |
| Copyright and seller                                                          | Footer: "©2026 Catalyst", Catalyst Performance Ltd, Co. 13632394 (`footerLinks.ts:82-90`). Confirm the legal seller name on team `LTW9262ZXP`.                                | Pedram                                 |
| Support URL                                                                   | `/support` is 404. `/contact` is 200 (not read). Footer email is `info@` (`footerLinks.ts:79`), not `support@`. Needs a live page naming `support@catalyst-growth.com`.       | Text: mine. Publishing: Pedram         |
| Marketing URL (optional)                                                      | `https://www.catalyst-growth.com`, 200                                                                                                                                        | Pedram                                 |
| Privacy policy URL                                                            | Finding 1                                                                                                                                                                     | Section text: mine. Publishing: Pedram |
| Account-deletion page                                                         | `platform.catalyst-growth.com/delete-account`, live after the web deploy; outside the app's `/creator/*` link scope on purpose (`creators/routes.ts`, Security route comment) | Deploy                                 |
| EU trader status (from memory)                                                | Needs a public address, phone and email; the phone is not in the repo                                                                                                         | Pedram                                 |
| TestFlight external testers (from memory)                                     | Need a feedback email, privacy URL, beta description and a demo account                                                                                                       | Pedram, with my text                   |
| Listing languages                                                             | **Ruled D3: en + es.** Do not claim 24 languages: the flaky ten are mostly English in `creator.json` (AH-110).                                                                | Writing                                |

## 2. Screenshots

- **Sizes (from memory):** iPhone 6.9" at 1320×2868 (1290×2796 or 1260×2736 also accepted); iPad 13" at 2064×2752 only if iPad stays (**D1 removes it**). One to ten shots, PNG or JPEG, no alpha.
- **Local tooling:** Xcode 26.0; simulators iPhone 17 Pro Max (iOS 26.0, 1320×2868 natively) and iPad Pro 13" (M4).
- **Screens:** dashboard (approved, invitations plus profile strength); campaign invitations with the AH-107 status chips; jobs board; a message thread; profile and portfolio; notifications feed. Skip the wizard, Security and the push soft-ask.
- **Seed data:** no creator seeder exists. `Sprint1IdentitySeeder` creates two agencies and a super admin, guarded to local and testing. A new guarded local-only seeder with fictional data is **code**. Images synthetic or licensed; no real faces or creators.
- **Capture:** `build:native:dev` against the local API; `simctl status_bar override` (9:41, full battery); `simctl io screenshot`. Reword the "coming soon" banner first (AH-111).
- **Approval:** Pedram signs off the set.

## 3. App Privacy ("nutrition labels")

- **Tracking: none.** The Meta Pixel is skipped on native (`SignInPage.vue:190-201`, pinned at `SignInPage.native.spec.ts:115-121`); Firebase Analytics is off (`GoogleService-Info.plist`: `IS_ANALYTICS_ENABLED` false); no ad SDK; no `NSUserTrackingUsageDescription`.
- **Crash data: none.** No Sentry package in `composer.lock`; `VITE_SENTRY_DSN_MAIN` empty; production read-back D says "no Sentry". Apple's own crash reports are not ours.
- **Location: none.** No location plugin or usage key. **Linked to identity:** everything below. **Purpose:** App Functionality; no analytics, personalisation or advertising.

| Apple category          | What we collect, from the code                                                                                                                                              |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Name                    | First and last name at sign-up; `display_name`                                                                                                                              |
| Email Address           | Sign-up                                                                                                                                                                     |
| Phone Number            | `phone`, `whatsapp` (optional)                                                                                                                                              |
| Physical Address        | Street, postal code, region, country                                                                                                                                        |
| Other User Contact Info | Typed social handles (OAuth is off)                                                                                                                                         |
| User ID                 | Account id and ulid; the sign-in token                                                                                                                                      |
| Device ID               | FCM token (`user_push_tokens.token`); Firebase installation id                                                                                                              |
| Photos or Videos        | Avatar, cover, portfolio, draft media, message attachments                                                                                                                  |
| Emails or Text Messages | In-app messages; application notes                                                                                                                                          |
| Other User Content      | Bio, draft captions, availability notes                                                                                                                                     |
| Other Data Types        | Date of birth; `content_companions` (coarse bands); `accent`; languages; categories; IP and user agent (audit logs, sessions, `last_login_ip`); audio inside captured video |
| Other Diagnostic Data   | `push_deliveries` (outcome plus a capped diagnostic string); platform and `app_version`                                                                                     |
| Other Usage Data        | Token `last_used_at`, push `last_seen_at`, notification read state, login events (not analytics)                                                                            |

**Ruled D5: as proposed** (IP and user agent under Other Data Types; audio declared conservatively; the proposed diagnostic and usage rows). Financial Info is not declared today because KYC, tax and payout are hidden (`WIZARD_HIDDEN_STEPS`,
`packages/api-client/src/wizard.ts:30`); the label must be updated **before** those steps are enabled.

**Third parties (read, Google's "Firebase and Apple's App privacy details" page, 2026-10-29):** `FirebaseMessaging` is "always collected": it records the APNs token and an installation ID, plus device model, language, time zone, OS version, app id and version.
Enter the label following Google's mapping page.

## 4. Review notes and demo account

- **Review notes (mine)** must say: sign-in is required and creator-only; where deletion lives (More → Security → Delete my account); the 14-day grace and the sign-in restore path; email verification needs a real inbox; a reviewer-created account stays "under review" by design; push is optional; and the vetted-B2B argument for 1.2 (D8).
- **Flags ON at review time:** `account_deletion_enabled` (`deploy-log.md:97,163`; it also gates the Security entry, the wizard account menu and the pending-page Delete link). `push_notifications_enabled` was armed 2026-10-12; read it back.
- **Flags OFF:** `contract_signing_enabled`, social OAuth, KYC, `account_erasure_execute_enabled`.
- **Demo account (production write; ⚠ PROD-DATA RISK: LOW to MEDIUM when performed):** a pre-verified, approved creator with no 2FA; one invitation from a dedicated demo agency whose roster holds only that creator; **the demo campaign must not be listed**
  (listing would push it to the job board and fan-out for about 279 real creators); undiscoverable; an admin legal hold so a stray Erase click cannot touch it; created through the admin UI, not SQL.
- **Reviewer deletion test** locks the demo account; a sign-in restores it. **Reviewer fresh sign-up** creates real production rows and a real approval-queue entry; Pedram rejects and erases them later.

## 5. Items already owed to AH-105

- **Consent wording.** "By creating an account, you acknowledge our Privacy Policy." (`SignUpPage.spec.ts:471`). A notice, not recorded, not legally reviewed (`tech-debt.md:3496`). `/legal/terms` exists (200) but is the agency client-services T&Cs of June 2025, not creator terms; do not link it. **Ruled D6: keep the wording.**
- **en/es read-through of the deletion texts:** `creator.json:606-626` (`security.delete.*`, `dialog.*`), `:631-641` (`account_deletion.*`), `auth.json:208` (deletion), the `/delete-account` page keys, and the confirmation-mail copy under `lang/` (not yet located). One side-by-side sheet for Pedram.
- **Privacy-notice inputs** (`account-erasure.md` §7): backups 7 days; logs up to 14 days; erased anchor rows indefinitely; audit rows kept but scrubbed; the failed-login-after-erasure row is not scrubbed until AH-104e; `push_deliveries` is a new stored category; Firebase holds an installation id.
  **SMTP provider: Postmark** (read-back 2026-10-12, `mail.default = smtp (smtp.postmarkapp.com)`, `deploy-log.md:254`, `deploy-ah099-ah103.md:51`). Postmark's own message-log retention is **not read**. **S3 versioning: still open** (`account-erasure.md` §2 item 5).
- **`ITSAppUsesNonExemptEncryption`** is present and `false` (`Info.plist`), correct for HTTPS and Keychain only (my reading).

## 6. Build config

| Item                        | State                                                                                                                                                           | Owner                                                         |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Version and build           | `MARKETING_VERSION = 1.0.0`, `CURRENT_PROJECT_VERSION = 3` (`project.pbxproj:324,332,348,356`; bumped in `0cc86e9a`)                                            | Pedram checks ASC for an existing build 3                     |
| `aps-environment`           | Committed as `development`; the file comment says automatic signing swaps in `production` on archive                                                            | Pedram reads the archive with `codesign -d --entitlements :-` |
| Associated Domains          | `applinks:platform.catalyst-growth.com` present; useful only once the AASA file is served (web deploy, C1–C7); the App ID and profile must carry the capability | Pedram, deploy                                                |
| `UIBackgroundModes` warning | Pre-existing, silent pushes only; `pushIosConfig.spec.ts:65` pins the key's absence. **Ruled D7: leave out**; record in the AH entry.                           | Record                                                        |
| Privacy manifest            | No app-level `PrivacyInfo.xcprivacy`; app code uses no required-reason API; Capacitor and Firebase ship their own                                               | Pedram runs "Generate Privacy Report" on the archive          |
| Production env              | `.env.native-production` is gitignored; build 3 is built on Pedram's machine                                                                                    | Pedram                                                        |
| Minor                       | Release keeps `NSAllowsLocalNetworking`; landscape declared on iPhone, unverified                                                                               | Note only                                                     |

## 7. Apple guidelines that could bite

| Guideline                     | Position                                                                                                                                                                       |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 5.1.1(v) deletion             | Built; reachable from More, the wizard menu and the pending page behind one flag. Risk: grace plus manual erasure is "a request, then processed"; the copy states the 14 days. |
| 4.8 Sign in with Apple        | Not triggered; no third-party login.                                                                                                                                           |
| 4.2 minimum functionality     | A bundled web shell with native push, badge, secure storage, universal links. Moderate risk; the notes lead with the native features.                                          |
| 2.1 placeholder or incomplete | Finding 6. The key `creator.ui.wizard.social_platforms.coming_soon` appears unused.                                                                                            |
| Login-required                | Demo account (§4).                                                                                                                                                             |
| 5.1.1(i) privacy policy       | In ASC (finding 1). In-app only the sign-up consent link; nothing once signed in.                                                                                              |
| 1.2 UGC                       | Finding 3.                                                                                                                                                                     |
| 1.5 contact                   | The support email appears in-app only inside deletion-error text.                                                                                                              |
| 3.1.1                         | No in-app purchase; payments and escrow are not built, so the listing must not promise in-app payouts.                                                                         |
| 5.1.2 and ATT                 | No tracking, so no prompt.                                                                                                                                                     |
| Minors                        | Finding 4.                                                                                                                                                                     |

## 8. Rulings (Pedram, 2026-10-29)

| #           | Ruling                                                                                                                                                                                                                        |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1          | **iPhone-only** (`TARGETED_DEVICE_FAMILY = 1`).                                                                                                                                                                               |
| D2          | Category **Business**.                                                                                                                                                                                                        |
| D3          | Listing languages **en + es**.                                                                                                                                                                                                |
| D4          | **Yes**, reword the native approved banner now as its own small chunk, **AH-111**, ×24 locales, no "soon" or "coming".                                                                                                        |
| D5          | Label judgement calls **as proposed**.                                                                                                                                                                                        |
| D6          | **Keep** the consent wording.                                                                                                                                                                                                 |
| D7          | Leave `UIBackgroundModes` **out**.                                                                                                                                                                                            |
| D8          | Add a minimal **"Report a problem"** link in message threads (`mailto:support@`, prefilled subject with the thread ulid), small chunk **AH-112**; also argue vetted B2B in the review notes.                                  |
| D9          | Age rating **17+**, plus one privacy-policy sentence (18+).                                                                                                                                                                   |
| D10         | **Pedram works the erasure queue weekly (Monday)**; runbook.                                                                                                                                                                  |
| D8 refined  | **Plan review 2026-10-29 (Deviation 1):** the report subject carries the **agency ulid** (relationship thread) or the **assignment ulid** (campaign thread), the ids the client already holds; no API change, no thread ulid. |
| D9 refined  | **Plan review 2026-10-29 (Deviation 2):** the age-rating answers are drafted and ASC computes the tier; the tier it shows is reported. The 18+ policy sentence stays, with the no-enforcement record (AH-088 D8).             |
| D10 refined | **Plan review 2026-10-29 (Deviation 3):** nothing in `account-erasure.md` §2 is closed; the Monday routine's start condition is "after C4 preconditions close", with no calendar date.                                        |
| —           | SMTP provider **Postmark** (confirmed from the earlier read-back). S3 versioning: **still open**.                                                                                                                             |

## 9. Corrections made when saving this file

- The banner key is `creator.ui.dashboard.native.approved_body` (the chat summary abbreviated it).
- Postmark is confirmed from the read-back (§5); Postmark's retention is still not read.
- Not verified, unchanged from the chat inventory: Apple's current sizes, trader rules, age tiers and the 5.1.1(v) grace-period position; `/contact` content; the ASC record; App ID capabilities; production flag states other than from the logs; the
  es confirmation-mail copy; landscape and iPad rendering (iPad now moot).
