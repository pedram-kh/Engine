# Creator app eyes-on fixes: iOS input zoom, edit-profile form body, frame-07 dashboard pass (AH-100c) — REVIEW

- **Status: Closed — approved.** Verdict (Claude, independent review, 2026-10-09; decision owned by
  Pedram): **B1–B4 verified by Pedram's re-walk** (no zoom after field focus, no clipping anywhere,
  layouts flush, the tab bar pinned, the frame-07 dashboard and the styled Edit profile form body
  landing; screenshots on record with Claude). The **16px deviation (D1) and its ≥16px CSS-parsing pin
  are ratified**; the **parallel-sheet scoping mechanism (D5) and the deliberate scoping-spec edit are
  accepted**; the **D1–D6 deviation list and the ten new strings ×24 are accepted as reported**; the
  **reconstructed findings 4–5 were confirmed accurate by Claude against the originals**; the
  **pre-existing translation-quality debt was correctly logged and left untouched**. This was a
  **conditional clearance** (the AH-090 precedent): the review had to match the build report as
  delivered. **No divergence was found**; the only edits made at close are the status, provenance and
  disclosure lines that this build-time text could not have known.
  Close condition: CI green at the pushed tip (§5.41), the two local load-timeouts (§7) judged there.
- **Commits:** pushed together as `e5beeddc..112e7eb6`: `9f4934e9` (feat) · `112e7eb6` (docs: this
  review, the log entry, the AH-100b post-close addendum, the deploy-log PENDING entry, the tech-debt
  entry, the Part 2 refresh; amended once before the push to carry this close, so its pre-push hash
  `94417362` never reached the remote). A docs-only provenance commit (the one that wrote this
  paragraph) follows them (AH-091 precedent). `git rev-list --left-right --count origin/main...HEAD`
  was `0 0` after the push.
