# Creator app Figma fidelity audit and app-resume state refresh (AH-100d) — REVIEW

- **Status: Built, gates green (see §7 and §10), push HELD.** Extended by the second re-walk batch
  (eight items, §10): `9ef51fd4`, `f11ad8dc`, `a363bebf`, `88cb4fdd`, `2b8294d5` on top of the first ten. Acceptance is Pedram's re-walk (§9), including
  the new path: submit the wizard → approve as admin → background and reopen the app → the approved
  state appears with no logout. Nothing is pushed before his go. Independent review not yet done.
- **PROD-DATA RISK: NONE.** SPA-only and native-gated. No `apps/api` or `apps/admin` diff, no migration,
  no new API surface, no secrets in code. No dev database was read or altered. The web byte-identical
  pins are green and unedited.
- **Orientation:** at kickoff `HEAD` = `origin/main` = `b214c31f` (the AH-100c citation tip), working
  tree clean.
- **Commits (fast-batch, conventional, gates green per commit):** `ceae669a` radius · `313ecd4c` item 8 ·
  `eedccc44` photo row · `90cd920f` social cards · `be40c52b` portfolio tiles · `9b4ef8da` invitations ·
  `476f4a58` notifications · `c73589be` Welcome back + `welcome_back` locale repair · `766c2d63`
  review-step Profile-strength card. The docs commit (this file, the log entry, tech-debt, deploy-log,
  Part 2) is the second half of the pair.
- **Chunk:** Creator App arc, a new entry (AH-100d). Not a reopen of AH-100c.
- **Provenance:** audit rule and rulings by Pedram (invitations "ruled BUILD"), built by Cursor.

## 1. The audit rule and how the audit was run

**Rule (kickoff):** a gap that is pure styling, or uses data the app already has → **fixed**. A gap
that needs a new API shape or new behavior → **deferred**, named per item.

**Method, stated honestly.** The Figma file is `1Dd0NdZ55EXh58uNyvf9Bi`. I pulled the frame metadata
(every text node of every frame) and the screenshots of frames 01, 06, 06b, 06c, 10 and 12, and compared
them against the English locale strings, the templates and the native sheets. **I did not compare
rendered pixels of the built pages**: there is no backend or simulator harness in this session, and the
Playwright E2E needs a backend. The audit is therefore string-level and structure-level, plus
screenshot-against-CSS reading. Pedram's re-walk is the pixel check, as in AH-100c.

## 2. Why the AH-100 radius token never reached the buttons (the kickoff's question)

AH-100 set `border-radius: var(--native-radius-lg)` (8px) on native buttons. Every one of the app's
buttons is declared `rounded="0"` in the templates. Vuetify turns `rounded: 0` into the utility class
`.rounded-0 { border-radius: 0 !important }`. An `!important` utility beats a non-important token
rule on any specificity, so the token was computed correctly and applied never: the buttons rendered
0px. The fix is an `!important` rule in `packages/design-tokens/native.css` on every non-icon button
inside `.catalyst-native`, so the token wins over the utility. `native-button-radius.spec.ts` pins both
directions: the rule exists with `!important`, and no native sheet sets a button radius without it.
Break-revert in §7. **Latent on the web:** the same `rounded-0` utility overrides any web token radius the same way. The web's current look is untouched here (tech-debt).

## 3. Per-frame audit table

Frame ids: 01 `11:3` · 02 `11:75` · 03 `11:299` · 04 `17:169` · 05 `17:331` · 06 `17:492` · 06b `23:401` ·
06c `23:478` · 07 `20:222` · 08 `20:335` · 09 `20:510` · 10 `20:621` · 11 `22:277` · 12 `22:363` ·
13 `22:558` · 14 `23:590`.

