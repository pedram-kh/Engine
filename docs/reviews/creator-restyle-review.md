# AH-115 review: creator web adopts the native look (L0 to L5)

**Mode:** A (full loop). **Plan:** [`creator-restyle-plan.md`](creator-restyle-plan.md). **Inventory:** [`creator-restyle-inventory.md`](creator-restyle-inventory.md) (rulings Q1 to Q11). **Baselines:** [`ah-115-agency-baseline`](ah-115-agency-baseline/README.md), [`ah-115-creator-baseline`](ah-115-creator-baseline/README.md).
**State:** pushed to `main` layer by layer (L0 to L4 at `2f27ab2b`; L5 at the tip, CI cited in the adhoc log). **Deployed: NO.** Deploy entry `PENDING` in [`deploy-log.md`](../runbooks/deploy-log.md). **PROD-DATA RISK:** NONE for the restyle; LOW for the one additive migration (`2026_11_09_100000`, an index on `relationship_threads(creator_id)`).

Evidence labels: MEASURED (ran it), READ (read the code), REPORTED (told by Pedram or the colleague), NOT PROVED.

## What changed, in one paragraph

The creator web (creator shell, onboarding, and the three creator-reachable auth screens) now renders with the native design system, through two runtime-registered themes (`creator-dark`, which is `nativeDarkTheme`, and `creator-light`, derived), not by widening `.catalyst-native`. `useNativeLook()` is `isNativePlatform() || inject(CREATOR_LOOK)`, so the native app renders as before and the web gets the same look. Behaviour gates (transport, tokens, push, resume) stay on `isNativePlatform`. The agency shell is untouched and proved byte-identical. On top: the creator unified inbox (Direct and "Brand · Campaign" tags, search by message body, deep link to the assignment chat) behind a new flag, and a Notifications page with a real header.

## Per-layer evidence

