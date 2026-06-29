# Catalyst Engine — Ad-Hoc Changes Log

A running record of changes made **outside** the sprint plan and the phase roadmap —
out-of-band UX improvements, polish, small fixes, and tech-debt paydowns that don't
belong to any numbered sprint. The aim is simple: nothing the platform does should be
unexplained. If a change isn't a sprint and isn't on the roadmap, it lives here, so any
developer (or future us) can open this one file and know **what changed, why, and where**.

This file is the **index and history** for ad-hoc work. Changes that go through the full
chunk loop still get their own detailed review file in `docs/reviews/`; the entry here is
a short pointer to it, not a duplicate.

---

## How this file works

**Scope.** Any change not driven by the active sprint or the phase spec: UX tweaks, copy
fixes, accessibility, performance polish, small bugfixes, doc corrections, tech-debt
cleanup. If it _is_ part of a sprint, it belongs in that sprint's review, not here.

**Relationship to the existing workflow.** Ad-hoc changes still follow the house loop —
inventory (when the surface is unknown) → kickoff with locked decisions → plan-pause →
build → spot-check → two-commit pair → push. This file doesn't replace that; it's the
durable record so the work isn't invisible afterward. Larger ad-hoc changes get a full
`docs/reviews/<name>-review.md` and this log just links to it.

**Entry lifecycle.** An item starts in **Live Status** (below) when proposed, moves through
`In progress`, and graduates into the **Change Log** as `Landed` once merged. Parked or
dropped items stay in the log with that status so the decision is on record.

**IDs.** Each entry gets a stable `AH-NNN` id so it can be referenced from commits,
reviews, and conversations.

**Entry template** (copy this for each new change):

```
### AH-NNN · <short title>

- **Status:** Proposed | In progress | Landed | Parked
- **Date:** YYYY-MM-DD (landed date, or last-updated while open)
- **Why:** the user problem / motivation in one or two sentences
- **What:** the change in plain terms
- **Touched:** files / modules / surfaces affected
- **Decisions:** any locked calls made along the way
- **Ref:** kickoff / review file / commit(s), if applicable
```

---

## Live Status (open + in-flight)

| ID      | Title                                          | Status   | Notes                                                                                                                                                                                                                  |
| ------- | ---------------------------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AH-010a | Relationship messaging — backend spine + gate  | Landed   | 1:1 connected agency↔creator. Mirrored spine + status-aware gate + file/link attachments (EXIF-strip) + 2 notification types. Committed `2656e5a` (feat) + docs; **push held** for AH-010b sequencing. See Change Log. |
| AH-010b | Relationship messaging — WhatsApp-shaped inbox | Proposed | Net-new conversations inbox + thread on the existing 15s poll; reuses `ChatPanel`/`useMessageThread`. Creator nav item + agency roster-detail entry. Paired commit after AH-010a.                                      |
| —       | Campaign Drafts tab — independent review       | Pending  | Merged in code; review file reads "pending independent review pass."                                                                                                                                                   |

> Pointer, not an ad-hoc item: **Sprint 10 (Payments/Escrow)** remains the deepest pending
> roadmap dependency, Stripe-gated. Tracked in `tech-debt.md`, not here.

---

## Change Log (newest first)

### AH-010a · Relationship messaging — backend spine + gate + attachments + notifications

- **Status:** Landed (push held for AH-010b sequencing)
- **Date:** 2026-06-29
- **Why:** A connected agency and an approved creator had no way to talk outside a
  campaign. AH-010 adds 1:1 direct messaging (WhatsApp-shaped, AH-010b is the FE)
  gated by the relationship — so a blacklisted/declined/prospect agency cannot DM,
  consistent with the AH-005 contact-visibility posture but stricter.
