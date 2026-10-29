# App Store listing, English (AH-105)

**For Pedram to read and enter in App Store Connect (ASC).** Written from the shipped feature set only. Nothing here promises a feature that is flagged off or not built: no payments or payouts, no contract signing, no social-account connect, no identity check (`WIZARD_HIDDEN_STEPS`, `packages/api-client/src/wizard.ts:30`; flags in `docs/runbooks/deploy-log.md:97,163`). Character counts are checked by `docs/store/check-listing.mjs`, not by eye; run it after any edit.

- **Locale in ASC:** English (U.K. or U.S.: your choice; the text is neutral).
- **Primary category:** **Business** (D2). Do not choose Social Networking (it invites guideline 1.2 scrutiny).
- **Screenshots:** the six English iPhone 6.9" shots in `screenshot-shot-list.md`. The Spanish listing reuses them (Q3).
- **Release notes:** not needed for 1.0.

## Name (30)

ASC already holds a reserved name (builds 1 and 2 are in TestFlight): **read it in ASC and keep it.** If you are free to choose, the candidates are below; the home-screen name is "Catalyst" (`apps/main/capacitor.config.ts`, `appName`).

```
Catalyst Creators
```

Alternates: `Catalyst: Creator Collabs`, `Catalyst for Creators`.

## Subtitle (30)

```
Brand collabs, all in one app
```

## Promotional text (170)

```
Receive brand collaboration invitations, browse job posts from your agencies, and message them, all in one place. Your profile and portfolio stay up to date.
```

## Description (4000)

```
Catalyst is the creator app for the Catalyst platform. It is where creators who have been approved by our team work with the agencies and brands they are connected to.

WHAT YOU CAN DO

• See your dashboard. A welcome summary, your profile strength and the collaboration invitations waiting for your answer.

• Answer invitations. Each invitation shows its status, so you can see at a glance what is new, what you have answered and what is under way.

• Browse job posts. Agencies you are connected with can list open jobs. Open one to read the details.

• Message your agencies. Talk to the agencies you are connected with, and to the team on a campaign you are part of. Share files and links in the conversation.

• Keep your profile current. Your photo, bio, social handles and portfolio are in one place. Block out the dates you are not available.

• Get notified. Turn on push notifications to hear about new invitations and messages. They are optional, and you choose which ones you want.

• Stay in control of your data. You can delete your account from inside the app, in Security. You have 14 days to change your mind before anything is erased.

HOW IT WORKS

Catalyst is not an open social network. There is no public feed and no way to message a stranger. Accounts are reviewed by our team before they can receive collaborations, and conversations only exist between a creator and an agency that are connected to each other.

You need an account to use the app. You can create one in the app and verify your email address; after our team approves it, you can start receiving invitations.

SUPPORT

Questions, a problem with a message, or a request about your data: write to support@catalyst-growth.com. In a conversation, tap Report a problem to write to us about that conversation.
```

## Keywords (100)

```
creator,influencer,UGC,brand deals,collaborations,agency,campaigns,portfolio,content,invitations
```

Notes: keywords are comma-separated with no spaces after the commas (ASC counts spaces). The app name and category words are not repeated (ASC indexes those already). Replace any term you would rather rank for.

## Copyright

```
2026 Catalyst Performance Ltd
```

The footer says "©2026 Catalyst", Catalyst Performance Ltd, company number 13632394 (`apps/main/src/modules/auth/components/footerLinks.ts:82-90`). **Confirm the legal seller name on team `LTW9262ZXP`** (it is Pedram's to confirm); ASC adds its own symbol, so the line above has none.

## URLs (ASC fields)

| Field                 | Value                                                        | State on 2026-10-29                                                                                                        |
| --------------------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| Support URL           | A new page on the marketing site (text in `support-page.md`) | `https://www.catalyst-growth.com/support` returned **404**. Publish before ASC entry.                                      |
| Marketing URL         | `https://www.catalyst-growth.com`                            | 200. Optional.                                                                                                             |
| Privacy Policy URL    | `https://www.catalyst-growth.com/legal/privacy`              | 200, but the text predates the app (finding 1 of the inventory). Add the section in `privacy-policy-app-section.md` first. |
| Account deletion page | `https://platform.catalyst-growth.com/delete-account`        | Live **after the web deploy** (it returned the app shell on 2026-10-29). Not an ASC field; named in the review notes.      |

## Claims this text makes, and where each is true

| Claim in the text                         | Source                                                                                                                                                                   |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Dashboard, strength, invitations          | `apps/main/src/modules/creators/pages/CreatorDashboardPage.vue` (banner, `DashboardStrengthCard`, `DashboardInvitationCard`)                                             |
| Invitation statuses                       | AH-107 chips on the Invitations list (`creator.assignments` route, `apps/main/src/modules/creators/routes.ts:54-55`)                                                     |
| Job posts from connected agencies         | The board shows campaigns of agencies the creator is rostered with (`apps/api/app/Modules/Campaigns/Services/JobsBoardVisibility.php:104`, `permitsMessaging()`)         |
| Messages with agencies and on a campaign  | `RelationshipThreadView.vue` and `ChatPanel.vue`; files and links as attachments                                                                                         |
| Profile, portfolio, socials, availability | `creator.profile` and `creator.availability` routes (`routes.ts:33-43`)                                                                                                  |
| Push notifications, optional, per type    | `creator.notifications.preferences` route (`routes.ts:123`); push is armed (deploy log, 2026-10-12): read the flag back before entering                                  |
| Delete in the app, 14 days                | `creator.ui.security.delete.*` (`creator.json:610-618`); behind `account_deletion_enabled`, **which must be ON at review**                                               |
| No public feed, no messaging a stranger   | `RelationshipSendState` and `canMessageRelationship`: a thread needs a roster relation (`apps/api/app/Modules/Messaging/Support/RelationshipSendState.php:66-74,86-110`) |
| Accounts reviewed before invitations      | `ApplicationStatus::Approved` gate on invites (`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php:157-166`)                               |
| "Report a problem" in a conversation      | AH-112: `apps/main/src/modules/messaging/utils/reportProblem.ts`                                                                                                         |
| Create an account in the app              | The sign-up page (the review notes explain the "under review" state)                                                                                                     |

**Not claimed on purpose:** payments, payouts or escrow; contracts; connecting Instagram or TikTok; identity verification; "24 languages" (D3: only en and es are claimed, because the flaky ten are mostly English in `creator.json`, AH-110).