| Frame                                                                         | Gaps found                                                                                                                                                                                      | Fixed                                                                                                                                                                                                                                                                                                                                            | Deferred (and why)                                                                                                                                                                                                             |
| ----------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **all (buttons)**                                                             | button radius 0px, not 8px (§2); the red outlined "Remove" style absent                                                                                                                         | `!important` radius rule; `danger-outlined` style; `--native-usp-6-subtle` token                                                                                                                                                                                                                                                                 | none                                                                                                                                                                                                                           |
| **01 Welcome back**                                                           | plain card with prompt text; no step list, no "Up next", no first-name greeting; `welcome_back` strings in 12 locales were word-substituted English (flaky-10) or Czech (hr/sl/sk)              | named greeting, subtitle, 64px ring, step list (check / name / "Up next" pill / to-do), footer hint; the nine `welcome_back` strings rewritten in bg el et fi ga hu lt lv mt ro hr sl sk                                                                                                                                                         | none for this frame                                                                                                                                                                                                            |
| **02 Basics, no photo**                                                       | dropzone vs the frame's dashed-tile row                                                                                                                                                         | dropzone **stays** as the empty state, as ruled; native "Tap to choose" copy already there                                                                                                                                                                                                                                                       | phone placeholder text from the frame (new string, no data); date of birth stays the native date input on purpose (platform picker)                                                                                            |
| **03 Basics, photo set**                                                      | thumbnail + dropzone + red link, where the frame has one row: avatar, "Replace", red "Remove"                                                                                                   | native filled-state row; Replace opens the same file input the dropzone used; web untouched                                                                                                                                                                                                                                                      | none                                                                                                                                                                                                                           |
| **04 Social + portfolio**                                                     | brand-less rows; no `@username`; no "Added" pill; Edit/Remove not inline; portfolio tiles had a corner badge, no type pill, no count; uploaded files listed twice (queue row and tile)          | social: brand tile icons (TikTok as a local inline SVG, MDI has none), `@handle`, "Added" / "Not connected", inline Edit and Remove, entry prefilled with Save and Cancel; portfolio: Image / Video / Link pill, heading with item count, corner badge hidden, finished queue rows hidden (in-flight rows stay)                                  | tile meta "Video · 0:24" (duration is not in the payload)                                                                                                                                                                      |
| **05** (portfolio states)                                                     | string-level pass only: no gap found beyond 04's                                                                                                                                                | covered by 04                                                                                                                                                                                                                                                                                                                                    | none found at that level (not pixel-checked, §1)                                                                                                                                                                               |
| **06 / 06b Review** (document header, sections)                               | the frame's header carries a "% read" line and banner copy                                                                                                                                      | nothing beyond the card styling below                                                                                                                                                                                                                                                                                                            | "% read" and the banner copy need a completeness-by-document signal the API does not expose; named, not built                                                                                                                  |
| **06c Review, Profile strength**                                              | the strength block was a bare bar                                                                                                                                                               | native card (radius 2xl, padding 20, surface, border), label in body-strong                                                                                                                                                                                                                                                                      | frame's "Profile strength" title, "To reach 100%" list, "Ready to submit" title, "Done" pill, "2 working days" footnote: need field-level completeness and review-time copy not in the payload                                 |
| **07 Dashboard**                                                              | done in AH-100c                                                                                                                                                                                 | —                                                                                                                                                                                                                                                                                                                                                | the pending/rejected/suspended states (design needed)                                                                                                                                                                          |
| **08 Availability / agenda**                                                  | string-level pass only: no gap found                                                                                                                                                            | —                                                                                                                                                                                                                                                                                                                                                | none found at that level (not pixel-checked, §1)                                                                                                                                                                               |
| **09 Notifications**                                                          | no type icon, no compact age, dot placement, no end-of-feed line                                                                                                                                | type-icon disc toned per type (unknown types get a neutral bell), compact age (`2h`, `3d`, a short date from 7 days), unread dot on unread rows only, "You're all caught up" at the end of the last page; `notificationVisual.ts` is the single map                                                                                              | title/subtitle split, All/Unread filter, "Mark all as read" wording: need a title/subtitle on the notification payload and a filter API ("No API changes in this batch"); the age reads `2h` with no "ago" (bare `{time_ago}`) |
| **10 Invitation**                                                             | one flat card                                                                                                                                                                                   | brand-initials tile (campaign name when no brand), status pill, Fee / Dates / Deliverables panel read from the payload (a row is left out when the payload has none), "Note from the brand" bar (omitted when empty), full-width **Accept invitation** with **View brief** and **Decline** beside it                                             | the 7-day expiry footer: the payload has no expiry. Everything else in the frame was in the payload                                                                                                                            |
| **11 Messages**                                                               | string-level pass only: no styling gap found                                                                                                                                                    | —                                                                                                                                                                                                                                                                                                                                                | "Search conversations": new behavior                                                                                                                                                                                           |
| **12 Campaign detail**                                                        | none structural                                                                                                                                                                                 | —                                                                                                                                                                                                                                                                                                                                                | subtitle wording with dates                                                                                                                                                                                                    |
| **13 Edit profile**                                                           | form body done in AH-100c; social and portfolio blocks inherit the 04 fixes                                                                                                                     | via 04 (the Edit-profile sheet carries a duplicate of each rule, §5)                                                                                                                                                                                                                                                                             | "Account details" card (first/last name, email): needs those fields on this page's payload; "Video · 0:24" as in 04                                                                                                            |
| **14** (sign-in / lapse)                                                      | covered in AH-100b; string-level re-check found nothing new                                                                                                                                     | —                                                                                                                                                                                                                                                                                                                                                | none found at that level                                                                                                                                                                                                       |
| **Invitation / assignment detail** ("Back to invitations", "View assignment") | no frame draws it (frame 12 covers the campaign page only); the page rendered as unstyled Vuetify: link-blue back link, 34px title, white-outlined Messages card, solid pink/green draft rounds | **undesigned — token-derived by Pedram's instruction.** Back link, Display title, status pill (info-tinted when invited), brand and fee line, About card, offer note rail, Accept / Decline on full 44px rows, Messages card with native bubbles, form cards with a full-width primary, tinted draft-history rounds, the four banner tones (§10) | none                                                                                                                                                                                                                           |
| **Job post detail**                                                           | no frame draws it; white-outlined card, bare chips, Vuetify meta line                                                                                                                           | **undesigned — token-derived by Pedram's instruction.** Bordered card at radius 2xl, canvas panel for fee and applicants, H4 section titles, neutral chip pills, Secondary link buttons side by side, attachments panel, full 44px Apply, the lifecycle notices as native banners (§10)                                                          | none                                                                                                                                                                                                                           |
| **Assignment detail, draft view** (Submit your draft, Messages thread)        | as above; the form card had a right-aligned small button                                                                                                                                        | **undesigned — token-derived by Pedram's instruction.** Native card, field styling inherited from the global native rules, full-width 44px Submit / Resubmit, revision banner, draft history                                                                                                                                                     | none                                                                                                                                                                                                                           |