- **What:** A backend relationship-messaging layer built **alongside** campaign
  messaging, not on top of it.
  - **Mirrored spine (Q1, deliberate duplication-debt):** `relationship_threads`
    (`UNIQUE(agency_id, creator_id)`) / `relationship_messages` /
    `relationship_message_read_receipts` + `RelationshipMessageService`. NOT shared
    with the `messages` table / `MessageService` — the campaign `messages.thread_id`
    FK forbids it without a campaign-path change (AH-010 Step-0). Consolidation
    trigger logged in tech-debt.
  - **Gate (D2, load-bearing):** `CreatorPolicy::canMessageRelationship` —
    approved creator + roster + non-blacklisted + active membership/ownership.
    Built from a new status-aware relation query, NOT `canSeeContactDetails`/
    `hasNonBlacklistedRelation` verbatim. Break-revert verified: loosening to the
    not-blacklisted-only predicate fails the declined/prospect/pending/external/
    non-approved specs; reverted.
  - **Attachments (D4):** thread-keyed presigned files + net-new http/https links
    (`javascript:`/`data:` rejected); **synchronous on-complete EXIF strip**
    (reuses `PortfolioImageProcessor`, 25 MB / 50 MP) before any row or signed URL
    — undecodable image → clean 422, not a 500.
  - **Notifications (D5):** two dual-recipient `NotificationType` +
    `AuditAction` verbs. The AuditAction verbs are **inert vocabulary** required
    only by the NotificationType↔AuditAction one-vocabulary tie (the Sprint-11
    `message.received_by_*` precedent) — **NO `audit_logs` row is written on a
    message send**, so a private DM leaves no content or metadata trail. Enforced
    by a guard test (`writes NO audit row on message send`). Recipient resolution
    is relationship-shaped (no assignment to deref).
- **Touched:** `apps/api/app/Modules/Messaging/*` (models, factories, services,
  controllers + concern, request, resource, routes), `database/migrations/2026_06_29_1000{00,01,02}`,
  `CreatorPolicy`, `AuditAction` + `NotificationType` enums (+ their tripwires),
  new `RelationshipMessage{Api,Attachment}Test` + `CreatorPolicyTest` cases.
- **Decisions:** Q1 mirror (duplication-debt + named consolidation trigger);
  Q2 roster-only gate (`external` unreachable + non-roster); Q3 synchronous
  on-complete EXIF strip; Q4 agency-org-level participants (`sender_user_id` per
  message); Q5 symmetric inboxes both sides; Q6 no extra agency eligibility.
  Digest deferred + virus-scan out (tech-debt). `deleted_at` present-but-unwritten.
- **Build assertions met:** full suite **1755 passed / 0 failed** (zero blast
  radius on campaign messaging), gate break-revert, EXIF genuinely stripped on a
  sent image, idempotent per-pair provisioning, PHPStan + Pint clean.
- **Ref:** `2656e5a` (feat) + this docs commit (the AH-010a pair). Kickoff +
  Step-0 in chat; duplication-debt in [`tech-debt.md`](../tech-debt.md). AH-010b
  (WhatsApp-shaped inbox) is the next, separate pair.

### AH-009 · Standalone creator Profile-edit page (reuses wizard steps 2 & 3)

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The wizard was the only creator self-edit path (logged as the wizard-as-settings
  stopgap). Post-onboarding creators had no place to update their profile, socials, or
  portfolio.
- **What:** A "Profile" nav item (desktop topbar + AH-007 mobile bottom-nav) opens an editable
  `/creator/profile` page with two bordered sections — Profile basics (the extracted step-2 form
  body, incl. AH-005 contact) and Socials & portfolio (the two step-3 sub-sections mounted
  unmodified). Reuses the existing save paths (`PATCH /creators/me/wizard/profile` + the social /
  portfolio writes); a single `GET /creators/me` bootstrap hydrates everything. Step 2's `<v-form>`
  body was extracted into a shared `ProfileBasicsForm` (avatar, display name, bio + preview,
  country, region, contact fieldset, language, categories, the `updateProfile` save + 422 mapping)
  that exposes `save()` / `hydrate()` / `isPristine` + a `readiness` emit — **one form, two hosts**:
  the wizard host keeps its chrome (forward-gate, "Save and continue", nav to
  `onboarding.connections`, onMounted + guarded re-hydration watch); the profile host owns its own
  sections, snackbar, and the floor. New strings (`creatorNav.profile`, `creator.ui.profile.*`
  incl. the floor copy) authored in `en` and across all 24 locales (parity green).