- **CI at the pushed tip (§5.41):** **green — all four jobs** (Backend Pint+Larastan+Pest, Frontend
  lint+typecheck+unit, E2E main SPA, E2E admin SPA) —
  [run `37957556300`](https://github.com/pedram-kh/Engine/actions/runs/37957556300) at `112e7eb6`, **attempt 2** (see the first bullet).
  - **The first attempt was red on the Backend job, one test, in code this chunk did not touch** (zero
    `apps/api` diff): `MembershipsIndexTest > searches members by email substring (case-insensitive)`
    (`expected size 1, got 2`). The test searches `ALICE` and gives the second user a random
    `fake()->name()`; the endpoint also matches the name column, so a random name containing "alice"
    makes it fail. A non-deterministic test, not a regression. Only the failed job was re-run and passed
    (Frontend had passed on attempt 1; both E2E jobs ran on attempt 2). The test is not fixed here
    (it is `apps/api`, outside this chunk's fence); tech-debt carries it with the one-line fix.
  - **The two local load-timeouts (§7) were judged here:** both files, `createRouter` (admin) and
    `roster/pages/CreatorDetailPage.spec.ts`, passed in CI's Frontend job (attempt 1; that job was not re-run, having passed).
  - **Three Playwright flaky retries, all passing on retry:** main `creator-wizard-happy-path.spec.ts`
    (the known pre-existing flake; it traverses a surface this chunk touched, the native-gated
    dropzone copy, which does not render on web) and main `creator-detail.spec.ts:64` ("navigates from
    a roster row…", a roster page this chunk does not touch; **first time recorded in these docs**); admin
    `admin-sign-in.spec.ts` (the TOTP reveal, the known admin flake). Main 31 passed + 2 flaky; admin
    1 passed + 1 flaky. No other spec flaked or failed.
- **Provenance:** eyes-on findings by Pedram (AH-100b's walkthrough), diagnosis and fix proposal by
  Cursor, rulings by Pedram / Claude, built by Cursor, re-walk acceptance by Pedram, independent review
  closure by Claude.
- **Chunk:** Creator App arc, a new entry, **not a reopen of AH-100b** (closed chunks stay closed; AH-100b
  carries a dated post-close addendum pointing here).
- **Scope fences held:** no `apps/api` / `apps/admin` diff, **no new API surface**, no migration, no
  push/camera/deep-links/biometrics (AH-102/103), no sign-up (AH-104b), **no web visual change** (the
  web pin set is green and unedited; every added CSS selector is native-gated, and the two new native
  sheets are registered in the scoping spec). **PROD-DATA RISK: NONE.** No dev database was read or
  altered.
- **Acceptance instrument:** Pedram's re-walk on his own machine, including the reproduction path:
  tap a field, then navigate on (§9).

## 1. The eyes-on findings (AH-100b, recorded here and as a post-close addendum there)

Pedram's walkthrough of AH-100b found five things. Findings 1–3 are one cause; 4 and 5 are scope that
AH-100b did not reach.

1. **Sign-in:** after a field took focus and the keyboard closed, the page stayed zoomed: the hero
   headline's left edge was clipped and the page panned sideways.
2. **Dashboard:** carried over the zoom, "Your application" was clipped on the left, and "0%
   complete" and "Connection requests" lost their first letters.
3. **Tab bar:** the fixed bar was not unpinned but sat outside the visible area, so the tab bar was
   only reachable by scrolling.
4. **Dashboard (frame 07):** the dashboard was the plain web layout. AH-100 used frame 07 only for the
   shell (app bar, tabs); AH-100b's D1 frame list (02–05, 06, 06b, 06c, 09–11, 13, 14) left 07 out, so
   nobody built the dashboard content.
5. **Edit profile (frame 13):** the shell and section cards were native but the **form body** was
   not: the `S8` pass was thin. The form components are shared with the onboarding wizard, whose native
   rules hang off `.onboarding-native__body` and never reach this page.

> **Provenance of this list:** the finding wording was reconstructed from the AH-100b diagnosis session
> (the simulator measurements and the doc/CSS trace), not copied from Pedram's original message, which
> the session transcript does not preserve. Findings 1–3 are exact as measured below; 4 and 5 were
> summarised. **Claude confirmed findings 4–5 accurate against the originals at close (2026-10-09).**

### Root cause of 1–3 (measured on the simulator, iPhone 17 Pro)

- Field text was **14px** (`.catalyst-native .v-field__input … { font: var(--native-type-body) }`,
  Figma's number). iOS WKWebView **auto-zooms any control under 16px** on focus.
- After the keyboard closed: `visualViewport.scale` = **1.1418**, `offsetLeft` 25. 16 / 14 = 1.1428,
  so the zoom is exactly the 14→16 ratio.
- The zoom is **page-level WebView state**: it survives blur and **carries across SPA route changes**
  (sign-in → dashboard), and there was no scrollable overflow anywhere.
- Fixed elements are laid out against the unzoomed box, so the app bar and tab bar sat outside the
  zoomed visual viewport (tab bar `position: fixed`, 784–874 high, but the visible viewport was 765
  high). **Control:** toggling the viewport meta in the live DOM reset the zoom, and the dashboard was
  then flush with the tab bar visible. That is the same finding seen from the other side, not a code
  change.

## 2. What was built

| Id  | What                                                                                                                                                                | Where                                                                                                                                         |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| B1  | Native field text is **16px**; labels stay 14px. New `--native-type-input` token, lockstep in `native.ts`. Pinned by a CSS-parsing spec over every native sheet     | `packages/design-tokens/native.css`, `src/native.ts`, `native-field-text-size.spec.ts`                                                        |
| B2  | Investigated the 6px vertical scroll and a suspected safe-area double count: **no defect, no change** (§3, D6)                                                      | none                                                                                                                                          |
| B3  | The Edit profile **form body** styled against frame 13 in a parallel native sheet; dropzones say "Tap" on native; coverage spec                                     | `creatorProfileFormNative.css` (new, 500 lines), `creator-profile-native-coverage.spec.ts`, `AvatarUploadDrop.vue`, `PortfolioUploadGrid.vue` |
| B4  | The approved dashboard against frame 07: welcome header, approved banner, Profile-strength card, invitation card with "New" pill, requests count, dashed empty card | `creatorDashboardNative.css` (new, 272 lines), `DashboardStrengthCard.vue`, `DashboardInvitationCard.vue`, `CreatorDashboardPage.vue`         |

Every B4 surface is gated on `isNativePlatform()`: on the web the page renders the same DOM as before
(pinned by a web-polarity test, §7). Both new sheets are bound through the existing
`.creator-main--native` root and are registered in `native-page-css-scoping.spec.ts` (a deliberate
edit to that spec, which now covers them).

## 3. Deviations — the complete list, for Pedram's per-item ruling

**D1 — 16px field text instead of Figma's 14px (ratified).** The design was drawn without WebKit's
focus-zoom rule in view; 16px is the canonical fix. **Labels stay 14px.**

- **`maximum-scale=1` was considered and rejected on accessibility grounds:** it blocks pinch-zoom,
  a WCAG 1.4.4 failure. The rejection is also recorded in a comment in `native.css`.
- **For the record:** Pedram may update the Figma inputs to 16px so the design and the build
  re-converge. Until then the build is 2px above the frame on field text.
- The pin asserts the cause, not a symptom (§7).

**D2 — Native dropzone copy.** "Drop here, or click" is wrong on a touch device; the native app says
"Tap to choose a photo" / "Tap to choose files" (two new keys). **This also changes the native onboarding
wizard**, which uses the same two components, not only Edit profile. Web copy is unchanged (pinned).

**D3 — Frame 13 (Edit profile form body).**

1. The country / language / categories **echo block is hidden** (`.profile-basics-form__preview`): not
   in the frame, and it repeats the fields above it.
2. The social **connected-accounts summary list is hidden** (`.social-accounts__connected`): it
   duplicates the tiles' own view mode (the same call the wizard sheet makes).
3. Icons are MDI, not the frame's glyphs (the standing deviation from AH-100).
4. The labels remain Vuetify's outlined-field labels; I did not repaint them as stacked labels. **Not
   verified against the frame by eye.**
5. **Coverage count.** The finding cited 47 uncovered classes; my own count of the classes the page
   renders without a native rule came to 61 (the two numbers differ by counting method, which I did not
   reconcile). Either way the spec now pins zero, with a named allow-list: `NO_VISUAL` (six visually
   hidden or inert classes: `avatar-upload-drop__input`, `portfolio-upload-grid__input`,
   `portfolio-upload-grid__sr-status`, `portfolio-step__sr-status`, `portfolio-step__upload`,
   `master-agreement-terms`) and `PACKAGE_CLASSES` (the portfolio gallery's `portfolio-gallery`,
   `__thumb-wrap`, `__remove`, `__title`, which belong to a shared package).

**D4 — Frame 07 (approved dashboard), where the data shape or the product forced a call.**

1. **"To reach 100%, finish this step: {step}" is step-level, not field-level.** The completeness
   payload exposes the next incomplete **wizard step**, not the next missing field, and this batch
   builds no new API surface. The row names the step, and links to Edit profile **only** for steps
   Edit profile can finish (`profile`, `social`, `portfolio`); other steps (KYC, tax, payout, contract,
   review) are shown as plain text rather than a link that goes nowhere. The row is absent at 100%.
2. **The "New" pill means "an invitation is awaiting your response", not "unseen".** There is no
   seen/unseen signal on an assignment. The pill is keyed on the pending-invitation count and absent at
   zero. If a seen signal is added later the pill should key on it.
3. **The call-to-action reuses the existing "View invitations" string** (`assignments.cta`), full-width
   and 44px tall against the frame's 36px, for the touch-target size. The count title uses a new
   2-form plural key.
4. **Zero invitations:** the card stays (it is the way into the assignments surface) with the existing
   `assignments.title` / `assignments.none` copy and no pill. The frame draws only the populated card.
5. **Approved dashboard only.** The other states (pending, rejected, suspended and so on) are **not**
   redesigned; they keep the web layout inside the native shell.
6. **The requests list-item layout is not verified against the frame** (the empty state is a dashed
   card per frame; the populated list items were restyled from the frame's tokens without a
   reference render).
7. The percent is formatted with `Intl.NumberFormat` in the active locale (so "100 %" in some
   locales), not the frame's literal "100%".
8. The web `CompletenessBar` and the `v-alert` teaser are replaced on native by the two new cards, and
   the aurora rule under the header is dropped natively.

**D5 — The scoping mechanism: a third one.** The wizard's native sheet is rooted at
`.onboarding-native__body`; the Edit-profile page cannot be reached from it. Rather than touch the
verified wizard sheet (and risk its pins) I added a **parallel sheet** rooted at `.creator-main--native`.
Two sheets now style the same shared components, in two scopes. **Tech debt:** unify them under one
shared root class (tech-debt, AH-100c). `native-page-css-scoping.spec.ts` was **deliberately edited** to
register both new sheets, with the same checks (rooted, no at-rules, no literal colours).

**D6 — B2: no defect found, nothing changed.** The 6px vertical scroll was suspected of a safe-area
double count. From source: `--v-layout-top/bottom` carry Vuetify's registered bar heights (64/56px),
and the native rules add the safe-area insets on top of them for both the bars and `<v-main>`
(`.creator-main--native { padding-top: calc(var(--v-layout-top) + var(--native-safe-top)) … }`); the
bottom bar is `56px + inset` tall and the top bar adds the inset as padding, so the arithmetic is
consistent on paper. **I did not measure the 6px again** (the Safari Inspector window was gone and I did
not re-take the desktop), so I cannot say what it was. It may go away with B1, since the zoom was
distorting every measurement on the page. **Re-measure it in the re-walk (§9).**

## 4. New user-facing strings — all ten, ×24 locales

The zero-new-strings posture was AH-100b's circumstance, not a rule. Each key below exists in all 24
locale files (`apps/main/src/core/i18n/locales/*/creator.json`) with a **real machine translation**.
**Flaky-10 (bg, el, et, fi, ga, hu, lt, lv, mt, ro) script-checked:** a script confirmed all ten keys
are present in all 23 non-English locales and that **none equals the English value** in any of them;
`scripts/i18n/verify-locale.mjs` passes for each of the ten (keyset, `{named}` placeholders, plural form
count), and the parity and used-keys architecture specs are green (§7).

| #   | Key                                               | English                                                                                  | Note                                                                                            |
| --- | ------------------------------------------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 1   | `creator.ui.dashboard.native.welcome`             | Welcome, {name}                                                                          | `{name}`                                                                                        |
| 2   | `creator.ui.dashboard.native.subtitle`            | Here's what needs your attention today.                                                  |                                                                                                 |
| 3   | `creator.ui.dashboard.native.approved_body`       | You can now receive brand collaborations. More of your creator dashboard is coming soon. | The approved banner body                                                                        |
| 4   | `creator.ui.dashboard.native.strength.title`      | Profile strength                                                                         |                                                                                                 |
| 5   | `creator.ui.dashboard.native.strength.next`       | To reach 100%, finish this step: {step}                                                  | `{step}` is the translated wizard step name; colon form so the step name never needs inflecting |
| 6   | `creator.ui.dashboard.native.invitations.title`   | {count} campaign invitation \| {count} campaign invitations                              | 2-form plural (house convention); `t(key, count)`                                               |
| 7   | `creator.ui.dashboard.native.invitations.waiting` | Waiting for your response                                                                |                                                                                                 |
| 8   | `creator.ui.dashboard.native.invitations.new`     | New                                                                                      | The pill                                                                                        |
| 9   | `creator.ui.upload.avatar_prompt_native`          | Tap to choose a photo                                                                    | Native dropzone (also the native wizard)                                                        |
| 10  | `creator.ui.upload.portfolio_prompt_native`       | Tap to choose files                                                                      | Native dropzone (also the native wizard)                                                        |

**Plural note:** the key keeps the house 2-form `a | b` shape in every locale (the form-count parity
gate). In **11 locales (cs, ga, hr, hu, lt, lv, mt, pl, ro, sk, sl)** both slots carry the same string,
in a count-neutral form (for example `Pozvánky do kampaní: {count} | Pozvánky do kampaní: {count}`),
because a two-slot singular/plural pair cannot cover those languages' plural categories and an
incorrect pair would be worse than a neutral one. A native speaker may want to refine these. Locale JSON round-trips
byte-clean with `ensure_ascii=False, indent=2` plus a trailing newline.

### A finding about the existing translations (not fixed here)

While auditing I counted existing `creator.json` values in the flaky-10 locales that are **identical to
English** (strings over 3 characters, excluding the ten new keys): **bg 188, el 206, et 208, fi 207,
ga 205, hu 206, lt 207, lv 207, mt 212, ro 210** out of 424 keys. Many may be legitimate (brand names,
loanwords, tokens), but that is far above the "only three EN-identical values" (all the loanword "Blog") that
`ui-batch-post-arc-inventory.md` found in an earlier audit of another namespace, so it needs a value audit. Separately, **hr, sk and sl** have 131, 134 and 131 values
identical to **Czech** (and not to English): possibly legitimate cognates, possibly a copied
translation. **I measured identity only; I did not judge either set**, and neither was touched. See
tech-debt.

## 5. Decisions carried (from the clearance)

- **16px inputs: yes**, as D1; labels 14px; `maximum-scale=1` rejected.
- **New strings allowed for this batch**, each with real MT ×24 and listed (§4).
- **New chunk, not a reopen;** AH-100b gets a dated post-close addendum.
- **B1–B4 ratified.** B3's scoping fix updates the scoping spec deliberately (D5). B4's two
  sub-rulings: the reach-100 row (the payload exposes the next incomplete step only: built step-level
  and listed, D4.1) and the "New" pill (no unseen signal: keyed on a pending invitation, D4.2).
- **No new API surface,** no push (held).

## 6. Not built / out of scope

- A field-level "add X to reach 100%" row (needs the completeness payload to expose the next missing
  **field**: API work, tech-debt).
- A seen/unseen signal for invitations.
- Non-approved dashboard states in the frame-07 style.
- Unifying the two native form sheets under one root class.

## 7. Evidence

### Break-reverts (mutate, capture the red, restore, sha256 identical, re-green)

| #    | Artifact                       | Mutation                                                                    | Red (exact)                                                                                                                                                                          |
| ---- | ------------------------------ | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| BR1a | `native.css`                   | the field rules go back to the 14px body token (the original cause)         | `native-field-text-size`: every field-text rule ≥16px — 7 offending rules named (`.v-field__input`, `… input/select/textarea`, `.catalyst-native input/select/textarea` → 14px/20px) |
| BR1b | `native.css`                   | `--native-type-input` set to 14px                                           | 2 — every field-text rule ≥16px; the type-input token itself ≥16px                                                                                                                   |
| BR1c | `creatorDashboardNative.css`   | a `.dash-x .v-field__input` rule at 15px added                              | 1 — names `creatorDashboardNative.css: … .dash-x .v-field__input → 15px` (the pin covers the per-area sheets, not only the token sheet)                                              |
| BR2a | `creatorProfileFormNative.css` | an unrooted `.stray`, a bare at-rule and a literal colour added             | 3 — roots every selector; no at-rules; no literal colours (the **scoping-spec update** is live)                                                                                      |
| BR2b | `creatorDashboardNative.css`   | `.dash-banner` loses its `.creator-main--native` root                       | 1 — roots every selector (names `.dash-banner`)                                                                                                                                      |
| BR3a | `creatorProfileFormNative.css` | the `social-card__head` rule removed                                        | 1 — the coverage spec names `social-card__head` as rendered with no native rule                                                                                                      |
| BR4a | `DashboardInvitationCard.vue`  | the "New" pill rendered at count 0 (`v-if` dropped)                         | 1 — "with no invitations: the card stays (the way in), without the pill" (log: `br4a.red.log`)                                                                                       |
| BR4b | `CreatorDashboardPage.vue`     | the strength card shown whenever approved (also on the web)                 | 1 — web polarity: "approved: v-alert banner, CompletenessBar, the original teaser, no native markup"                                                                                 |
| BR4c | `CreatorDashboardPage.vue`     | the requests-count pill rendered on the web too (`v-if="isNative"` dropped) | 1 — the same web-polarity test                                                                                                                                                       |
| BR4d | `DashboardStrengthCard.vue`    | `kyc` added to the linkable steps                                           | 1 — "renders a step the Edit profile page cannot finish as plain text, not a link"                                                                                                   |
| BR5  | `AvatarUploadDrop.vue`         | the prompt key forced to the native copy on every platform                  | 1 — "web: the drag-and-drop copy is unchanged"                                                                                                                                       |

BR1–BR3 reds, restored originals and re-green logs are in `/tmp/br/`; BR4–BR5 in `/tmp/br2/`
(scratch, not committed). **All restored files are byte-identical (sha256), and `git status` was clean
after the last restore.** One mutation survived on the first pass: the "with no invitations" test in B4
passed against the web markup, because it only looked for the absence of the pill. It now also asserts
the native card's class (`dash-invites`), and BR4a then went red as shown.

### Specs added or edited

- **New:** `native-field-text-size.spec.ts` (non-vacuous: it must find field-text rules, then every
  one ≥16px, the token ≥16px, labels still 14px), `creator-profile-native-coverage.spec.ts`,
  `CreatorDashboardPage.native.spec.ts` (native and web polarity), `DropzonePrompts.native.spec.ts`.
- **Edited deliberately:** `native-page-css-scoping.spec.ts` (both new sheets registered, D5).
- **Unedited:** the web pin set, `CreatorDashboardPage.spec.ts` included.

### The board

Run at the feat commit `9f4934e9`, after the docs were written (the docs are the only later change):

- `typecheck:frontend`: clean (vue-tsc across `apps/main`, `apps/admin` and the packages). A first
  run mid-build reported `TS2339` in my own `CreatorDashboardPage.native.spec.ts`
  (`props` on `WrapperLike`); fixed with `findAllComponents(RouterLinkStub)`, and the final run is clean.
- `lint:frontend`: 0 errors, 2 pre-existing `v-html` warnings.
- **Unit:** design-tokens 40 (+1 todo), api-client 221, ui 43, **admin 488**, **main 1973 in 198 files**.
  - **Two timeouts under load, neither in a file this chunk touched.** Run all together
    (`pnpm test:frontend`), `apps/admin` `createRouter > builds a router whose beforeEach resolves the
store…` timed out at 5000ms (the suites run in parallel and Docker was using the machine); **admin
    alone: 58 files, 488 tests, green.** `apps/main` alone then had one timeout, `roster/pages/
CreatorDetailPage.spec.ts > keeps add-to-pool for every status EXCEPT ended` (5788ms), **22/22
    green alone.** An earlier full `apps/main` run in this chunk was 198 files / 1973 tests, all green.
    Both are the cold-machine timeout shape, not logic; CI at the tip is the real check.
- Architecture specs (including `i18n-locale-parity` and `i18n-used-keys-resolve`) are inside the main
  run and green. `verify-locale.mjs` passes for each of the flaky-10.
- **Native build:** `build:native:dev` (`vue-tsc --noEmit` then `vite build --mode native-development`)
  exits 0; output goes to the gitignored `dist/`.
- `format:check` over the changed files and `docs/`: clean except `docs/reviews/sprint-4-chunk-2-review.md`,
  which was already unformatted before this chunk (AH-100b recorded the same).
- **Not run:** Playwright (no web behaviour changed; the dashboard and Edit profile web DOM is pinned
  by unit tests), and the backend suites (zero `apps/api` diff).

## 8. Disclosures (one line each)

- **At build time no simulator look followed the fix** (everything visual was CSS reasoning and pins;
  the Safari Inspector window from the diagnosis session had closed and I did not re-take the
  desktop). **Pedram's re-walk then verified the batch on his own machine** (screenshots on record
  with Claude). The 6px scroll (D6) is not separately recorded as re-measured, only that layouts
  were flush; it stays on the §9 list as an open observation.
- **`pymobiledevice3` was installed for the diagnosis and is uninstalled.** A booted iPhone 17 Pro
  simulator and any Safari windows may remain on the machine.
- **The diagnosis signed the simulator's session out once and moved windows on the desktop** (noted and
  accepted at clearance).
- **BR1–BR3 come from the earlier build pass; BR4–BR5 were re-run in this final pass** (an earlier
  batch run of the B4 mutations was blocked by the tool's auto-review, so they were redone one at a time).
- **The AH-100b finding text was reconstructed (§1); findings 4–5 are now confirmed against the originals.**
- **No dev database was read or altered; no passwords were reset.**

## 9. Rebuild steps and Pedram's re-walk (acceptance — done, 2026-10-09)

```
pnpm --filter @catalyst/main build:native:dev
cd apps/main && pnpm cap sync
```

Relaunch on both platforms, then:

1. **The reproduction path (the acceptance test for B1):** on sign-in tap the email field, then the
   password field, dismiss the keyboard, sign in, and **navigate between the tabs.** The page must not
   be left zoomed, the headline and "Your application" must not be clipped, and the **tab bar must stay
   in view without scrolling.** Repeat on Edit profile and in the wizard.
2. **Dashboard (frame 07):** welcome header, approved banner, Profile-strength card (and its next-step
   row: link on profile/social/portfolio, plain text otherwise), invitation card with and without the
   "New" pill, the requests count and the dashed empty card. **Re-measure the vertical scroll** (D6).
3. **Edit profile (frame 13):** every section, the dropzone copy ("Tap to choose …"), and the master
   agreement card.
4. **The pages:** sign-in, the wizard (every step, especially the two dropzones), notifications,
   invitations, messages, job posts.
5. Rule on each item in §3 (D1–D6).