## 4. New user-facing strings (STOP-GATE flag, raised in the moment)

Seventeen new keys, each **en + 23 locale dirs**, real MT, flaky-10 (bg, el, et, fi, ga, hu, lt, lv,
mt, ro) script-checked for empty and English-identical values (a loanword allowlist is explicit and
short). Verified with `node scripts/i18n/verify-locale.mjs` and the parity specs.

- `creator.ui.wizard.steps.portfolio.kind_label.{image,video,link}`, `…portfolio.gallery_count`
- `creator.ui.wizard.social_status.{added,not_connected}`, `creator.ui.wizard.fields.social_handle_placeholder`
- `creator.ui.upload.replace`
- `creator.ui.assignments.{accept_invitation,view_brief,note_from_brand,dates,deliverables}`
- `creator.ui.wizard.welcome_back.{title_named,subtitle_native,up_next,footer_hint}`

Plus a **repair, not a new key:** the nine existing `welcome_back` strings in 12 locales (the flaky-10
and hr/sl/sk) were rewritten, because the new screen sat on top of English or Czech text. For the
plural `gallery_count`, the Slavic, Baltic, Maltese, Irish and Romanian locales use a
"Label: {count} | Label: {count}" form so that no number-agreement form can be wrong.

**Second batch (§10), two more flags.** Five new keys, `availability.creatorNav.tab.{dashboard,jobs,assignments,messages,more}`, each **en + 23 locale dirs** (120 strings, real MT, flaky-10 script-checked: no empty value, no English-identical value, Cyrillic only in bg, Greek only in el). Plus a **repair** of the base `availability.creatorNav.assignments` label in ten locales, where an English plural "s" had been fused onto the native word. The repair is visible on the web (the top nav and the More menu read it), so it is flagged:

| Locale | Was           | Now         |
| ------ | ------------- | ----------- |
| bg     | Поканаs       | Покани      |
| el     | Πρόσκλησηs    | Προσκλήσεις |
| et     | Kutses        | Kutsed      |
| fi     | Kutsus        | Kutsut      |
| ga     | Cuireadhs     | Cuirí       |
| hu     | Meghívós      | Meghívók    |
| lt     | Kvietimass    | Kvietimai   |
| lv     | Uzaicinājumss | Ielūgumi    |
| mt     | Stedinas      | Stediniet   |
| ro     | Invitațies    | Invitații   |

Native-locale review is wanted for **ga, mt, lv, el** (the least certain). `mt` Dashboard is **Pannell** (not the English loanword), a judgement call a Maltese speaker should confirm.

## 5. Other stop-gate flags

1. **New dependency: `@capacitor/app` 8.1.2** (the resume event), with its Gradle and SPM wiring
   (`capacitor.settings.gradle`, `capacitor.build.gradle`, `Package.swift`) and the lockfile. Native
   projects need `cap sync` before a device build.
2. **`packages/ui` change: `PortfolioGallery.vue`** gains an optional `kindLabels` prop. Absent prop =
   unchanged DOM (spec'd). Consumers, none of which pass it except the first:
   - `apps/main`: `ConnectionsPortfolioSection` (passes it, native only; also reached through
     `CreatorProfilePage`), `DiscoverProfilePage`, roster `CreatorDetailPage`, `DraftReviewPanel`.
   - `apps/admin`: creators `CreatorDetailPage`.
3. The global `danger-outlined` button style in `native.css` (native-scoped).
4. Two parallel native sheets (wizard and Edit profile) each carry a copy of the rules for the shared
   components, as in AH-100c; `creator-profile-native-coverage.spec.ts` `PACKAGE_CLASSES` gained the
   gallery's kind, badge, footer and download classes.

## 6. Item 8 — stale session state (behavioral, native-gated)

**Cause:** the session and the onboarding state were fetched once at launch. A creator approved while
the app was backgrounded came back to the same under-review page until a logout.

- **Resume listener** (`src/core/platform/appResume.ts`, wired in `main.ts`): on Capacitor's app-active
  event, native only, throttled so near-simultaneous activations are one resume; it refetches `/me` and
  the onboarding bootstrap. Guards: nothing at all when signed out or before the first bootstrap;
  overlapping calls share one request; an answer that arrives after a sign-out or a different user's
  sign-in is dropped; a deliberate sign-out that lands mid-refresh is not reported as "session expired".
- **Under-review page poll** (`useApplicationStatusPoll`, used by `CreatorDashboardPage`): re-fetches the
  status on a modest interval while the page is visible, skips ticks while the document is hidden,
  stops on unmount. When the status flips to approved the page renders the approved branch with no
  reload. The web dashboard never polls.
- **Named follow-ups (not this batch):** the proactive version is **AH-102's push** (approval joins the
  push-worthy set). The **web page-poll is a named follow-up**; the web dashboard is deliberately
  unchanged here.

## 7. Evidence

**Gates (final, on touched packages)** — see the table at the end of this section, filled from the
runs at the feat tip `766c2d63`.

**Break-reverts (§5.35: mutate → capture the right red → restore → re-green).** Each row was a
single-anchor mutation of the load-bearing line, a vitest run captured red on the named spec, the file
restored from a copy and compared by sha256 (identical), then re-run green. Logs are kept locally under
`apps/main/test-results/br/` (gitignored).