- **Touched:** `apps/main` — new `onboarding/components/ProfileBasicsForm.vue` (extracted body),
  `onboarding/pages/Step2ProfileBasicsPage.vue` (now hosts the shared form, keeps wizard chrome),
  new `creators/pages/CreatorProfilePage.vue` (+ `CreatorProfilePage.spec.ts`),
  `creators/routes.ts` (+`creator.profile`), `creators/layouts/CreatorDashboardLayout.vue`
  (conditional nav item), 24× `creator.json` + `availability.json` locales.
- **Decisions:**
  - **Editable, extract-not-duplicate.** Not read-only; the wizard keeps working on the same
    shared `ProfileBasicsForm` body rather than a forked copy (break-revert verified: mutating the
    shared form fails a wizard step-2 spec).
  - **`requireAuth`-only on the creator shell — NOT `requireOnboardingAccess`** (that guard
    redirects every non-`incomplete` creator to the dashboard, which would have made the page
    unreachable for its own audience — the highest-risk finding of the inventory).
  - **Post-submission audience only** (pending / approved / rejected). The nav item is hidden for
    `incomplete` creators, and an `incomplete` deep-link is soft-redirected to
    `onboarding.welcome-back` **from the page** (not the guard, so the route stays `requireAuth`).
  - **D3 literal — sub-sections mounted unmodified.** `ConnectionsSocialSection` /
    `ConnectionsPortfolioSection` are mounted as-is; the page reacts to the store count rather than
    reaching into them, so removal warnings are **post-hoc** (fire when the count lands at zero).
  - **Lifecycle-aware completeness floor (host/page-owned — `CreatorWizardService` untouched).**
    The save paths recompute `profile_completeness_score` / `next_step` with no backend status
    guard, so the regression is guarded at the page edge, split three ways by lifecycle:
    - **pending / rejected → hard block** on profile-basics (`floorMet`, a 1:1 mirror of the
      backend `isProfileComplete`: display_name + country + primary_language + ≥1 category +
      avatar). Save is disabled and guarded, **including the avatar-delete-then-save path**
      (delete avatar → `avatar_path` null → `floorMet` false → blocked).
    - **approved → soft-warn, never block.** The edit is allowed (creator agency) but a warning is
      surfaced; the save genuinely proceeds.
    - **socials / portfolio (all states) → page-level warn at count-zero, never block.** Removing
      the last social / portfolio item is allowed; the page warns when the store count hits zero.
  - **Why approved is soft-warn, not free-edit (the load-bearing finding).** The gating
    read-question — _does anything read `next_step` / `profile_completeness_score` for an approved
    creator?_ — resolved to: `next_step` is **vestigial** post-approval (only wizard surfaces read
    it, all gated to `incomplete`), BUT `profile_completeness_score` is **agency-visible on
    discovery** — `CreatorPublicProfileResource` exposes it for `approved + is_discoverable`
    creators (the same fail-closed gate as the discovery / connection-request reads). So an
    approved creator's edit that lowers completeness lowers a signal prospective agencies see on
    discovery — which is precisely why approved is soft-warned rather than left to edit freely or
    silently. It is also surfaced on the creator's own dashboard (`CompletenessBar`, all statuses)
    and the admin list/detail.
  - **Backend status guard deferred to tech-debt** — the write endpoints have no
    `application_status` guard; this floor is the page-edge defense. (See also the recorded
    decision in `tech-debt.md`: a pending creator below 100% completeness is intentional, not a
    bug — approval is admin judgment, not a completeness gate.)
- **Ref:** `1dcd180` (refactor: extract `ProfileBasicsForm`) + `2ef98ed` (feat: standalone
  profile-edit page + floor).

### AH-007 · Creator platform mobile-responsive pass

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The creator-facing surfaces (onboarding wizard + post-submit dashboard) were
  built desktop-first and were cramped/unusable on small viewports — the wizard's left step
  rail and the dashboard/wizard topbar controls overflowed, the framed wizard content was
  locked to a fixed-viewport inner scroll, and several step-2/step-3 fields broke layout on a
  phone.