| Layer | Commits (first to last)                                                            | What                                                                                                                                                                                                                                                                  | Gate evidence                                                                                                                                                                                                                                |
| ----- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| L0    | `d1dd07d1`, `2009e80c`, `4af2cbd1`, `7b03767e`, `b3d22643`, `adb31fd8`             | Guards before any change: the agency baseline walk (126 targets), the agency-frozen hash spec (22 agency files), the import-graph and no-creator-class specs, the theme registration (unwired), the light derivation and its parity spec.                             | MEASURED: agency walk recorded on unchanged runtime, reproduced identically on a second run. Specs green. Break-reverts recorded in each commit (a brand-font token change fails the dashboard targets; reverted).                           |
| L1    | `caeccdcd`, `74a988f2`, `79b74a34`, `6b03123b`, `5128c4f0`, `b3a39db8`, `00832579` | Layouts register the creator theme; auth by route name; `native.css` scope widened to the creator themes and loaded by the hook; `useNativeLook`; the stored theme preference applies at load; the Roboto fix (Inter on `.text-*` and overlays in the creator scope). | MEASURED: agency walk 126 / 126 at the L1 tip. The progress-bar finding (below) was made here. Native polarity specs flipped on purpose (listed in plan section 3).                                                                          |
| L2    | `e69e7c3d`, `a01389eb`, `01d2466f`                                                 | Shared chat, inbox and notices take the creator look by inject; light labels, floating field labels, disabled primary buttons.                                                                                                                                        | MEASURED: agency walk 0 diffs; flag-off pins green; admin build and unit suite green; hash pins re-pinned with a reason on each line.                                                                                                        |
| L3    | `b7862632`, `4d88b5ef`, `4a57f950`, `25ba492b`, `0c7120cc`, `75766ded`             | Dashboard, assignments, jobs, security, availability, onboarding (native wizard chrome below `md`, web upload prompts), auth link colours, outlined fields transparent, floating label colour.                                                                        | MEASURED: agency walk 126 / 126; real-page screenshots at 1280 and 390, dark and light; CI green on `75766ded`, run [42567604541](https://github.com/pedram-kh/Engine/actions/runs/42567604541).                                             |
| L4    | `b05f7787`, `e850447f`, `ff0f6ddb`, `4e5eecd7`, `4803b7a3`, `2f27ab2b`             | Creator inbox API (flag `messages_v2_creator_inbox_enabled`), web (tags, search, deep link), Notifications page header, the index migration, the day-aware inbox stamps.                                                                                              | MEASURED: Pest SQLite 4613 passed + 36 skipped, Postgres leg 4649 passed at `2f27ab2b`. CI run [42638791357](https://github.com/pedram-kh/Engine/actions/runs/42638791357) green on all four jobs. EXPLAIN on Postgres 16 in the plan, L4.1. |
| L5    | this commit set                                                                    | Playwright specs for the inbox (flag on and off) and the Notifications page, full Playwright, the deliberate creator re-baseline, the agency baseline proof, docs, build 5 number.                                                                                    | Below.                                                                                                                                                                                                                                       |

## Break-reverts

Each is a deliberate break of the guard, a run, then a restore with `git status` clean.

| Break                                                                                      | Result (MEASURED)                                                                                                                                               |
| ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Drop the flag gate in `CreatorMessageInboxController` (both endpoints, `if (false)`)       | `CreatorInboxV2ApiTest`: **1 failed, 13 passed** ("answers 404 not_found on both endpoints while the flag is OFF"). Restored: 14 passed.                        |
| Drop the creator scoping (`t.creator_id` and `a.creator_id` in `CreatorMessageInboxQuery`) | **2 failed, 12 passed** ("never lists another creator's thread, in either table" and "reads a deleted last message as a deleted preview"). Restored: 14 passed. |
| Ignore the `dayAwareStamps` prop on the creator page                                       | `CreatorMessagesPage.unified.spec`: **2 failed, 5 passed** (flag on and off). Restored: 7 passed.                                                               |
| Earlier layers                                                                             | Recorded in their commits and in the L-specific specs (for example the flag-off pins, `creator-css-auth-links`, `OnboardingLayout.native`); not repeated here.  |

The search service was not part of the scoping break (it has its own scope and tests); only the inbox query was.

## Agency 126 / 126: the proof

Claim: no AH-115 file changed any agency render (DOM, computed styles of every element, the Vuetify theme `<style>` bytes), at both widths, dark and light, including a creator-then-agency crossover.

- **MEASURED, at the tip:** `node tools/walk/walk.mjs --set agency --compare` against a recording of the **pre-chunk runtime** (`src` and `packages` at `9a0be3f3`), made on the same machine the same day: **`COMPARE OK: 126 targets identical`**, 0 page errors.
- **The committed baseline drifted, and the cause was found.** The L0 recording stopped matching on 12 targets (the six auth screens at 1280, `styleHash` only), identically on `75766ded` and at the tip, so not this chunk. Per-element dumps of two runs of one tree showed the locale select measuring 67.34 px in one run and 67.67 px in the other: its width depends on which Inter subset files (Latin-extended, Cyrillic, Greek) have arrived at capture time, and `fonts.ready` can resolve before they are requested. **Fixed in the walk tool only** (`open()` requests every weight with a multi-script sample, then waits); the runtime is untouched. The L0 file recorded the incomplete-font state, so the agency baseline was re-recorded with the fixed harness from the **pre-chunk runtime** (`9a0be3f3`); the L0 recording is kept as `baseline-recorded-l0.json`. **MEASURED: the tip compares 126 / 126 identical to that pre-chunk recording, twice in a row.** Details in the agency baseline README. Tech-debt row 24 is closed by this (below).
- READ: `agency-frozen.spec` pins 22 agency files; `agency-theme-isolation.spec` forbids the creator tree reaching `modules/agency/`; `agency-tree-no-creator-class.spec` forbids creator classes in it. The shared files that changed (RelationshipInbox, NotificationCenter, and the L2 shared set) are hash-pinned with a reason, and every change is gated by `useNativeLook()` or an opt-in prop, so the agency DOM is the previous DOM.

## The creator walk, re-baselined on purpose

`docs/reviews/ah-115-creator-baseline/` replaces the AH-114 creator file as the reference. All 142 targets moved against it, by design; the README tabulates the reason per family. Two things worth knowing: 44 targets moved on `styleHash` only (the screens deliberately left unchanged, plus error and impersonation), which is the **progress-bar mask** and nothing else (those screens have the same DOM and the same theme sheet bytes); and the AH-114 file was recorded before the mask existed, so any compare against it will show that. MEASURED on the new file: four consecutive compares, **142 / 142 identical** (it had the same font race; recorded with the fixed harness, README).

For one of those unchanged screens (`auth.forgot-password`, dark, 1280 and 390) I also dumped every element's computed style on the tree before the chunk and on the tip, same harness: **341 of 341 elements identical** (the styleHash is equal on both). So the "unchanged" claim is per element, not only per hash.

## Deviations, each deliberate

1. **Auth-screen scope.** The creator look applies by route name to `auth.sign-in`, `auth.sign-up`, `auth.verify-email.pending` only. The four shared screens an agency user can also reach (2FA, forgot password, reset password, accept invitation) and `/agency/sign-in` stay as they were (plan ruling 1). Follow-up in tech-debt.
2. **Hamburger on mobile web.** Below `md` the onboarding web layout takes the native compact header, but keeps the right-side hamburger and its drawer (account menu, language, sign out): native dropped them in AH-101 U5, web users still need them. READ: `OnboardingLayout.vue`, the `!isNative` branch. The app is unchanged.
3. **Native Roboto fix.** `.text-*` utilities and teleported overlays get Inter in the creator scope. This also changes the native app (it was rendering Roboto there): recorded, wanted, listed in the build 5 walk.
4. **Light derivation.** `creator-light` is derived from the dark theme by one remap block plus a creator-side copy of the colour parity table; web creators who stored "light" now see the derived light theme, not the old Vuetify light. The auth logo has no light variant yet (tech-debt row 21).
5. **Progress-bar mask** in the walk hash (above): one property on one element class; everything else still counts.
6. **`dayAwareStamps` prop** on the shared `RelationshipInbox`: opt-in, the creator page passes it, every other caller keeps the short numeric date. Hash re-pinned with the reason. The creator inbox reads today as a time, this year as "Nov 9", older as "Nov 9, 2025", locale-aware, the same rule as the agency inbox.
7. **Outlined fields lose their fill** in the creator scope (transparent), because the canvas fill straddled the floating label; the agency shell has its own field rules and is unaffected.
8. **Notifications on the native app** keeps the bare card (the native shell has its own header); the page header is for the web.
9. **Unread aggregates scoped** in the creator inbox query (not requested, found by the EXPLAIN): both aggregates read only the creator's own threads.
10. **Bell popover (build 4 walk finding).** `NotificationBell.vue` (creator bell; agency has its own) is gated by `useNativeLook()`: with the look, below md the bell navigates to the Notifications page (no popover; the native app is always below md in practice, so this changes the app: recorded, in the build 5 walk); at md and above the popover stays with creator classes (`creator.css`, header, rows as on the page, "View all" footer) and one scroll container (card `overflow: hidden`, list `overflow-y: auto`, no overflow on the centre). Without the look the DOM is unchanged. Pinned by `NotificationBell.look.spec.ts` and `creator-notification-popover.spec.ts`; break-reverted (card overflow, list overflow). MEASURED: the agency walk is 126 / 126 identical between the tree without and with this change, recorded and compared in the same sitting. The earlier font-race fix was not fully deterministic: against the committed baseline the agency auth screens at 1280 flipped back to the other font state after the bell change, so the same-sitting pair is the proof for this change.

## Playwright (MEASURED)

New: `creator-inbox-v2.spec.ts` (flag on: one list, the Direct and "Brand · Campaign" tags, search by body with the matched term marked, a hit and a campaign row both deep-link to `/creator/assignments/:ulid?focus=messages`; flag off: the previous list, no tag, no search field, and the new endpoint answers 404) and `creator-notifications-page.spec.ts` (title, settings button, an agency-caused row, the settings button opens the preferences). Both green on a real stack.

Full main-SPA suite and admin-SPA suite: results in the adhoc log entry for AH-115 (counts and any selector fixes), run on `catalyst_e2e` with `workers: 1`.

## What is NOT proved

- **Native rendering of build 5** (device): the build 5 walk below. The restyle's native regression surface is the Roboto fix and the inbox/Notifications changes; everything else renders through the unchanged `.catalyst-native` rules.
- **Production behaviour and index use on a production-sized table.** At 300 rows the planner still chooses a seq scan (correctly); the index is proved usable (`enable_seqscan = off`), not proved chosen at scale. Tech-debt row 22 is the watch.
- **Light theme on a real device** (derived, never seen on one).
- **Timezone and locale beyond `en-US` and `Europe/Madrid`** for the inbox stamps (the formatting is `Intl`, so it follows the locale; only `en` is asserted in the spec).

## Build 5 device-walk list

Install build 5 over build 4 (the session must survive), then on a device:

1. **Install over 4:** signed in, no sign-in screen, no onboarding flash.
2. **Dark look unchanged** on dashboard, availability, assignments, onboarding, auth sheets (compare to build 4).
3. **Inter renders** on `.text-*` text and in overlays (menus, dialogs, snackbars): the recorded Roboto fix.
4. **Inbox, flag OFF** (before arming): equal to build 4. **Flag ON:** one list; "Direct" and "Brand · Campaign" tags; search finds a message and marks the term; a campaign row and a hit open the assignment chat; dates read "time / Nov 9 / Nov 9, 2025".
5. **Notifications page:** layout, the settings button, mark all read, a tap opens its target (deep link).
6. **Push, resume, deep links, icon badge:** unchanged (behaviour gates were not touched): receive a push, background and resume, open a universal link, check the badge count.
7. **Chat thread:** safe area at the bottom, keyboard open and close, no jump.
8. **Photo upload** (paperclip and paste), re-checked: the native-origin CORS rule is live (REPORTED 2026-11-09), and build 5 must not regress it.
9. **Share limits (known):** the lightbox share still hands over the signed URL, not the bytes (tech-debt rows 17 and 23).

10. **Bell (build 4 walk finding):** tapping the top-bar bell opens the Notifications page directly (no popover) on the app and on creator web below md. Check the unread badge still shows on the bell.

Deploy order, with the snapshot and the migration, is in the PENDING entry of the deploy log.
