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

| ID  | Title                                    | Status  | Notes                                                                |
| --- | ---------------------------------------- | ------- | -------------------------------------------------------------------- |
| —   | Campaign Drafts tab — independent review | Pending | Merged in code; review file reads "pending independent review pass." |

> Pointer, not an ad-hoc item: **Sprint 10 (Payments/Escrow)** remains the deepest pending
> roadmap dependency, Stripe-gated. Tracked in `tech-debt.md`, not here.

---

## Change Log (newest first)

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
- **Ref:** `5dc1e1f` (feat) + `e58dfec` (docs).

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