- **What:** A frontend-only pass (`apps/main` + one `packages/ui` component), with mobile
  behaviour gated on Vuetify `smAndDown` so desktop is unchanged except where noted:
  - **Navigation reflow.** Onboarding topbar collapses the locale switcher + "Save and exit"
    into a right-side `v-navigation-drawer` hamburger (`v-app-bar-nav-icon`); the creator
    dashboard moves its primary nav from the inline topbar to a `v-bottom-navigation` bar.
  - **Mobile wizard chrome.** New `AnimatedWizardChromeMobile` — a horizontal top step rail
    (fixed edge-anchored number boxes: completed pinned left, upcoming pinned right, active
    centred; thin per-state rectangle outlines) with a snap → SVG-frame-draw → typewriter
    step transition, used instead of the desktop left-rail chrome on `smAndDown`.
  - **Full-height framed content.** The mobile frame moved from a fixed-viewport box with an
    inner scroll to a full-height card the _page_ scrolls; the SVG outline draws the card's
    full height (all four antennas still fire), the step rail is `position: sticky` under the
    app-bar, and a panel `ResizeObserver` (`syncFrameSize`) keeps the outline glued as content
    height changes.
  - **Per-step scroll reset.** Both chromes (desktop + mobile) reset the framed content to its
    top on each step change so a step never opens inheriting the previous step's scroll.
  - **Step-level fixes.** Step 2: the bio/profile preview wraps long unbroken strings
    (`overflow-wrap`/`word-break`) and the dial-code autocomplete no longer wraps to two lines
    on mobile focus. Step 3 social: a mobile-only stacked card with a view/edit toggle
    (read-only `@handle` → Edit reveals the input with Save/Cancel). Step 8: spacing between
    the agreement alert and "Save and continue".
  - **Light-mode logo regression fix.** The light-header logo darkening (added with the
    Catalyst-logo branding swap) used a `:global(...)` scoped rule that Vue's compiler
    collapsed to a bare `.v-theme--light { filter: brightness(0) }`, blacking out the whole
    dashboard in light mode; re-driven from a theme-bound class on the `<img>`.
  - **i18n:** added `app.nav.menu` (hamburger aria-label) and `creator.ui.wizard.actions.cancel`
    across all 24 locales.
- **Touched:** `apps/main` onboarding (`OnboardingLayout`, new `AnimatedWizardChromeMobile`,
  `AnimatedWizardChrome` scroll-reset only, `Step2ProfileBasicsPage` CSS, `Step8ContractPage`
  CSS, `ConnectionsSocialSection` mobile card + view/edit), creator dashboard
  (`CreatorDashboardLayout` bottom nav + logo theme-class fix), shared `packages/ui`
  (`PortfolioGallery` copy-link clipboard fallback), locales (`app.json` `nav.menu` +
  `creator.json` `actions.cancel`, all 24).
- **Decisions:** all mobile branches gated on `smAndDown` (desktop untouched) — _except_ the
  social **Remove** button, deliberately given an outline (`variant="text"` →
  `variant="outlined"`) on **both** desktop and mobile. Mobile wizard frame grows with content
  and the page scrolls (not an inner scroll box). The mobile social view/edit toggle is local
  UI state only and reuses the existing connect/remove flows verbatim (no payload change). Logo
  darkening re-expressed as a theme-driven class, not an ancestor `:global` selector (the
  scoped-CSS footgun that caused the blackout). Beyond-CSS notes: the `PortfolioGallery`
  `execCommand` copy fallback is `<script>` logic in a shared component (affects all consumers,
  copy-feedback only — no content change; desktop success path verified unchanged); no
  API/resource-shape changes; the AH-005 contact card is untouched.
- **Ref:** `dd7d93a` (mobile nav) · `d4e282b` (mobile chrome + polish) · `7e2c327` (scroll
  reset) · `0b176a3` (full-height frame) · `1da5dae` (light-mode blackout fix) + this docs
  commit.

### AH-008 · Portfolio link cards — copy-URL button

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Portfolio link items showed their destination URL but offered no quick way to copy
  it — agencies/creators had to open the link and copy from the address bar.
- **What:** Added a copy-link affordance to link-kind cards in the shared `PortfolioGallery` —
  an icon button that writes the item's `externalUrl` to the clipboard and shows a ✓ tick for
  1.5 s. Surfaced on every gallery consumer (creator onboarding, roster detail, discover
  profile, admin creator detail) via a localized `copyLinkLabel` aria-label across all 24
  locales (main `creator.json` + admin `creators.json`); the consumer pages only pass the new
  label prop. No API or data-shape change.
