# UI batch (post-AH-059 arc) — Step 1 change inventory

Read-only inventory. No code edits, no commits, no push. Step 2 follows review.

> **This is a close-time snapshot of the batch as it stood at `ebcc50a`, not a live claim** — the
> same convention the AH log's `Status:` line follows. Read it as the record of what the close-out
> found. **Step 2 then acted on it**, and three of its findings are now closed:
>
> - **§5 / §7.1 — the pixel's E2E blast radius is FIXED** (`2153e9e`). An auto-applied Playwright
>   fixture aborts both Meta endpoints in **both** suites and asserts nothing reached them, and an
>   architecture spec pins that every spec takes `test` from that fixture. Where §5 says "nothing in
>   `playwright.config.ts` or the fixtures intercepts or blocks external requests," that is now
>   false by design.
> - **§3 / §7.2 — the unpinned token contract is PINNED** (`63f1d8d`), by the tripwire §7.2 proposed:
>   every consumer of `--auth-glow-gradient` must declare `opacity` in the same rule, consumers
>   discovered by source-scanning both SPAs. Break-revert executed on the admin consumer.
> - **§7.4 — the 5.6 MB PDF is now a logged tech-debt entry** with a CDN target
>   (`catalyst-engine-public-prod`) and an escalation trigger.
>
> **One correction to §5's own correction.** §5 says "20 spec files exist — **not 27**, worth
> correcting since the brief assumed 27." The file count is right, but **27 was never wrong**: it is
> the **test** count in `apps/main` (27 tests across 18 files, per `playwright test --list`), which
> is also what `RESUMPTION-TEMPLATE.md` means by "27 specs." With `apps/admin` the suite is **29
> tests across 20 files**. Both numbers were real; they count different things.
>
> Playwright was **not** executed for this inventory (§5's exposure was static analysis). It was
> executed in Step 2 — see the AH-060…AH-064 gate lines.

**Base SHA:** `94088e380f6284036daf1137c32b6c8e77d32bef` — this is `origin/main`, and also
`git merge-base origin/main HEAD`, and also the AH-059 arc-close commit
(`docs(jobs-board): close AH-059 — chunk 5 review approved, the arc ends`). All three
coincide, so the range below is exactly this batch with nothing inherited.

**Range:** `94088e3..HEAD` (`ebcc50ae004c2dacde9d91d297e653769693afe3`)
**Position:** 8 ahead / 0 behind `origin/main`. Unpushed.
**Working tree:** clean — `git status --short` returns nothing.
**Totals:** 57 files, +2670 / −15.

---

## 1. Commit list

`git log --oneline 94088e3..HEAD` (oldest first):

| SHA       | Subject                                                                              | What it did                                                                                                                                               |
| --------- | ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `8081435` | `feat(creators): answer a campaign invitation from its detail page`                  | Adds the accept/decline pair to the creator's assignment detail page, so an invitation opened for reading can be answered without going back to the list. |
| `877e81b` | `feat(boards): review hand-off from the card drawer's Draft-submitted row`           | Adds a Review button to the board card drawer's `draft_submitted` timeline row that sends the operator to the campaign's Drafts tab.                      |
| `ee8a917` | `fix(boards): let tall card stacks scroll instead of squashing the cards`            | One CSS declaration per column component so flex children stop shrinking to fit and the existing scroll actually engages.                                 |
| `ceb15f0` | `feat(auth): creator-guide CTA and marketing footer on the sign-in landing`          | The rebrand landing's marketing tail: guide CTA block, site footer, 12 i18n keys across 24 locales, new design tokens.                                    |
| `6d970a8` | `chore(auth): add the creator guide PDF served by the sign-in landing`               | The 5.6 MB `creator-guide.pdf` the CTA opens (compressed from 12 MB).                                                                                     |
| `f62529e` | `fix(auth): seat the footer monogram in flow and link out to the marketing site`     | Eyes-on fixes: monogram moved into flow, glow seam removed, wordmark alignment, mobile hide, real marketing-site URLs.                                    |
| `6ddad53` | `feat(auth): rebuild the footer monogram with the live site's glass card and motion` | Rebuilds the monogram SVG to match catalyst-growth.com, with orbit/sheen animation and cursor-driven tilt.                                                |
| `ebcc50a` | `feat(auth): load the Meta Pixel on the sign-in page only`                           | Meta Pixel loader, mounted from `SignInPage` alone; tech-debt entry for the consent gap.                                                                  |

---

## 2. Theme grouping — five candidate AH entries

AH-059 is the highest number on record, so these are AH-060 through AH-064.

### AH-060 — Answer a campaign invitation from its detail page

**Why:** A creator who opened an invitation to read it had to navigate back to the list to answer it.
**What:** Renders the same accept/decline pair the flat list offers, gated on `status === 'invited'`, hitting the same `creatorAssignmentsApi.accept` / `.decline` endpoints with the same copy and toasts. One `answering` flag drives both buttons so a double-answer is impossible in flight. Mobile splits the pair across the width, as the list does.
**Touched (apps/main):**

- `src/modules/creators/pages/CreatorAssignmentDetailPage.vue`
- `src/modules/creators/pages/CreatorAssignmentDetailPage.spec.ts`

**Commits:** `8081435`

### AH-061 — Review hand-off from the board card drawer

**Why:** The drawer showed a draft had been submitted but offered no way to act on it.
**What:** A Review button on the `draft_submitted` timeline row, shown only with the `review` ability, emitting a payload-free navigation hand-off that `CampaignDetailPage` turns into a Drafts-tab switch. Deliberately a navigation hand-off, not a write. Reuses the existing `review` ability and the existing `app.campaigns.review.action` key — no new ability, no new key.
**Touched (apps/main):**

- `src/modules/boards/components/BoardCardDrawer.vue`
- `src/modules/boards/components/BoardCardDrawer.spec.ts`
- `src/modules/boards/components/BoardView.vue` (new `canReview` prop, `review` emit)
- `src/modules/campaigns/pages/CampaignDetailPage.vue` (`onBoardReview` → `tab = 'drafts'`)
- `src/modules/campaigns/pages/CampaignDetailPage.spec.ts`

**Commits:** `877e81b`

### AH-062 — Tall card stacks scroll instead of squashing

**Why:** Past a screenful, columns compressed their cards to fit and the content was silently clipped by `.board-card`'s own `overflow: hidden`, so cut-off cards were useless.
**What:** `flex: 0 0 auto` on the list's children in both column components. The scroll container already existed; flex children were shrinking to fit and defeating it. Pure CSS, no script or template change.
**Touched (apps/main):**

- `src/modules/boards/components/BoardColumn.vue`
- `src/modules/boards/components/BoardApplicationsColumn.vue`

**Commits:** `ee8a917`

### AH-063 — The sign-in landing's marketing tail

**Why:** The rebrand Figma gave the login page a creator-guide block and the marketing site's footer; neither existed.
**What:** Two sibling components rendered only in hero mode (`CreatorGuideCta`, `AuthMarketingFooter`), an interactive monogram (`AuthFooterMonogram`) rebuilt from the live site's SVG with orbit/sheen animation and cursor-driven tilt, a link table (`footerLinks.ts`) pointing at catalyst-growth.com, the guide PDF, 12 i18n keys across 24 locales, and three new design tokens. Tilt maths extracted to `monogramTilt.ts` to keep it unit-testable.
**Touched (apps/main):**

- `src/modules/auth/layouts/AuthLayout.vue`
- `src/modules/auth/components/` — `AuthMarketingFooter.vue`(+spec), `CreatorGuideCta.vue`(+spec), `AuthFooterMonogram.vue`(+spec), `footerLinks.ts`(+spec), `monogramTilt.ts`(+spec)
- `src/modules/auth/assets/catalyst-monogram.svg`, `assets/guide/guide-card-{1,2,3}.webp`
- `public/creator-guide.pdf`
- `src/core/i18n/locales/{24 locales}/auth.json`
- `tests/unit/architecture/auth-layout-shape.spec.ts` (ceiling raise — §6)

**Touched (apps/admin):**

- `src/modules/auth/layouts/AuthLayout.vue` (compensating `opacity: 0.3` — see §3 fan-out)

**Touched (packages):**

- `packages/design-tokens/tokens.css`

**Commits:** `ceb15f0`, `6d970a8`, `f62529e`, `6ddad53`

### AH-064 — Meta Pixel on the sign-in page

**Why:** Pedram asked for the Meta Pixel on the login page.
**What:** A queueing loader mounted from `SignInPage` only — never `index.html`, never the layout — because the pixel reports the full document location and the sibling auth routes carry single-use tokens in their query strings. Automatic Advanced Matching disabled before `init` so the login form's email field isn't harvested. Fires with no consent gate; ID hardcoded. Both accepted deliberately and logged.
**Touched (apps/main):**

- `src/modules/auth/internal/metaPixel.ts` (new) + `metaPixel.spec.ts` (new)
- `src/modules/auth/pages/SignInPage.vue`, `SignInPage.spec.ts`

**Touched (docs):**

- `docs/tech-debt.md`

**Commits:** `ebcc50a`

---

## 3. Scope verification (evidence)

### i18n keyset — NON-EMPTY

`git diff 94088e3..HEAD --stat -- '**/locales/**' 'apps/api/lang/**'` → **24 files, +432, −0**.
All 24 are `apps/main/src/core/i18n/locales/<locale>/auth.json`, 18 lines each. No `apps/api/lang/**` change.

**12 keys added. 0 removed. 0 changed.** Diffed as flattened key-paths against the base, not by eyeballing the patch:

```
+ auth.ui.guide.heading
+ auth.ui.guide.body
+ auth.ui.guide.cta
+ auth.ui.footer.nav.home
+ auth.ui.footer.nav.about
+ auth.ui.footer.nav.services
+ auth.ui.footer.nav.case_studies
+ auth.ui.footer.nav.contact
+ auth.ui.footer.nav.resources
+ auth.ui.footer.nav.blog
+ auth.ui.footer.nav.international
+ auth.ui.footer.privacy
```

**Parity:** all 24 locales carry all 12 keys. Machine-checked per locale, not inferred from the equal line counts.

**Flaky-10 value audit** (bg, el, et, fi, ga, hu, lt, lv, mt, ro) — values compared against English, not just presence:

| Locale | EN-identical | Locale | EN-identical |
| ------ | ------------ | ------ | ------------ |
| bg     | 0/12         | lt     | 0/12         |
| el     | 0/12         | lv     | 0/12         |
| et     | 0/12         | mt     | 1/12         |
| fi     | 0/12         | ro     | 1/12         |
| ga     | 0/12         | hu     | 1/12         |

The only three EN-identical values are `auth.ui.footer.nav.blog = "Blog"` in hu, mt and ro. "Blog" is the correct word in all three languages — a loanword, not an untranslated fallback. **No English fallback leaked into the flaky 10.**

**Reused keys (rendered but not added — checked because a missing one shows the raw key path to the user):** all six resolve in all 24 locales.

| Key                                     | en value                                  |
| --------------------------------------- | ----------------------------------------- |
| `app.campaigns.review.action`           | "Review"                                  |
| `creator.ui.assignments.accept`         | "Accept"                                  |
| `creator.ui.assignments.decline`        | "Decline"                                 |
| `creator.ui.assignments.toast.accepted` | "Invitation accepted."                    |
| `creator.ui.assignments.toast.declined` | "Invitation declined."                    |
| `creator.ui.assignments.toast.error`    | "Something went wrong. Please try again." |

### API / resource shape — EMPTY

`git diff` over `apps/api/**/Resources/**`, `**/Controllers/**`, `**/Requests/**`, `apps/api/routes/**`, `packages/api-client/src/**` → no output. **`apps/api/**` is untouched in its entirety\*\*, so no field, resource shape, or contract moved. The batch consumes existing endpoints only.

### Gates / policies / guards / middleware — EMPTY

No diff under `Policies/`, `Middleware/`, `Gates/`, `Guards/`, `**/guards.ts`, `*Policy*`, `*Guard*`.

One UI-level gating decision worth naming even though it changes no gate: `BoardCardDrawer`'s `showReviewAction` is `canReview === true && status === 'draft_submitted'`. `CampaignDetailPage` passes `:can-review="canReview"` — the same `review` ability already behind `canResolve`. An existing ability reused on a new surface, not a new one.

### Schema — EMPTY

No new or modified migrations. Nothing under `apps/api/database/migrations/**`.

### Route table — EMPTY

No diff to any `**/routes.ts` or `**/router/**` in either SPA. The AH-024 precedent does not apply. AH-061's Drafts hand-off is an in-page tab switch (`tab.value = 'drafts'`), deliberately not a route.

### Shared components — ONE, with fan-out

`packages/**` diff is exactly one file: `packages/design-tokens/tokens.css` (+35/−5). No `packages/ui` change, no `packages/api-client` change.

Three tokens moved:

- **`--auth-glow-gradient` — semantics changed, not just values.** Alpha was baked in at `0.2`; it is now the aurora at full strength and **consumers must dim it themselves with `opacity: 0.3`**. Both references (Figma's 70%-black overlay, and catalyst-growth.com's own band) agree on 30%, so the pre-fix `0.2` was also wrong against the comment claiming 30%.
- `--auth-glow-mask` — new, the footer bloom's radial falloff.
- `--auth-hairline` — new, the footer divider and guide-card edge.

**Fan-out verified complete.** Every consumer of `--auth-glow-gradient` in the monorepo, and all three carry the compensating `opacity: 0.3`:

| Consumer                                                            | Dimmed?                          |
| ------------------------------------------------------------------- | -------------------------------- |
| `apps/main/src/modules/auth/layouts/AuthLayout.vue:97`              | yes, `opacity: 0.3`              |
| `apps/admin/src/modules/auth/layouts/AuthLayout.vue:90`             | yes, `opacity: 0.3` (this batch) |
| `apps/main/src/modules/auth/components/AuthMarketingFooter.vue:145` | yes, `opacity: 0.3`              |

`--auth-glow-mask` and `--auth-hairline` have one and two consumers respectively, all inside the new components.

**Gap for §7:** nothing pins the new "token is full-strength, consumer dims it" contract. `aurora-surfacing.spec.ts` only asserts the variable is _referenced_ and that no raw hex appears in the SFC; `packages/design-tokens/src/tokens.spec.ts` does not mention any `--auth-*` token. A fourth consumer that forgets `opacity` renders the aurora ~5× too bright and every gate stays green.

### Arc-pinned surfaces — THREE TOUCHED, all pins green

| Arc surface                                                                            | Touched?                           | Evidence                                                                                                                                   |
| -------------------------------------------------------------------------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Jobs-board predicate (`Campaign::scopeListedOnJobsBoard`, `JobsBoardVisibility`)       | **No**                             | backend untouched                                                                                                                          |
| Listing gate (`CampaignPolicy::update`, `ValidatesJobsBoardListing`, single-key PATCH) | **No**                             | backend untouched; `CampaignListPage.vue` untouched                                                                                        |
| D5 mapping (`JobLifecycleState::fromAssignmentStatus`, `assignment_state`)             | **No**                             | `apps/api/.../JobLifecycleState.php` untouched; SPA holds no derive function                                                               |
| Application endpoints / dialogs                                                        | **Partly** — consumed, not changed | `CreatorAssignmentDetailPage` now calls the existing `accept`/`decline`; `RejectApplicationDialog` and `useCampaignApplications` untouched |
| The board column                                                                       | **Yes**                            | `BoardColumn.vue`, `BoardApplicationsColumn.vue` (CSS only), `BoardView.vue`, `BoardCardDrawer.vue`                                        |

Pins covering what this batch touched, all green in the HEAD run (§6):

| Pinned test                                                                                | Locks                                                                                                                           | State                          |
| ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| `boards/components/BoardApplicationsColumn.spec.ts`                                        | D4 pending-only fetch, `pending_total`, no draggable/drag handle, shared reject dialog, dual refetch                            | pass                           |
| `boards/components/BoardColumns.spec.ts` (`— the Applications pseudo-column (AH-059, D4)`) | pseudo-column is first child of the scroll root, outside both draggable groups, absent from the reorder payload, no drag handle | pass                           |
| `boards/components/BoardView.spec.ts`                                                      | hosts the pseudo-column and hands it the invite ability; staff sees it with `canAct: false`                                     | pass                           |
| `boards/components/BoardCardDrawer.spec.ts`                                                | drawer read surface + Resolve/Review actions                                                                                    | pass                           |
| `campaigns/pages/CampaignDetailPage.spec.ts`                                               | includes the D3 listing-floor mirror                                                                                            | pass                           |
| `creators/pages/CreatorAssignmentDetailPage.spec.ts`                                       | accept/decline endpoint calls and reload                                                                                        | pass                           |
| `tests/unit/architecture/listing-floor-parity.spec.ts`                                     | the five floor fields, same order both sides                                                                                    | pass                           |
| `apps/api/.../JobLifecycleStateTest.php`                                                   | D5 exhaustiveness over all 16 statuses                                                                                          | not re-run — backend untouched |

The two CSS-only board changes are the closest call in the batch: `BoardApplicationsColumn.vue` **is** the D4 pseudo-column. The change adds one `flex` declaration to its list children and touches neither the pending predicate, the ability plumbing, nor the drag exclusion the D4 pins protect.

---

## 4. Stop-gate log

| #   | Flag                                                                                            | When                               | Resolution                                                                                                                                                                                                                                                                                                       |
| --- | ----------------------------------------------------------------------------------------------- | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **New i18n keys** — an explicit Mode A trigger, reached while building the marketing tail       | mid-batch, before writing the keys | Raised; **Pedram granted an explicit exception** to proceed inside the fast batch. 12 keys, translated across 24 locales, parity verified (§3).                                                                                                                                                                  |
| 2   | **`AuthLayout.vue` size ceiling** — the two new blocks pushed the layout past `MAX_LINES = 215` | mid-batch                          | Not a listed trigger, but flagged rather than silently bumped. Ceiling raised 215 → 240 with a chunk-scoped note in the spec, as the spec's own text requires. Both blocks are sibling components with their own coverage; the layout gained only imports, two `v-if` tags and spacing.                          |
| 3   | **Meta Pixel — "anything security-relevant"**                                                   | before any code was written        | **Stopped and did not build.** Declared `PROD-DATA RISK: NONE`, then surfaced the token-leak finding, the Advanced Matching harvest, and the conflict with the project's own consent/CSP/SRI commitments. Pedram chose: ship un-gated on `/sign-in` only, hardcoded ID, tech-debt entry. Built to that decision. |

### Self-reported — should have been flagged and wasn't

**(a) The shared-token semantics change.** `--auth-glow-gradient` losing its baked-in alpha is a contract change in `packages/design-tokens`, consumed by **both** SPAs. It was treated as part of the footer work and never flagged as cross-app fan-out. The outcome is correct — all three consumers were updated and verified (§3) — but the _check_ happened during this close-out rather than at build time, and the admin SPA's aurora depended on someone remembering. Nothing pins the new contract.

**(b) The Meta Pixel's E2E blast radius.** Reported in §5. The consent shortcut was flagged and logged; the fact that **all 18 apps/main Playwright specs would begin making live outbound requests to Meta on every run** was not part of that conversation. It should have been, since it changes CI behaviour for the whole suite rather than one page.

Neither is a production-data risk. Both are scope facts that belonged in a flag.

---

## 5. Playwright exposure

20 spec files exist (18 `apps/main` + 2 `apps/admin`) — **not 27**, worth correcting since the brief assumed 27.

| Theme                    | Touches E2E-traversed territory? | Which                                                                                                                                      |
| ------------------------ | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| AH-060 invitation answer | **No**                           | `/creator/assignments/:ulid` detail is not visited by any spec; `jobs-board-full-lifecycle` accepts from the **list**, not the detail page |
| AH-061 review hand-off   | **Yes**                          | `jobs-board-full-lifecycle.spec.ts` (step 4 opens the Board tab and the card drawer), `campaign-applications.spec.ts`                      |
| AH-062 column scroll     | **Yes**                          | same two specs — CSS-only, but the board layout is traversed                                                                               |
| AH-063 marketing tail    | **Yes, broadly**                 | the sign-in landing's hero mode is the first screen of **18/18** main specs                                                                |
| AH-064 Meta Pixel        | **Yes, broadly**                 | same 18 specs                                                                                                                              |

**The finding worth a reviewer's time:** all 18 `apps/main` specs call `page.goto('/sign-in')`, and nothing in `playwright.config.ts` or the fixtures intercepts or blocks external requests. So from this batch onward, **every main-app E2E run loads `connect.facebook.net/en_US/fbevents.js` and registers the production pixel, once per spec.** Consequences: CI traffic lands in the production pixel (the tech-debt entry covers localhost and staging but not this multiplier), and each spec gains a third-party network dependency it did not have before — a latency cost at best, a new flake source if CI egress is slow or filtered. The admin specs are unaffected; the pixel is only in `apps/main`.

No E2E spec files were modified by this batch (`tests/e2e/**`, `playwright/**` diff is empty). Playwright was not executed for this inventory — the exposure above is static analysis.

---

## 6. Gates at HEAD

| Gate                                         | Result                                                                                                                                                                |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/main` Vitest (full)                    | **148 files / 1431 tests passed**, 167 s. No failures, no flake this run.                                                                                             |
| `apps/admin` Vitest (full)                   | **53 files / 449 tests passed**, 72 s.                                                                                                                                |
| `apps/main` typecheck (`vue-tsc --noEmit`)   | clean                                                                                                                                                                 |
| `apps/admin` typecheck (`vue-tsc --noEmit`)  | clean                                                                                                                                                                 |
| `apps/main` lint (`eslint src tests`)        | 0 errors, 2 warnings — both pre-existing `vue/no-v-html` in `onboarding/ClickThroughAccept.vue` and `onboarding/ProfileBasicsForm.vue`, neither touched by this batch |
| `apps/admin` lint                            | clean                                                                                                                                                                 |
| Prettier (`tokens.css`, `tech-debt.md`)      | clean                                                                                                                                                                 |
| Locale parity (`i18n-locale-parity.spec.ts`) | **4 tests passed** (70 s) — inside the main run                                                                                                                       |
| `aurora-surfacing.spec.ts`                   | 8 tests passed (main), 2 passed (admin)                                                                                                                               |
| `listing-floor-parity.spec.ts`               | 2 tests passed                                                                                                                                                        |
| Pest                                         | **not run — `apps/api/**` is untouched\*\*                                                                                                                            |
| Pint / PHPStan                               | **not run — no PHP in the range**                                                                                                                                     |

### Specs changed to stay green

**One.** `apps/main/tests/unit/architecture/auth-layout-shape.spec.ts` — `MAX_LINES` raised **215 → 240**.
Why: the marketing tail added two imports, two `v-if="isHero"` tags and the spacing rules that place them (including the negative margin letting the footer bleed past the 24 px gutter). The spec's own docblock requires a chunk-scoped note on any raise, and one was added naming the Figma nodes. No `<script setup>` logic was added — `isHero` is still the only computed, so the no-function and no-multi-statement-arrow guards still hold unchanged.

No other spec was weakened, skipped, or relaxed. Every other spec touched in the range gained tests:

| Spec                                  | Tests added                                                                                                                                                    |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreatorAssignmentDetailPage.spec.ts` | 4 (renders the pair, accept, decline, failure leaves the pair in place)                                                                                        |
| `BoardCardDrawer.spec.ts`             | 2 (Review offered on a submitted draft; hidden without the ability)                                                                                            |
| `CampaignDetailPage.spec.ts`          | 1 (the hand-off leaves Board for Drafts)                                                                                                                       |
| `SignInPage.spec.ts`                  | 1 (pixel loads on mount)                                                                                                                                       |
| new files                             | `metaPixel.spec.ts` (8), `AuthFooterMonogram.spec.ts`, `AuthMarketingFooter.spec.ts`, `CreatorGuideCta.spec.ts`, `footerLinks.spec.ts`, `monogramTilt.spec.ts` |

---

## 7. Surprises and reviewer's-eye items

1. **The pixel's E2E blast radius** (§5) — the single most consequential unflagged fact in the batch. 18/18 main specs now make live third-party requests.

2. **The unpinned token contract** (§3) — `--auth-glow-gradient` now means something different from what it meant at the base SHA, and no test enforces the `opacity: 0.3` that consumers must supply. Cheapest fix is a tripwire asserting that every consumer of the token also sets `opacity`, in the spirit of `listing-floor-parity.spec.ts`. Candidate Step-2 item.

3. **`v-html` introduced under a suppression.** `AuthFooterMonogram.vue:66` carries `<!-- eslint-disable-next-line vue/no-v-html -->`. Justified in place and genuinely safe — the content is a build-time `?raw` SVG import with no runtime input, and inlining is required for the scoped CSS to reach inside the artwork — but it is a new suppression of an XSS rule on the unauthenticated login page, which deserves a reviewer's glance rather than a footnote. It is also why `no-hard-coded-colors.spec.ts` stays green: the colour literals live in the `.svg` asset, which that spec does not scan.

4. **A 5.6 MB PDF now ships in `apps/main/public/`.** It is served from the unauthenticated landing page and is the largest single artefact in the batch — 5.9 MB of the +2670 diff by bytes. Already compressed from 12 MB via Ghostscript. Worth deciding whether it belongs in the bundle or behind a CDN before this reaches production.

5. **`RejectApplicationDialog` and `useCampaignApplications` still have no standalone specs** (AH-059 S7a extraction). Not caused by this batch, but AH-061 and AH-062 both work next to them, so the gap is now adjacent to live changes. Pre-existing; noting it because the arc's own close-out left it open.

6. **The board CSS fix reads as trivial and is not.** `flex: 0 0 auto` is one line per file, but the reason the bug was invisible is that `.board-card`'s `overflow: hidden` clipped the squashed content silently rather than overflowing visibly. Both components carry a comment explaining this. No test covers the scroll behaviour — it is layout-only and jsdom has no layout engine, so this is eyes-on-verified only.

7. **Pre-existing coverage shortfall, unchanged.** The global 100% threshold on the scoped coverage set still fails on `modules/auth/api` and `useAuthStore.ts` (97.61%), exactly as at the base SHA. Both files added by this batch (`metaPixel.ts`, and every new auth component) are at 100% on all four metrics. The batch neither caused nor fixed the shortfall.