| Id       | What was broken                                                      | Red, on                                                                      |
| -------- | -------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| A        | radius rule without `!important` / missing                           | `native-button-radius.spec` (2 red)                                          |
| H1       | resume listener subscribes on web                                    | `appResume.spec` "on the web it subscribes to nothing"                       |
| H2       | throttle removed                                                     | `appResume.spec` "treats activations closer than the throttle as one resume" |
| H3       | a mid-refresh sign-out reported as expired                           | `appResume.spec` "a deliberate sign-out … is not session expired"            |
| H3b      | refresh while signed out                                             | `appResume.spec` "does nothing at all when signed out"                       |
| H4       | `/me` before first bootstrap or for an anonymous session             | `useAuthStore.refresh.spec` (2 red)                                          |
| H5       | overlapping calls not shared                                         | `useAuthStore.refresh.spec` "shares one request"                             |
| H6       | late answer applied after sign-out / user switch                     | `useAuthStore.refresh.spec` (2 red)                                          |
| H7       | onboarding `refresh()` applies a late answer after reset             | `useOnboardingStore.spec`                                                    |
| H8       | onboarding `refresh()` before first bootstrap                        | `useOnboardingStore.spec`                                                    |
| H9       | poll not stopped on unmount                                          | `useApplicationStatusPoll.spec` + `CreatorDashboardPage.live.spec` (2 red)   |
| H10      | web dashboard polls                                                  | `CreatorDashboardPage.live.spec` "never polls"                               |
| H11      | poll or live flip removed                                            | `CreatorDashboardPage.live.spec` (2 red)                                     |
| H12      | poll ticks while hidden                                              | `useApplicationStatusPoll.spec` "skips a tick while hidden"                  |
| B1–B3    | photo row on web / empty state / Replace wiring                      | `AvatarUploadDrop.native.spec` (1 red each)                                  |
| C1–C4    | web markup leaks card / card parts / "Not connected" row / Edit flow | `ConnectionsSocialSection.native.spec` (1, 5, 1, 1 red)                      |
| D1–D3    | duplicate queue rows / pills and count / web leak                    | `ConnectionsPortfolioSection.native.spec` (2, 3, 1 red)                      |
| D5       | a rendered gallery class missing from the sheets                     | `creator-profile-native-coverage.spec`                                       |
| E1–E4    | card / panel / Deliverables row / note bar                           | `CreatorAssignmentsPage.native.spec` (7, 1, 1, 1 red)                        |
| F1–F4    | rows / caught-up / tone map / compact age                            | `NotificationCenter.native.spec`, `notificationVisual.spec` (4, 1, 2, 2 red) |
| G1a, G1b | step list / merged-step completion                                   | `WelcomeBackPage.native.spec` (5, 2 red)                                     |
| G1c      | step `kind === 'account'` completion                                 | `WelcomeBackPage.native.spec` (1 red)                                        |
| J1a–c    | invitation actions: Accept span / grid row / button may shrink       | `native-invitation-actions.spec` (1, 2, 1 red)                               |
| J3       | messages empty-state CTA back to `tonal` on native (mint on mint)    | `RelationshipInbox.native.spec` (1 red)                                      |
| J4a, J4b | Master agreement ticked from the flag-off pseudo-completion          | `WelcomeBackPage.native.spec` (5 red broad; 1 red, exactly the reported bug) |
| TB1      | tab cell takes the 8px button radius back                            | `native-tab-bar.spec` (1 red)                                                |
| TB2      | active overlay / underlay drawn (the filled block)                   | `native-tab-bar.spec` (1 red)                                                |
| TB3      | active line not shown on the selected tab                            | `native-tab-bar.spec` (1 red)                                                |
| TB4      | cells stop sharing the row equally / may not shrink                  | `native-tab-bar.spec` (1 red)                                                |
| TB5      | native bar reads the long base labels                                | `CreatorDashboardLayout.spec` "short French labels" (1 red)                  |
| TB6      | the line collapses to 0px                                            | `native-tab-bar.spec` (1 red)                                                |
| TB7      | a 24-character French tab label                                      | `native-tab-bar.spec` budget + `CreatorDashboardLayout.spec` (2 red)         |
| P1       | offer-note type loses `!important` (loses to `.text-body-2`)         | `native-detail-pages.spec` (1 red)                                           |
| P2       | draft round fill loses `!important` (solid pink block returns)       | `native-detail-pages.spec` (1 red)                                           |
| P3       | warning banner untinted                                              | `native-detail-pages.spec` (1 red)                                           |
| P4       | Accept / Decline row no longer a grid                                | `native-detail-pages.spec` (1 red)                                           |
| P5       | form submit no longer a full row                                     | `native-detail-pages.spec` (1 red)                                           |
| P6       | `data-status` rendered on the web                                    | `CreatorAssignmentDetailPage.spec` web leg (1 red)                           |
| P7       | `data-status` missing on native                                      | `CreatorAssignmentDetailPage.spec` native leg (1 red)                        |
| P8       | a rendered class left unstyled (coverage)                            | `native-detail-pages.spec` (1 red)                                           |
| P9       | invited pill untinted                                                | `native-detail-pages.spec` (1 red)                                           |

The first P8 mutation survived (renaming to `.job-detail__metaX` is still the same class to the
sheet scanner, which reads lowercase only). It was a bad mutation, not a weak pin: `.job-detail__met-a`
went red. Every restore was compared by sha256 and matched.