- **Touched:** `packages/ui` (`PortfolioGallery` button + `PortfolioDrawer` label passthrough),
  `apps/main` (`ConnectionsPortfolioSection`, roster + discover detail pages), `apps/admin`
  (creator detail page), all 24 `creator.json` / `creators.json` locales.
- **Decisions:** the copy logic lives in the shared component, which stays i18n-free (label via
  prop); no persistence/analytics. The HTTP/iOS `execCommand` copy fallback was added later as
  part of the AH-007 mobile pass, not here.
- **Ref:** `185f1a9` (feat) + this docs commit.

### AH-006 · Finish the Connect→Add rename (step-3 social copy)

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** AH-003 renamed the social-account button Connect→Add (nothing actually connects —
  username entry only), but left the surrounding step-3 headings/labels saying "Connect," so the
  screen contradicted itself across all 24 locales.
- **What:** Swept the remaining "Connect"-family copy on the social-account CTA surface to "Add"
  framing — three value edits (`connections.title`, `social.title`, `social.description` in
  `creator.json`) — and regenerated across all 24 locales. Several locales (bg, el, et, fi, ga,
  hr, hu, lt, lv, mt, ro, sk, sl) had never received a translation for the social sub-keys at all;
  hr/sk/sl had Czech copy-pasted into their social block. All corrected in this pass.
- **Decisions:** copy-only, no behavior change; value-edit over key-rename to avoid keyset churn;
  unrelated "connect" uses left untouched (Stripe payout copy, agency connection-request
  workflow, discover connection-status badges, network-error strings, JS identifiers). Two
  agency-side social-metrics empty-state strings flagged as ambiguous but left untouched and
  recorded as tech-debt (social integration deferred).
- **Ref:** `33f2941` (feat) + `90832f4` (docs)

### AH-005 · Creator contact details (phone, WhatsApp, address) — connected-agency-visible

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Connected agencies had no way to reach a creator directly — only the related User's
  email was exposed, and creators had nowhere to provide a phone, WhatsApp, or mailing address.
- **What:**
  - **Four optional plaintext fields on `creators`** — `phone`, `whatsapp`, `address_street`,
    `address_postal_code` (all nullable). The full mailing address composes from the existing
    `country_code` + `region` (city line) + the two new fields — no field stored twice. Plaintext,
    NOT the tax address's `encrypted:array` handling, because these are deliberately agency-visible.
  - **Agency-scoped visibility gate** — `CreatorPolicy::canSeeContactDetails(User, Creator, Agency)`
    = admin OR (active member of _that_ agency AND _that_ agency's relation is non-blacklisted). The
    "non-blacklisted relation" check is one shared `hasNonBlacklistedRelation()` primitive that
    `hasAgencyAccess()` also calls — one canonical blacklist rule. Agency-scoped, not a user-wide
    union: a multi-agency user on Agency A's page for a creator A has blacklisted sees no contact,
    even if their Agency B has a clean relation.
  - **Surfaced only on roster detail** (`AgencyCreatorDetailResource`, gated) + creator-owner
    self-read + admin view-only (base `CreatorResource` attributes — no `admin_attributes`
    duplicate, `EDITABLE_FIELDS` untouched so it stays creator-owned, not admin-editable).
  - **Explicitly withheld** from six surfaces (discover detail, discover list, roster list row,
    talent-pool member, campaign assignment, messaging thread list) — each by omission, each with a
    negative absence assertion that fails if a contact key is ever added there.
  - **Self-edited** via a "Contact details" sub-section on the profile wizard step; rendered to the
    connected agency as a Contact card on roster detail (shown only when the server surfaced it).
  - **i18n done-gate:** new contact-sub-section labels regenerated across all 24 locales; parity green.
- **Touched:** `apps/api` (`creators` migration + four columns, `Creator` model/factory,
  `UpdateProfileRequest`, `CreatorPolicy` gate + shared primitive, `AgencyCreatorDetailResource`,
  `CreatorResource` base attributes, `AgencyCreatorDetailController`), `packages/api-client`
  (`creator.ts` / `agency.ts` types), `apps/main` (`Step2ProfileBasicsPage` contact sub-section,
  roster `CreatorDetailPage` Contact card), locales, policy + withholding + render specs.
- **Decisions:** plaintext not encrypted (agency-visible by design); inline columns not a dedicated
  table; agency-scoped blacklist-aware gate (not the looser relation-exists); `region` reused as the
  city line (no duplicate city column); admin view-only (not the `EDITABLE_FIELDS` contract);
  distinct WhatsApp number (not a flag). Break-revert surfaced and fixed a `toHaveKey($key, $msg)`
  misuse that had silently neutered the withholding guards.
- **Follow-up — country-code dial selector (`1399ee3`):** the phone + WhatsApp contact inputs
  this entry added gained a searchable dial-code selector (a `v-autocomplete` of `+NN` codes,
  backed by new static `countries.ts` / `dialCodes.ts` data and a small `vuetify.ts` default),
  so the dial code is picked separately from the national number on `Step2ProfileBasicsPage`.
  Frontend + static data only — no `apps/api` / `packages/api-client` change, so the `phone` /
  `whatsapp` resource shape is unchanged.
- **Ref:** `5dc1e1f` (feat) + `e58dfec` (docs); dial-code follow-up `1399ee3`.

---

### AH-002 · Digest/invite email locale docblock + English-only decision

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** The `UnreadMessagesDigestMail` docblock falsely implied per-recipient locale handling,
  and the deliberate English-only disposition of the digest + agency-invite emails was unrecorded.
- **What:** Corrected the docblock to state the digest renders in the application default locale
  (`en`) for all recipients — no `->locale(...)` at the send site — and logged the English-only
  decision as tech-debt, including why the digest is harder to localize than a normal mailable: its
  lines are built with `__()` in console context inside `MessageDigestService` (`:204`/`:212`/
  `:220`) before the job is queued, so a future fix must localize at line-build time, not just chain
  `->locale()` at the send site. No behavior change; no test change.
- **Touched:** `apps/api/app/Modules/Messaging/Mail/UnreadMessagesDigestMail.php` (docblock only),
  `docs/tech-debt.md` ("Digest + agency-invite emails are English-only (deliberate)").
- **Ref:** `766d925` (docblock + tech-debt entry); this log reconciliation commit.

---

### AH-004 · Portfolio overhaul (schema + async image worker + drawer)

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The portfolio was a thin, image-only path: small per-creator cap, no full-resolution
  download, raw EXIF-bearing originals served straight back, no link entries, and three separate
  resources each minting signed URLs with their own copy of the (missing) safety logic. It also
  presented inconsistently across the creator, agency-roster, agency-discover, and admin surfaces.
- **What:**
  - **`processing_status` lifecycle** (`processing` → `ready` / `failed`) on portfolio items —
    new enum + migration (`default('ready')` so all existing rows + link items are ready) + model
    cast + factory `processing()` / `failed()` states.
  - **Presigned image uploads** mirroring the proven video path: `POST portfolio/images/init`
    (presigned `PUT`) → client `PUT` with **progress + a client timeout** → `POST
portfolio/images/complete`, which dispatches the worker. Uniform **500 MB** ceiling for all
    file types; per-creator cap raised **10 → 30**.
  - **`ProcessPortfolioImageJob` + `PortfolioImageProcessor`** — an async worker that re-encodes
    the upload at **full resolution with EXIF stripped** (not the avatar downscale path),
    generates a 512px-max-edge thumbnail, and guards a **`MAX_MEGAPIXELS = 50`** decompression-bomb
    cap. On success → `ready`; on over-cap / corrupt input → `failed`. The 50 MP cap is a **matched
    pair** with the memory pins (below): a near-cap decode stays inside the 512 MB test / 768 MB
    worker envelope.
  - **Shared `PortfolioItemPresenter`** — the single source of truth that all **three** portfolio
    mint sites (`CreatorResource`, `AgencyCreatorDetailResource`, `CreatorPublicProfileResource`)
    now route through, so the **server-authoritative `ready`-gate lives in one place**: `view_url`,
    `thumbnail_view_url`, and `download_url` are minted **only** when `processing_status === ready`;
    otherwise null. A break-revert on this gate is the load-bearing spec.
  - **Download** = a presigned GET on the **same already-authorized resource** with
    `ResponseContentDisposition=attachment` (full-res source, never the thumbnail). It therefore
    **inherits each surface's view authz** and the same `ready`-gate — never a broader grant than
    view. Per-surface authz feature tests pin that a caller who 404s the resource never receives a
    `download_url`.
  - **Link portfolio items** — `POST portfolio/links` with http/https-only URL validation (XSS
    guard), surfaced as `ready`-by-definition items with an `external_url`.
  - **`PortfolioDrawer`** — one reusable `v-dialog` (the `ReviewDraftDrawer` pattern) wrapping
    `PortfolioGallery`, wired into all four surfaces with a "View all" affordance + processing
    spinner / failed-state overlays / download button.
  - **Deleting an item cleans up its S3 objects** (raw + thumbnail), including `failed` items whose
    raw object is unreachable behind the gate but would otherwise orphan.
  - **Memory pins (matched pair):** `composer test` runs at `-d memory_limit=512M`; the prod/dev
    `queue:work` worker is sized at `--memory=768` and documented in `local-dev.md`.
  - **i18n done-gate:** new `creator` (main) + `creators` (admin) strings — processing / failed /
    download / view-all labels and the add-link form — regenerated across all 24 locales;
    parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`PortfolioProcessingStatus` enum, migration, `CreatorPortfolioItem`
  model + factory, `PortfolioImageProcessor`, `ProcessPortfolioImageJob`, `PortfolioUploadService`,
  `PortfolioController`, routes, the shared `PortfolioItemPresenter`, the three portfolio resources,
  `composer.json`), `packages/api-client` (`presigned.ts` progress/timeout, `types/creator.ts`),
  `packages/ui` (`PortfolioGallery`, new `PortfolioDrawer`, `index.ts`), `apps/main` (onboarding
  api/composable + spec, `ConnectionsPortfolioSection`, `PortfolioUploadGrid`, roster + discover
  detail pages), `apps/admin` (creator detail page), all `creator.json` / `creators.json` locales,
  `package.json`, `docs/runbooks/local-dev.md`, backend feature/job tests.