Disclosures: the first G1c mutation survived (a special case for the review step was equivalent to the
general path); I deleted the redundant code and re-ran a meaningful mutation, which went red. The
`packages/ui` `kindLabels` prop was covered by the new `PortfolioGallery.spec.ts` (4 tests, both
polarities: with and without the prop), and mutated by hand in `packages/ui`.
The item-8 set is 13 break-reverts (H1–H12 plus H3b), not a rounder number.

**Specs added** (both polarities, native and web): `native-button-radius`, `appResume`,
`useAuthStore.refresh`, `useOnboardingStore` (refresh block), `useApplicationStatusPoll`,
`CreatorDashboardPage.live`, `AvatarUploadDrop.native`, `ConnectionsSocialSection.native`,
`ConnectionsPortfolioSection.native`, `CreatorAssignmentsPage.native`, `notificationVisual`,
`NotificationCenter.native`, `WelcomeBackPage.native`, `PortfolioGallery` (ui).

**Verbatim check for item 8** — the specs that say it, by name:

- "on the web it subscribes to nothing and returns a harmless stop"
- "does nothing at all when signed out"; "does not call /me before the first bootstrap"
- "shares one request between overlapping calls"
- "drops the answer when the user signed out while it was in flight"; "…when a different user signed in…"
- "a deliberate sign-out that lands mid-refresh is not 'session expired'"
- "re-polls the status, and approval flips the page to the approved branch with no reload"
- "lands on the same approved page when the resume listener refreshes the store instead"
- "stops polling when the page unmounts"; "skips a tick while the document is hidden and resumes when visible"
- "makes no status request on its own, however long the page stays open" (web)

**Gates at the feat tip `766c2d63`** (run with `--testTimeout=90000` on a loaded host):

| Gate                                                                    | Result                                                                                                                |
| ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `apps/main` `vue-tsc --noEmit`                                          | exit 0                                                                                                                |
| `apps/main` eslint                                                      | 0 errors, 2 warnings (pre-existing `vue/no-v-html` in `MasterAgreementTerms.vue` and `ProfileBasicsForm.vue`)         |
| `apps/main` vitest, full                                                | 210 files, **2070 tests passed**, exit 0 (includes the architecture pins, i18n parity and used-keys specs)            |
| `packages/ui` vitest                                                    | 6 files, 47 tests passed (includes the new `PortfolioGallery.spec.ts`)                                                |
| `packages/design-tokens` vitest                                         | 3 files, 42 passed, 1 todo                                                                                            |
| `apps/admin` `vue-tsc --noEmit` and vitest (the `packages/ui` consumer) | exit 0; 58 files, 488 tests passed                                                                                    |
| prettier                                                                | changed `.vue` / `.ts` / `.css` and the docs: clean                                                                   |
| **Playwright E2E (wizard happy path)**                                  | **not run**: it needs the backend and a database, and no dev DB was touched. CI at the pushed tip is the gate (§5.41) |

The web byte-identical pins (`native-css-scoping`, `native-page-css-scoping`, the web-polarity
specs) are green and unedited.

## 8. Disclosures

- The audit is string- and structure-level plus screenshot reading; no rendered-pixel comparison (§1).
- Figma tint pairs measured below 4.5:1 (4.12, 4.19, 4.30) and text-tertiary captions and the overline
  are the design's own colors, reproduced faithfully. Logged, not "fixed" in code (tech-debt).
- The local machine ran at a load average of 100–340 during this batch, not from this session's
  processes; a few 5-second vitest timeouts were spurious and re-ran green with a longer timeout.