- **Decisions:** `MAX_MEGAPIXELS = 50` (not 100) to keep a near-cap decode inside the 512/768 MB
  envelope while still guarding the bomb line; download inherits view authz rather than being a
  separate (broader) grant; the legacy direct-multipart image endpoint is kept for the Playwright
  seed but bypasses the worker (recorded in tech-debt). Resume/multipart, presign-expiry handshake,
  and S3 storage-cost-at-scale remain deferred (tech-debt AH-004 carry-overs).
- **Ref:** `docs/reviews/ah-004-portfolio-overhaul-plan.md` (audited plan); tech-debt
  "Portfolio upload — resume / presign-expiry / storage cost (AH-004 plan carry-overs)" +
  its build-time addendum. Commit-pair: `7b62272` (feat) + `b0605be` (docs); pre-push
  reconciliation follow-up adds the corrected legacy-endpoint disposition + the AH-001 i18n
  completeness debt entry.

---

### AH-003 · Wizard slim + profile-basics polish

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** Sprint 10 (payments) and automated KYC aren't built, and KYC is manual today, so
  the Identity-verification / Tax / Payout steps collect nothing actionable yet — they made
  onboarding longer without value. Separately, the wizard hard-coded its step count (and a
  comment falsely claimed it rendered dynamically), "Connect" misled on form-only social, and
  the profile photo was circular.
- **What:**
  - **Reversible-hide of kyc/tax/payout** via a single static registry
    (`WizardStep::WIZARD_HIDDEN_STEPS`, mirrored by the TS `WIZARD_HIDDEN_STEPS`), held in
    lockstep by a 5.25 parity test. Hidden steps are excluded from the rail, numbering,
    completeness denominator, and the submit gate (so the always-required `tax_profile_complete`
    no longer dead-locks submit). Re-introduction = remove from the list (+ flip the kyc/payout
    Pennant flags ON). NOT a feature flag — it's a build-time "not ready yet" hide.
  - **Merged Social + Portfolio** into one "connections" step with the two kept as distinct
    sub-sections (backend keeps them as separate completion units; APIs/weights unchanged).
  - **Derived numbering/progress/geometry** from a single visible-step list
    (`useWizardSteps`) — removed `TOTAL_STEPS = 9`, the index maps, and the animated chrome's
    `/7`·`/8` divisors, and fixed the false "renders dynamically" comment. A future hide/show
    is now a one-line registry flip.
  - **Profile-basics polish:** photo rectangular (was circular, style-only); "Primary
    language" → "Native language" (label only, column unchanged); removed the "Other languages"
    onboarding input (the `secondary_languages` column + its roster/discover/detail/admin
    displays from AH-001 are untouched; the save payload omits the field so existing data is
    preserved); social CTA "Connect" → "Add" (empty) / "Edit" (added).
  - **i18n done-gate:** the changed/new `creator` strings regenerated across all 24 locales;
    the orphaned `creator.ui.wizard.fields.secondary_languages` key deleted from all 24
    (verified wizard-only first); parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`WizardStep` enum + hidden registry, `CompletenessScoreCalculator`,
  `CreatorResource` bootstrap), `packages/api-client` (`wizard.ts` registry + parity spec),
  `apps/main` onboarding module (new `useWizardSteps`, merged `Step3ConnectionsPage` + two
  section components, `OnboardingLayout`, `OnboardingProgress`, `AnimatedWizardChrome`,
  `Step2ProfileBasicsPage`, `Step9ReviewPage`, `AvatarUploadDrop`, routes), all `apps/main`
  `creator.json` locales, unit + architecture specs, Playwright happy-path.
- **Decisions:** Q1 submit gate ignores `tax_profile_complete` while tax is hidden (the
  alternative is a literal deadlock) — **re-introduction obligation recorded in tech-debt**:
  Sprint 10 must backfill tax for creators who onboard during the hidden window, since tax is
  legally required before a first payout. Q2 static config (not Pennant); hidden takes
  precedence over the existing flag-based skip. Q8 the orphaned `secondary_languages` key is
  deleted from all 24 (parity forces all-24 anyway). D7 "Connect"→"Add", added→"Edit".
- **Ref:** kickoff "Creator onboarding + profile + portfolio reshape (AH-003 + AH-004)";
  tech-debt entries "Hidden onboarding steps (kyc/tax/payout) — Sprint-10-gated" + the AH-004
  upload-ceiling debt. Commit-pair (this entry's landing commit).

---

### AH-001 · EU locale support (24 languages) + persistence

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The language switcher reset on every reload/login (a selected language never
  stuck), and the platform shipped only 3 locales (en/pt/it) while serving EU-wide users.
- **What:** A selected UI language now persists across reload and login in both SPAs
  (server-authoritative via `PATCH /me`, with localStorage for the pre-auth choice), and the
  UI + content-language sets expanded from 3 to all 24 official EU languages via a
  model-authored machine-translation baseline. Includes lazy per-locale loading (only the
  active language is fetched), CLDR pluralization rules for all 24, a request-locale
  middleware so server error strings/emails follow the caller, and parity/placeholder/plural
  CI gates across both SPAs and the backend `lang/` tree. Legally binding content
  (`resources/contracts/**`) is carved out and stays English.
- **Touched:** `packages/api-client` (locale + plural-rules + format registries), both SPA
  i18n bootstraps + switchers + auth stores, Identity module (`PATCH /me`, `SetLocale`
  middleware), `apps/api/lang/**`, all locale JSON across `apps/main` + `apps/admin`,
  architecture parity specs, SOT docs (`00-MASTER-ARCHITECTURE §13`, `CURSOR-INSTRUCTIONS`,
  `02-CONVENTIONS`), new `docs/i18n-glossary.md`.
- **Decisions:** `preferred_language` validates against the rendered `UI_LOCALES`;
  content-language fields against the full `EU_LANGUAGES` (24). `PATCH /me` ignores unknown
  fields rather than 422-ing (matches the notification-preferences precedent; extra fields
  are provably inert). Translation baseline is structurally validated (keys/placeholders/
  plural-form-counts), **not** meaning-verified — per-market human review is a go-live gate,
  not a merge gate. Digest + agency-invite emails remain English-only by decision (see AH-002).
- **Ref:** `docs/reviews/eu-locale-support-review.md` (full review, 9 sub-steps, 48/48 parity).

---

_Maintained alongside the work: when an ad-hoc change lands, its entry moves here in the
same pass — the log and the build move together, never as an afterthought._