- Pre-existing locale debt found while auditing (about 107–127 English-identical `creator.json`
  strings per flaky-10 locale after this batch's repair of `welcome_back`; hr/sl/sk Czech-derived) is
  logged, not repaired.

## 9. Pedram's re-walk (acceptance before any push)

Rebuild: `pnpm --filter @catalyst/main build:native:dev`, `npx cap sync` (required: new plugin), relaunch.

1. **Item 8:** submit the wizard on the device → approve the creator as admin → background the app →
   reopen it → the approved dashboard appears, no logout. Also: leave it open on the under-review page
   while approving: it flips within the poll interval.
2. Buttons are 8px-radius everywhere (sign-in, wizard, dashboard).
3. Photo row (03), social cards (04), portfolio tiles with pills and count (04), the invitation card (10)
   with Accept full-width and View brief / Decline beside it, notification rows (09), Welcome back (01),
   the review step's Profile strength card (06c).
4. Web: a browser renders what it did before (the web pins are green, unedited).
5. **Second-batch walk (§10):** the Messages empty state button now reads "Start a conversation";
   a brand-new account's Welcome back shows Master agreement unchecked; Accept is full-width and
   fully readable on both invitation kinds; the tab bar in French (Accueil, Offres, Invitations,
   Messages, Plus) with a top line and no filled block, also with More open; the invitation detail,
   the job post detail (with and without the under-way banner) and the assignment detail (producing,
   revision requested) in the token-derived treatment.

## 10. Second re-walk batch (eight UI-only items)

Same fences: SPA-only, native-gated, no API or behavior change beyond display logic, web pins green
and unedited. **PROD-DATA RISK: NONE** (no `apps/api` or `apps/admin` diff, no migration, no secrets, no
dev database touched). Commits on top of the held ten: `9ef51fd4` (3), `f11ad8dc` (4), `a363bebf` (1),
`88cb4fdd` (5, 6), `2b8294d5` (2, 7, 8). Rendering was checked in a faked-platform Chromium harness
(local, gitignored), which is **not a device**: Pedram's re-walk is still the pixel check.

1. **Invitation card, Accept clipped.** Cause: a specificity tie. `.creator-main--native
.assignment__actions .v-btn { flex: 1 1 0 }` out-ranked Accept's `flex: 1 0 100%`, so with three
   actions Accept shared a row. Fix: the row is a two-column grid; Accept spans both and leads; View
   brief and Decline sit beside each other beneath it (`flex` does nothing on a grid item). **Both
   kinds:** the payload has no origin field, so direct-invited and applied-then-accepted cards share
   one markup and one rule. The spec renders both fixtures. The "GL" brand tile stays.
2. **Invitation detail.** §3 row. Token-derived.
3. **Messages empty state.** The key is fine: it resolves in all 24 locales (38,112 strings, none
   empty). The cause was paint, not text: the button was `tonal`, which the native sheet makes a mint
   fill with a mint label, so the label vanished. On native it is now `flat` (primary fill, dark label),
   web unchanged. **The used-keys tripwire should not have caught it**: the key exists and resolves; a
   new tripwire class would have nothing honest to check (a contrast check on a class combination is a
   different, larger tool). The behavioral spec pins the variant and the label text on native and web.
4. **Welcome back, Master agreement ticked on a new account.** Cause: the checklist asked "is this
   step complete in the store", and with contract signing OFF the click-through step reads as complete
   (`is_complete: true` is the flag-off pseudo-completion). It now uses the same status resolver the
   wizard uses, and a step counts as done only when that resolves to `completed`; the click-through
   step is completed only after the creator accepts. Display predicate only. Specs: fresh account
   (flag off, `done:['contract']`) unchecked and Profile basics "Up next"; accepted checked; signing
   ON signed checked; signing ON unsigned "next".
5. **French tab bar.** CSS: cells share the row equally (`flex: 1 1 0; min-width: 0`) and the label
   ellipsises, so a long label degrades instead of colliding. Labels: the native bar now reads five new
   short keys, `availability.creatorNav.tab.*`, while the web top nav and More menu keep the long ones.
   Every locale's five labels are under a 13-character budget (spec, all 24 locales). Changed labels:

   | Locale | Dashboard / Job Posts / Invitations / Messages / More |
   | ------ | ----------------------------------------------------- |
   | en     | Dashboard, Job Posts, Invitations, Messages, More     |
   | bg     | Табло, Обяви, Покани, Съобщения, Още                  |
   | cs     | Řídicí panel, Nabídky, Pozvánky, Zprávy, Více         |
   | da     | Dashboard, Jobopslag, Invitationer, Beskeder, Mere    |
   | de     | Dashboard, Jobs, Einladungen, Nachrichten, Mehr       |
   | el     | Πίνακας, Αγγελίες, Προσκλήσεις, Μηνύματα, Άλλα        |
   | es     | Panel, Ofertas, Invitaciones, Mensajes, Más           |
   | et     | Juhtpaneel, Töökohad, Kutsed, Sõnumid, Rohkem         |
   | fi     | Kojelauta, Työpaikat, Kutsut, Viestit, Lisää          |
   | fr     | **Accueil, Offres,** Invitations, Messages, Plus      |
   | ga     | Painéal, Poist, Cuirí, Comhráite, Tuilleadh           |
   | hr     | Početna, Poslovi, Pozivi, Poruke, Više                |
   | hu     | Irányítópult, Állások, Meghívók, Üzenetek, Továbbiak  |
   | it     | Dashboard, Lavori, Inviti, Messaggi, Altro            |
   | lt     | Skydelis, Darbai, Kvietimai, Žinutės, Daugiau         |
   | lv     | Panelis, Darbi, Ielūgumi, Ziņojumi, Vairāk            |
   | mt     | Pannell, Impjiegi, Stediniet, Messaġġi, Aktar         |
   | nl     | Dashboard, Vacatures, Uitnodigingen, Berichten, Meer  |
   | pl     | Pulpit, Oferty, Zaproszenia, Wiadomości, Więcej       |
   | pt     | Painel, Ofertas, Convites, Mensagens, Mais            |
   | ro     | Panou, Joburi, Invitații, Mesaje, Mai multe           |
   | sk     | Prehľad, Ponuky, Pozvánky, Správy, Viac               |
   | sl     | Pregled, Oglasi, Povabila, Sporočila, Več             |
   | sv     | Dashboard, Jobb, Inbjudningar, Chattar, Mer           |

   All 24 are new keys (the long base labels are unchanged on the web). Where the natural short form
   _is_ the English loanword (de, da, it, nl, sv "Dashboard") it is kept, as those languages use it.
   French was "Tableau de bord" / "Offres de mission": now "Accueil" / "Offres".

6. **Tab bar selected state.** What frames 07, 09 and 10 specify (read from node `20:296` and the
   screenshots): no fill and no rounded block on the selected tab; a 28×2 mint line at the top edge of
   the cell, icon and label in the accent colour, label in Caption-strong; unselected icon and label
   in text-tertiary; a 22px icon over a Caption label, 4px apart; the bar 59px plus the safe area, a
   1px top border on the sidebar surface. Applied as specified. Two findings: the AH-100d global button
   radius rule had reached the bar and rounded the cell, and the line AH-100 drew in `::after` had
   **never rendered** (Vuetify's own `.v-btn::after` is `opacity: 0`), so it is now a real element.
   The More button shows the same line while its menu is open.
7. **Job post detail.** §3 row. Token-derived. The under-way banner is the same tinted banner as the
   other notices.
8. **Assignment detail / View assignment.** §3 row. Token-derived. Items 2 and 8 are one component in
   different states, checked in invited, producing, revision-requested (with chat messages and two
   draft rounds).

**Design decisions to confirm on the re-walk.**

- The audit table rows marked "undesigned — token-derived by Pedram's instruction" are the three new
  rows (invitation detail, job post detail, assignment detail), read as your items 2, 7 and 8.
- The tinted banner style now covers all four tones for every creator page, not only the two detail
  pages (success, warning and error rows were not tinted before; info already was, globally).
- The offer note on the detail page has the rail but not the card's "Note from the brand" label: that
  would be a template change on a page whose markup this batch leaves alone.
- The bottom sheets (apply to a job, add a link) were not restyled.

**Specs added or extended:** `native-invitation-actions`, `RelationshipInbox.native`,
`WelcomeBackPage.native`, `CreatorAssignmentsPage.native`, `native-tab-bar`, `CreatorDashboardLayout`,
`native-detail-pages` (type, banners, rounds, actions, status pill, and the class coverage of both pages
plus `CreatorAttachmentsBlock` and `ChatPanel`), `CreatorAssignmentDetailPage` (the pill, both polarities).

**Gates at the tip of this batch** (`--testTimeout=90000`): `apps/main` `vue-tsc --noEmit` exit 0;
eslint 0 errors and the same 2 pre-existing warnings; vitest, full: 214 files, **2138 tests passed**;
prettier clean. `packages/ui`, `packages/design-tokens` and `apps/admin` are untouched in this batch
(their last green runs are in §7). The Playwright E2E was not run (needs the backend).
