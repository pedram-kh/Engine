# Creator date of birth → age everywhere — Inventory (I1–I6)

- **Scope:** read-only inventory only. **NO edits, NO plan, NO code.** Answers I1–I6 for the
  locked feature: creators enter a **date of birth** (wizard step + own profile edit, real date
  picker with sane year navigation); **everyone else sees a computed AGE only, never the raw
  date** (data minimization — agencies/discover/admin need "24", not the birthday); roster +
  discover gain an **age-range (min–max) filter**; DOB **joins the completion floor**, so the
  ~279 live creators follow the **AH-026 posture** exactly (untouched at rest, gated on next
  edit, `creators:recompute-completeness` re-run at deploy); **NO minimum-age enforcement**
  (offered, declined by Pedram — recorded here as a decision, not an omission).
- **PROD-DATA RISK today: NONE** — this document changes nothing; it is a read-only survey.
  The eventual build is **LOW expected**: one additive-nullable column, no backfill, no write
  path re-pointed. The sensitive part is **what renders where**, not the writes — see
  [§I5](#i5--privacy-boundary) for the one place where the house's existing single-resource
  posture actively works against the locked decision.
- **Session orientation (fresh session, per house pattern through AH-087):** `HEAD` =
  `ef064a94` = `origin/main` (branch `main`, zero ahead / zero behind — confirmed by
  `git rev-parse HEAD` and `git rev-parse origin/main` both returning
  `ef064a949c29c4c6d75ef925dc6da065dab16ad8`; `git status` clean). Most recent commits:
  `ef064a94` (AH-087 close-out — approved, deploy-log entries merged) → `5aa6564f` (AH-087
  review, tenancy rows, tech debt, deploy) → `c140b001` (AH-087 members card + 2FA controls)
  → `48a984a8` (AH-087 admin agency-members drill-in) → `cfd776a2` (the AH-087 inventory).
- **Load-bearing precedents walked for this inventory:**
  - **AH-026** — the onboarding-floor machinery: floor fields, backfill-on-next-edit, and the
    recompute command ([`onboarding-floor-reweight-review.md`](onboarding-floor-reweight-review.md)).
  - **AH-022** — the closest thing to existing "date handling" on creator profile data. Verdict:
    **AH-022 is not a date chunk at all** (see [§I1.3](#i13--date-input-precedent--the-answer-is-none-in-this-spas-wizard)).
  - **AH-080** — the `CreatorProfileDialog` / `CreatorProfileContent` render surface, full vs thin
    payload modes ([`creator-profile-everywhere-review.md`](creator-profile-everywhere-review.md)).
  - **AH-051** — the contact gate: the what-crosses-to-whom discipline
    ([`admin-connections-review.md`](admin-connections-review.md)).
  - **AH-079 / AH-076** — the chip/param filter patterns and the URL-state composable
    ([`admin-all-creators-filters-review.md`](admin-all-creators-filters-review.md)).

---

## The answers that shape the kickoff (headline)

1. **I2 — the floor is HAND-LISTED in three places, not registry-driven, and the parity spec is
   the only thing holding them together.** There is no shared floor registry: the field set is
   spelled independently in `CompletenessScoreCalculator::isProfileComplete()`
   (`apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php:352-357`), in
   `ProfileBasicsForm.vue`'s `floorMet` computed
   (`apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue:249-257`), and in the
   parity spec's `FLOOR_FIELDS` pin
   (`apps/main/tests/unit/architecture/floor-mirror-parity.spec.ts:62-69`). The spec enforces
   agreement by **regex-extracting both source bodies as text** and comparing token sets
   (`:91-102` backend, `:104-115` frontend) — it does not import a shared list. So adding DOB to
   the floor is a **three-place edit by construction**, and the spec is designed to fail loudly
   if only two of the three move (that is exactly its stated purpose, `:1-23`). Per-field cost is
   therefore small, mechanical, and already precedented one-for-one by AH-026's `region`
   addition — but it is **not** a one-line registry change.
2. **I3 — the render surfaces are FIVE distinct profile-field blocks, of which one is shared
   three ways.** Four hand-written blocks (`apps/main` roster detail, `apps/main` discover detail,
   `apps/main` discover CARD, `apps/admin` creator detail) plus the AH-080 shared
   `CreatorProfileContent.vue` grid, which backs the campaign Creators tab, the board drawer's
   Profile tab, and both application surfaces at once. See [§I3.2](#i32--the-render-surfaces--five-blocks-one-of-them-shared-three-ways)
   for the enumeration with the resource each reads. There is **no age accessor anywhere today** —
   nothing on `Creator`, nothing on `User`, no resource emitting anything age-adjacent
   ([§I3.1](#i31--the-resources--what-each-emits-today)).
3. **I1 — DOB's natural wizard home is the existing profile-basics step, and it needs no new
   step.** `ProfileBasicsForm.vue` is the single shared body already backing BOTH wizard step 2
   (`Step2ProfileBasicsPage.vue`) and the post-onboarding self-edit page (`CreatorProfilePage.vue`)
   — the AH-009 extraction. One field added there lands on both surfaces at once, which is also
   exactly what the locked decision asks for ("wizard step + own profile edit"). No `WizardStep`
   enum change, no step-count change, no rail/numbering change.

**One further finding the kickoff must resolve, surfaced by I5 and not in the brief's list:**
`CreatorResource` is deliberately ONE resource serving TWO audiences — the creator's own
`GET /creators/me` **and** the admin drill-in `GET /admin/creators/{creator}` via `withAdmin()`
(`apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php:26-36`, `:91-96`;
admin call site `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:176-178`).
`admin_attributes` is **additive** — the admin gets the full base `attributes` block too. The
AH-005 contact fields exploit this on purpose: they sit in the base attributes precisely so the
creator can hydrate their own form, and the comment records that "the admin inherits them through
the same resource (admin view-only, D6)" (`CreatorResource.php:119-125`). **DOB cannot follow that
precedent**: the locked decision says admin sees "24", not the birthday. So the kickoff must
choose between (a) the first audience-split inside `CreatorResource` since Chunk 1's tech-debt
entry 4 explicitly closed on "one resource, two gated audiences, symmetric shape"
(`CreatorResource.php:33-36`, `:86-89`), or (b) keeping raw DOB off `CreatorResource` entirely and
emitting `age` there instead, with the raw value reaching the creator by some other route. This is
a decision, not a detail — flagged now rather than discovered at build.

---

## I1 — Where DOB lives

### I1.1 · The `creators` table's shape — confirmed: NO birth/date column exists

The base table is `apps/api/database/migrations/2026_05_14_100000_create_creators_table.php:34-95`.
Its profile block is `:44-57` (`display_name`, `bio`, `country_code`, `region`,
`primary_language`, `secondary_languages`, `avatar_path`, `cover_path`, `categories`), then
lifecycle `:59-70`, `profile_completeness_score` `:74`, `last_active_at` `:76`,
`signed_master_contract_id` `:81`, KYC `:85-86`, `tax_profile_complete` / `payout_method_set`
`:88-89`, `submitted_at` `:91`, timestamps + soft-deletes `:93-94`.

Eleven migrations touch `creators`; the eight that ADD columns are:

| Migration                                                                | Column(s) added                                              | Line(s)  |
| ------------------------------------------------------------------------ | ------------------------------------------------------------ | -------- |
| `2026_05_15_100002_add_click_through_accepted_at_to_creators_table.php`  | `click_through_accepted_at`                                  | `:39-41` |
| `2026_05_15_100003_add_welcome_message_to_creators_table.php`            | `welcome_message`                                            | `:29`    |
| `2026_05_16_100000_add_kyc_method_and_verified_by_to_creators_table.php` | `kyc_method`, `verified_by_user_id`                          | `:38-43` |
| `2026_06_03_110000_add_is_discoverable_to_creators_table.php`            | `is_discoverable`                                            | `:32`    |
| `2026_06_28_120000_add_contact_details_to_creators_table.php`            | `phone`, `whatsapp`, `address_street`, `address_postal_code` | `:32-35` |
| `2026_07_08_120000_add_accent_to_creators_table.php`                     | `accent` (AH-022)                                            | `:21`    |
| `2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php`   | `incomplete_nudge_sent_at`                                   | `:32`    |
| `2026_07_19_100000_add_content_companions_to_creators_table.php`         | `content_companions` (AH-050)                                | `:29`    |

Plus `2026_06_03_100001_add_search_vector_to_creators_table.php` (Postgres-only generated tsvector,
`:46-54`) and `2026_06_03_100003_create_talent_pool_creators_table.php` (a different table).

**Confirmed absent (the presumption in the brief holds).** A case-insensitive search for
`birth|dob|date_of_birth` across `apps/` returns exactly **one** file, and it is a false positive:
`apps/api/tests/Feature/Modules/Campaigns/CampaignApplicationAcceptTest.php:146` — _"the assignment
birth"_, a metaphor for row creation in an audit-row test. The only other near-miss in the repo is
the `content_companions` migration's own docblock explicitly disclaiming demographic ages
(`2026_07_19_100000_add_content_companions_to_creators_table.php:12-14`) — companion age-bands
describe **who appears in the content**, never the creator's own age. So there is **no
`date_of_birth`, no `birth_date`, no `dob`, and no `age` column, cast, accessor, or resource key
anywhere in the platform today.**

The existing indexes on `creators` are `idx_creators_country_code`,
`idx_creators_application_status`, `idx_creators_verification_level`
(`2026_05_14_100000_create_creators_table.php:97-101`) plus the Postgres-only categories GIN index
(`:106-110`) — relevant to [§I4.4](#i44--the-index-question--the-house-volume-trigger-posture).

**Model:** `apps/api/app/Modules/Creators/Models/Creator.php` — `$fillable` `:106-149` (44 entries;
contact block documented `:111-113`, `content_companions` `:126-127`), `casts()` `:291-313` (the
datetime casts are `approved_at`, `rejected_at`, `kyc_verified_at`, `last_active_at`,
`submitted_at`, `incomplete_nudge_sent_at`, `click_through_accepted_at` — a DOB would want `'date'`
rather than `'datetime'`, which would be the first `date` cast on this model).

**Write path:** `apps/api/app/Modules/Creators/Http/Requests/UpdateProfileRequest.php:59-97` is the
single `PATCH /creators/me/wizard/profile` rule set — `display_name` `:62`, `bio` `:63`,
`country_code` `:64`, `region` `:65`, contact `:71-74`, `primary_language` `:78`,
`secondary_languages` `:79-80`, `accent` `:84`, `categories` `:85-89`, `content_companions`
`:94-95`. Every rule is `sometimes`, so a partial save never clears an untouched field — the shape
a nullable DOB would slot into unchanged. **Note for the no-minimum-age decision:** this is the
file where a `before:-18 years` rule would have gone; its absence is the recorded decision.

### I1.2 · The wizard's step structure — DOB joins the existing profile step

**Backend registry (single source):** `apps/api/app/Modules/Creators/Enums/WizardStep.php` — the
cases `:32-39` (`profile`, `social`, `portfolio`, `kyc`, `tax`, `payout`, `contract`, `review`),
`ordered()` `:76-88`, the build-time hidden set `WIZARD_HIDDEN_STEPS = ['kyc','tax','payout']`
`:68`, `isHidden()` `:93-96`, `visibleOrdered()` `:106-112`. The TS mirror is
`packages/api-client/src/wizard.ts:30`, held in lockstep by a parity architecture test
(`WizardStep.php:62-64` names the standard; spec `packages/api-client/src/wizard.spec.ts`).

**Frontend UX step list (the presentation registry — it does NOT match the backend 1:1):**
`apps/main/src/modules/onboarding/composables/useWizardSteps.ts` — `BASE_UX_STEPS` `:57-71`,
`VISIBLE_UX_STEPS` `:78-80`. Social + portfolio are MERGED into one `connections` UX step; with
kyc/tax/payout hidden the rendered rail is `account_created → profile → connections → contract →
review`.

**The step pages** (`apps/main/src/modules/onboarding/pages/`):

| Page                         | Backend step           | Collects                                   |
| ---------------------------- | ---------------------- | ------------------------------------------ |
| `WelcomeBackPage.vue`        | —                      | resume landing, CTA to `next_step`         |
| `Step2ProfileBasicsPage.vue` | `profile`              | **the profile-basics body (see below)**    |
| `Step3ConnectionsPage.vue`   | `social` + `portfolio` | social connect + ≥1 portfolio item         |
| `Step5KycPage.vue`           | `kyc` (hidden)         | vendor KYC / skipped badge                 |
| `Step6TaxPage.vue`           | `tax` (hidden)         | tax profile                                |
| `Step7PayoutPage.vue`        | `payout` (hidden)      | payout method                              |
| `Step8ContractPage.vue`      | `contract`             | e-sign or click-through accept             |
| `Step9ReviewPage.vue`        | `review`               | completeness bar + per-step cards + submit |

**The step DOB naturally joins: `profile` (wizard step 2), via the shared body, not the page.**
`apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue:1-34` is the AH-009 extraction —
its own docblock states it owns "every field (avatar, display name, bio + live preview, country,
region, the AH-005 contact fieldset, primary language, categories)" (`:6-9`) and is deliberately
host-agnostic (`:11-14`), backing **two hosts**: the wizard step
(`apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`, whose own docblock records
the same at `:5-8`) and the post-onboarding self-edit
(`apps/main/src/modules/creators/pages/CreatorProfilePage.vue`). It exposes `hydrate()` /
`isPristine` for host-driven hydration (`:26-28`) and emits a `readiness` payload
(`{ hasAvatar, hasCategory, floorMet }`, interface at `:52-60`) so each host builds its own gate.

Consequence for the build: **one field, one form, both surfaces** — the wizard step and the profile
edit page are the same component. That is also why the floor gate lands on both automatically
(`Step2ProfileBasicsPage.vue:53` forward gate; `CreatorProfilePage.vue:101-109` hard-block /
soft-warn — see [§I2.2](#i22--the-fe-mirror-and-its-two-consumers)).

### I1.3 · Date-input precedent — the answer is: NONE in this SPA's wizard

**AH-022 is not a date chunk.** The brief asks for "AH-022's date handling if any exists". AH-022
is _"Full ISO country/language pickers + creator accent field (three-concept locale split)"_
(`docs/reviews/RESUMPTION-TEMPLATE.md:694`; log entry `docs/reviews/adhoc-changes-log.md:2975`;
locale model `docs/00-MASTER-ARCHITECTURE.md:354`). It contributes **no date handling at all**. What
it DOES contribute, and what is genuinely load-bearing here, is the **single-field visibility
class** — the precedent later chunks cite as "the accent visibility set": creator-self + discover
(card payload + detail render) + roster (`adhoc-changes-log.md:2068`;
`content-companions-review.md:9`, `:159`). **Age is a candidate member of exactly that class**, and
`accent` is the field to copy the visibility reasoning from — see [§I3](#i3--age-rendering-surfaces).

**Date inputs in the creator wizard / profile-basics form: zero.** A search for `type="date"`,
`VDatePicker`, `v-date-picker`, and `DatePicker` across `apps/main/src/modules/onboarding` returns
no matches. Every date input in the platform lives elsewhere:

| Where                            | File:line                                                                                             | Pattern                                                |
| -------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Creator availability (apps/main) | `apps/main/src/modules/creators/availability/components/DateTimeField.vue:10-11`, `:52`, `:61`, `:89` | **`VDatePicker` inside a menu** + native `type="time"` |
| Campaign form (agency-side)      | `apps/main/src/modules/campaigns/components/CampaignForm.vue:238`, `:245`                             | native `type="date"` (start/end)                       |
| Campaign list filters            | `apps/main/src/modules/campaigns/pages/CampaignListPage.vue:380`, `:390`                              | native `type="date"`                                   |
| Roster availability filter       | `apps/main/src/modules/roster/pages/CreatorRosterPage.vue:472`, `:485`                                | native `type="date"` ×2 (a **two-bound range**)        |
| Admin audit log                  | `apps/admin/src/modules/audit/pages/AuditLogPage.vue:119`, `:130`                                     | native `type="date"`                                   |
| Admin impersonation log          | `apps/admin/src/modules/support/pages/ImpersonationLogPage.vue:153`, `:164`                           | native `type="date"`                                   |

`packages/ui` has **no** date-picker component. The nearest primitive is
`packages/ui/src/components/CMonthGrid.vue` (a month layout grid emitting `YYYY-MM-DD` day keys,
`:13`, `:42`, `:65`, `:100-112`) used by the availability calendar — a grid, not an input.

**What fits the locked decision.** The decision asks for "a real date picker with sane year
navigation". The house's two options are therefore: **(a) `VDatePicker`-in-a-menu**, already
precedented exactly once, in `DateTimeField.vue` — the only component in the repo with a real
picker, and the only one with year navigation at all; or **(b) native `type="date"`**, precedented
five times but explicitly chosen in `CreatorRosterPage.vue:458-467` on the reasoning that "native
date fields thread + test cleanly and are honestly better UX than a heavy picker **for a range
filter**" — a qualifier that does not transfer to a birth date decades in the past, where native
inputs make year selection painful. The kickoff should note that (a) has **no jsdom/Playwright
precedent for a picker interaction in the onboarding flow**, which is the real cost delta (see
[§I6.4](#i64--e2e-exposure)).

---

## I2 — The floor machinery

### I2.1 · The current floor-field list and where it is defined

**Backend, `apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php`:**

- `isProfileComplete()` — `:344-358`. The gate boolean; **six fields**, hand-spelled:

```352:357:apps/api/app/Modules/Creators/Services/CompletenessScoreCalculator.php
        return $creator->display_name !== null
            && $creator->country_code !== null
            && $creator->region !== null
            && $creator->primary_language !== null
            && is_array($creator->categories) && count($creator->categories) > 0
            && $creator->avatar_path !== null;
```

- `PROFILE_FLOOR_WEIGHT = 13` — `:62` (docblock `:50-61` states the pinned invariant: floor weight
  - Σ optional weights = `weights()['profile']` = 25).
- `PROFILE_OPTIONAL_WEIGHTS` — `:73-80` (`bio` 4, `accent` 2, `phone` 2, `whatsapp` 2,
  `address_street` 1, `address_postal_code` 1 = 12). Keyed by Creator attribute name so
  `profileEarned()` reads them generically (`:64-72`).
- `weights()` — `:85-96` (profile 25, social 15, portfolio 10, kyc 15, tax 10, payout 10,
  contract 15 = 100).
- `isFilled()` — `:365-368` (trimmed-non-empty; the FE-matching definition).

**Is there a single source? No.** The floor set exists as three independent spellings
(see [§I2.3](#i23--registry-vs-hand-list--the-shaping-answer)). Note the asymmetry that matters for
DOB: the **optional** fields ARE registry-driven (`PROFILE_OPTIONAL_WEIGHTS` is a keyed array that
`profileEarned()` iterates, `:262-266`), while the **floor** fields are not (a hand-written boolean
chain). So "DOB as an optional scoring field" would be a one-line map entry; "DOB as a floor field"
— which is what is locked — is the three-place edit.

### I2.2 · The FE mirror, and its two consumers

`apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue:249-257`:

```249:257:apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue
const floorMet = computed(
  () =>
    displayName.value.trim() !== '' &&
    countryCode.value !== null &&
    (region.value?.trim() ?? '') !== '' &&
    primaryLanguage.value !== null &&
    categories.value.length > 0 &&
    hasAvatar.value,
)
```

Consumed by exactly two hosts, via the `readiness` emit:

- `apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue:53` —
  `canContinue = readiness.value.floorMet` (AH-026 D2: the step-2 forward gate IS the full floor).
- `apps/main/src/modules/creators/pages/CreatorProfilePage.vue:101-109` — the AH-026 D3 posture:
  `saveBlocked = isHardBlockState && !floorMet` (pending/rejected = hard block on next edit) and
  `showApprovedWarning = isApproved && !floorMet` (approved = soft warn, never blocks). **This is
  the backfill-on-next-edit machinery the locked decision points at** — it is already generic over
  `floorMet`, so adding DOB to the floor automatically enrols the ~279 live creators in exactly the
  AH-026 posture with no new gating code. Documented in that page's docblock `:34-38` and in
  `docs/reviews/adhoc-changes-log.md:98-101`.

### I2.3 · Registry vs hand-list — the shaping answer

**Hand-listed, in three places, held together by a source-inspection spec — not a registry.**

`apps/main/tests/unit/architecture/floor-mirror-parity.spec.ts`:

- `FLOOR_FIELDS` — `:62-69`, the third spelling, pinned once as `{ be, fe }` pairs.
- Backend assertion `:91-102` — reads `CompletenessScoreCalculator.php` as **text**, regex-extracts
  the `isProfileComplete()` body (`:95`), collects every `$creator->(\w+)` token (`:98`) and asserts
  set equality against `FLOOR_FIELDS.map(f => f.be)`.
- Frontend assertion `:104-115` — same shape against `ProfileBasicsForm.vue`, extracting the
  `floorMet = computed(...)` body (`:108`) and every `(\w+).value` token (`:111`).
- The spec's own docblock (`:1-23`) states the intent verbatim: it exists to catch "the silent
  one-sided edit six months from now — e.g. someone adds a seventh floor field to the backend only",
  and notes the fields are "pinned ONCE in FLOOR_FIELDS below, so a legitimate floor change is a
  one-line edit here" (`:19-22`).

So the mechanical cost of adding one floor field is bounded and known — AH-026 did exactly this
with `region` and its break-revert evidence is on record in both directions
(`onboarding-floor-reweight-review.md` §P1, `:183-217`).

### I2.4 · The completion-percentage calculation sites

- `score()` — `CompletenessScoreCalculator.php:119-155`. Renormalises over `applicableSteps()`;
  the profile unit takes **partial** credit via `profileEarned()` (`:140-141`), every other unit
  is all-or-nothing (`:142-144`); final `min(round($earned / $total * 100), 100)` `:154`.
- `profileEarned()` — `:258-269`. Floor block (`PROFILE_FLOOR_WEIGHT` if `isProfileComplete()`)
  plus each filled optional, awarded independently of the floor.
- `stepCompletion()` — `:162-223`. The per-step boolean map (profile leg at `:203`), with hidden
  steps filtered out `:218-222`.
- `scoreCompletion()` — `:287-296` (contract differs from `stepCompletion` for scoring).
- `applicableSteps()` — `:315-342` (flag-gated + hidden steps drop out of the denominator).
- `nextStep()` — `:230-247`.
- Emitted to the SPA on every bootstrap:
  `apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php:179`
  (`profile_completeness_score`), plus `wizard.steps` `:192-201` and `wizard.weights` `:202`.

### I2.5 · The recompute command's current shape

`apps/api/app/Console/Commands/RecomputeCreatorCompleteness.php` (76 lines, whole file read):

- Signature `creators:recompute-completeness {--dry-run}` — `:31-32`.
- Docblock `:11-28` records the D5 posture verbatim: idempotent, "a documented POST-DEPLOY step,
  deliberately NOT a migration side effect", safe to re-run at any time.
- `chunkById(200)` over all creators ordered by id — `:43-45`.
- Idempotence: writes only when `$recomputed !== $current` — `:52-61`.
- `--dry-run` counts without writing — `:58`, reported at `:65-71`.

**Cost of a floor change to this command: zero code.** It re-derives from the calculator, so a new
floor field is picked up automatically; the only touch is the docblock's "why" line (`:14-15`
currently names AH-026 D1/D4 as the formula change) and its test's fixtures. Re-run at deploy is the
locked decision and matches AH-026 D5 exactly (`onboarding-floor-reweight-review.md:151`).

### I2.6 · What adding ONE floor field costs — every place, named

| #   | Place                                                | File:line (today's anchor)                                                                                                        | Why it moves                                                                  |
| --- | ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| 1   | Migration                                            | new file in `apps/api/database/migrations/` (pattern: `2026_07_08_120000_add_accent_to_creators_table.php:21`)                    | additive nullable column                                                      |
| 2   | Model fillable                                       | `Creator.php:106-149`                                                                                                             | mass-assignable                                                               |
| 3   | Model cast                                           | `Creator.php:291-313`                                                                                                             | first `'date'` cast on this model                                             |
| 4   | Validation                                           | `UpdateProfileRequest.php:59-97`                                                                                                  | `sometimes` + `nullable` + `date` rules (no min-age rule — recorded decision) |
| 5   | **BE floor**                                         | `CompletenessScoreCalculator.php:352-357`                                                                                         | spelling 1 of 3                                                               |
| 6   | **FE floor**                                         | `ProfileBasicsForm.vue:249-257`                                                                                                   | spelling 2 of 3                                                               |
| 7   | **Parity pin**                                       | `floor-mirror-parity.spec.ts:62-69`                                                                                               | spelling 3 of 3                                                               |
| 8   | Form field (ref + template + `save()` + `hydrate()`) | `ProfileBasicsForm.vue` (field-key union `:79-80`; `requiredLabel()` `:75-77` for the `*` marker)                                 | the input itself, on both hosts                                               |
| 9   | Self resource                                        | `CreatorResource.php:114-184`                                                                                                     | **see the [§I5](#i5--privacy-boundary) audience problem**                     |
| 10  | Floor/weights tests                                  | `apps/api/tests/Feature/Modules/Creators/CompletenessScoreCalculatorTest.php` (the 100%-fixture trap is documented at `:109-113`) | the fully-complete fixture must gain DOB or it can no longer reach 100        |
| 11  | Recompute test                                       | `apps/api/tests/Feature/Modules/Creators/RecomputeCreatorCompletenessTest.php`                                                    | fixture only                                                                  |
| 12  | Step-2 gate spec                                     | `apps/main/src/modules/onboarding/pages/__tests__` / `Step2ProfileBasicsPage.spec.ts`                                             | forward gate now needs DOB filled                                             |
| 13  | Profile-page floor spec                              | specs around `CreatorProfilePage.vue:101-109`                                                                                     | hard-block/soft-warn cases                                                    |
| 14  | api-client types                                     | `packages/api-client/src/types/creator.ts:117` (`CreatorAttributes`), `:323` (`CreatorProfileUpdatePayload`)                      | payload + attribute shape                                                     |
| 15  | i18n ×24                                             | `apps/main/src/core/i18n/locales/*/creator.json` (`fields.*` block, `en` at `:165-203`)                                           | field label + help text                                                       |
| 16  | Factory                                              | `apps/api/app/Modules/Creators/Database/Factories/CreatorFactory.php`                                                             | complete-creator states                                                       |
| 17  | E2E                                                  | `apps/main/playwright/specs/creator-wizard-happy-path.spec.ts:151-182`                                                            | the step-2 fill block                                                         |
| 18  | Docs                                                 | `docs/reviews/adhoc-changes-log.md`, this file's successor review                                                                 | the AH-026-posture record                                                     |

**Not touched by a floor addition** (checked, not assumed): the submit-gate unit membership
(`stepCompletion()`'s step set), the denominator/`weights()`, the admin approve path, and the
recompute command's body — the same "nothing moved" set AH-026 D7/D8 proved
(`onboarding-floor-reweight-review.md:168-177`).

---

## I3 — Age rendering surfaces

### I3.1 · The resources — what each emits today

Every creator-bearing resource, with the profile keys it carries. **None emits anything
age-adjacent** — no age, no birth year, no bracket. (`content_companions` age-bands describe the
content's cast, not the creator; see [§I1.1](#i11--the-creators-tables-shape--confirmed-no-birthdate-column-exists).)

| Resource                                                                | File:line                                                                                                                           | Emits (profile keys)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Contact keys?                                                  |
| ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `CreatorResource` (creator-self **and** admin drill-in)                 | `apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php:114-184`; `admin_attributes` `:212-252`                           | `display_name`, `bio`, `country_code`, `region`, `primary_language`, `secondary_languages`, `accent`, `content_companions`, `categories`, `avatar_path/url`, `cover_path/url`, `verification_level`, `application_status`, `tier`, `kyc_status`, `kyc_verified_at`, `rejection_reason`, `rejected_at`, `tax_profile_complete`, `payout_method_set`, `has_signed_master_contract`, `click_through_accepted_at`, `social_accounts`, `portfolio`, `profile_completeness_score`, `submitted_at`, `approved_at`, `created_at`, `updated_at`; admin block adds `email`, `account_name`, `account_last_name`, `last_active_at`, KYC attribution | **YES** `:126-129` (base attributes — reasoning `:119-125`)    |
| `AgencyCreatorDetailResource` (roster detail)                           | `apps/api/app/Modules/Agencies/Http/Resources/AgencyCreatorDetailResource.php`; relation block `:97-109`; `mapCreator()` `:119-162` | `display_name`, `bio`, `email`, `account_name`, `account_last_name`, `country_code`, `region`, `primary_language`, `secondary_languages`, `accent`, `content_companions`, `categories`, `avatar_url`, `cover_url`, `application_status`, `social_accounts`, `portfolio`                                                                                                                                                                                                                                                                                                                                                                  | **gated** `:154-159` (only when `withContact(true)`, `:56-81`) |
| `CreatorPublicProfileResource` (discover DETAIL = AH-080 **thin** mode) | `apps/api/app/Modules/Agencies/Http/Resources/CreatorPublicProfileResource.php:61-89`                                               | `display_name`, `bio`, `country_code`, `region`, `primary_language`, `secondary_languages`, `accent`, `content_companions`, `categories`, `avatar_url`, `cover_url`, `profile_completeness_score`, `social_accounts`, `portfolio`, `relationship_status`                                                                                                                                                                                                                                                                                                                                                                                 | **NO** (the break-revert surface, `:20-28`)                    |
| `CreatorDiscoveryResource` (discover CARD)                              | `apps/api/app/Modules/Agencies/Http/Resources/CreatorDiscoveryResource.php:56-81`                                                   | `display_name`, `country_code`, `primary_language`, `accent`, `content_companions`, `categories`, `avatar_url`, `relationship_status` — **no region, no bio**                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | **NO** (`:20-28`)                                              |
| Roster LIST row                                                         | `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` `toRow()` `:330`                                       | relation fields + `creator_id`, `display_name`, `email`, `application_status`, `country_code`, `primary_language`, `accent`, `content_companions`, `categories`, `avatar_url`                                                                                                                                                                                                                                                                                                                                                                                                                                                            | **NO**                                                         |
| `TalentPoolMemberResource`                                              | `apps/api/app/Modules/TalentPools/Http/Resources/TalentPoolMemberResource.php:58-63`                                                | `display_name`, `country_code`, `primary_language`, `categories`, `avatar_url`, `application_status` (+ blacklist facts, `added_at`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | **NO**                                                         |
| `CampaignAssignmentResource` (Creators tab / board rows)                | `apps/api/app/Modules/Campaigns/Http/Resources/CampaignAssignmentResource.php:97-101`                                               | `id`, `display_name`, `avatar_url` **only** (the `avatar_url` addition is AH-080 §6)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | **NO**                                                         |
| Admin creator INDEX (list cards)                                        | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:145-157`                                           | `display_name`, `email`, `application_status`, `kyc_status`, `profile_completeness_score`, `submitted_at`, `created_at`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | **NO**                                                         |
| Admin creator SHOW                                                      | `AdminCreatorController.php:170-178`                                                                                                | **delegates to `CreatorResource::withAdmin(true)`** — i.e. the full base attributes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | inherits **YES**                                               |

**Where an age computation would have to live.** Nothing exists to extend — there is no
`Creator::getAgeAttribute()`, no `age` accessor on `User`, and no shared presenter for scalar
profile facts (`PortfolioItemPresenter` is the only shared presenter and is portfolio-specific,
used at `CreatorResource.php:294-300`, `AgencyCreatorDetailResource.php:192-198`,
`CreatorPublicProfileResource.php:119-125`). So the kickoff picks one of: (a) a model accessor on
`Creator` (one definition, read by every resource that wants it), or (b) per-resource
`Carbon`-derived computation (five to seven call sites, the shape the signed-URL helper took —
which was duplicated on purpose, and the duplication is documented as accepted at
`AgencyCreatorDetailResource.php:44-47` and `CreatorPublicProfileResource.php:40-42`). Option (a) is
the one that makes "the raw date never crosses" a **single** provable seam.

**Which resources need `age` follows from I3.2's surface decisions, not the other way round** — the
minimal set implied by the locked decision is `AgencyCreatorDetailResource` (roster detail),
`CreatorPublicProfileResource` (discover detail + AH-080 thin mode), `CreatorDiscoveryResource`
(discover cards, if age renders there), and the admin path. `CampaignAssignmentResource` and
`TalentPoolMemberResource` carry no demographics at all today and would only move if the kickoff
puts age on those rows.

### I3.2 · The render surfaces — five blocks, one of them shared three ways

| #   | Surface                                                                  | File:line (the field block)                                                                                                                                                                                                                                                                                          | Reads                                                                                                                                                                               | Fields listed today                                                                                                                  |
| --- | ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | **Roster detail** (agency, apps/main)                                    | `apps/main/src/modules/roster/pages/CreatorDetailPage.vue:430`, `:434`, `:441`, `:448` (block ~`:420-449`)                                                                                                                                                                                                           | `AgencyCreatorDetailResource`                                                                                                                                                       | Country · Language · Categories · Companions (+ bio)                                                                                 |
| 2   | **Discover detail** (agency, apps/main)                                  | `apps/main/src/modules/discover/pages/DiscoverProfilePage.vue:419`, `:423`, `:430`, `:434` (block ~`:411-435`)                                                                                                                                                                                                       | `CreatorPublicProfileResource`                                                                                                                                                      | Country · Language · Categories · Companions                                                                                         |
| 3   | **The AH-080 shared dialog body** — backs 3 mount contexts               | `apps/main/src/components/CreatorProfileContent.vue:339-367` (`app.roster.fields.country` `:341-344`, `.language` `:347-353`, `.categories` `:355-359`, `.companions` `:361-366`); dialog shell `apps/main/src/components/CreatorProfileDialog.vue:65-71`                                                            | **full** = `rosterApi.show` → `AgencyCreatorDetailResource`; **thin** = `discoveryApi.show` → `CreatorPublicProfileResource` (mode logic in `apps/main/src/…/useCreatorProfile.ts`) | Country · Language · Categories · Companions (+ bio)                                                                                 |
| 3a  | ↳ Campaign **Creators tab** rows                                         | `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue:644-669` — avatar + `display_name` row (reasoning `:645-648`), row `@click="openProfile"` `:654`; dialog host `:793` (`assumeFull: false`, AH-080 §3a)                                                                                                 | `CampaignAssignmentResource` for the ROW; the dialog re-fetches                                                                                                                     | row shows name + avatar only                                                                                                         |
| 3b  | ↳ Board drawer **Profile tab**                                           | `apps/main/src/modules/boards/components/BoardCardDrawer.vue:667` (tab declared in the docblock `:31-32`; sticky-mount reasoning `:125`, AH-080 §3b)                                                                                                                                                                 | mounts `CreatorProfileContent` inline                                                                                                                                               | as #3                                                                                                                                |
| 3c  | ↳ Application surfaces (board pseudo-column + campaign Applications tab) | `apps/main/src/modules/boards/components/BoardApplicationsColumn.vue:202-213` (identity block + keyboard handling; dialog host `:300`) and `apps/main/src/modules/campaigns/components/ApplicationsTab.vue` (dialog host `:333`) — `assumeFull: true` (AH-080 §3c)                                                   | as #3 (full mode, no fallback)                                                                                                                                                      | as #3                                                                                                                                |
| 4   | **Discover CARD** (grid row)                                             | `apps/main/src/modules/discover/pages/DiscoverPage.vue:400-413` (meta row: country pin `:402-405`, language/accent `:406-412`), category chips `:419-438`                                                                                                                                                            | `CreatorDiscoveryResource`                                                                                                                                                          | Country · Language(+accent) · ≤2 category chips + "+N"                                                                               |
| 5   | **Admin creator detail** (apps/admin)                                    | `apps/admin/src/modules/creators/pages/CreatorDetailPage.vue:779-849` — `EditFieldRow` per field (`display_name` `:779-787`, `region` `:807-815`, `primary_language` `:817-…`); the 7 editable fields are named in its docblock `:8-10`; companions render read-only `:852-860` (the D7 "no pencil" pattern, `:114`) | `CreatorResource::withAdmin(true)`                                                                                                                                                  | display_name · bio · country_code · region · primary_language · secondary_languages · categories (editable) + companions (read-only) |

**Two notes the kickoff will want:**

- **Surfaces #1, #2 and #3 all read the SAME i18n keys** (`app.roster.fields.*`) — the AH-080
  i18n-reuse overturn (`creator-profile-everywhere-review.md:260-268`). So one new leaf,
  `app.roster.fields.age`, serves three of the five blocks; only the admin SPA needs its own key
  (`admin.creators.detail.fields.*`).
- **The read-only-render precedent for a non-editable profile fact already exists** in the admin
  page: companions render as a plain row with no pencil (`CreatorDetailPage.vue:114`, `:852-860`).
  Age is by definition non-editable-by-admin, so it inherits that exact treatment.

### I3.3 · Does age belong in AH-080 **thin** mode? — flagged for kickoff, with the evidence

**Presumption in the brief (age is public-profile-grade, so yes) is consistent with the code**, and
here is the precise reason: thin mode is backed by `CreatorPublicProfileResource`, which already
carries `region`, `primary_language`, `accent`, and `content_companions` — the AH-022 "accent
visibility class" (`adhoc-changes-log.md:2068`) plus AH-050's companions, whose own resource comment
calls them "discover-visible by design (profile-class data, the accent visibility set)"
(`CreatorPublicProfileResource.php:72-74`). Age, as a coarsened derivative of a demographic fact,
sits naturally in that same class; it is emphatically NOT in the AH-005 contact class (which thin
mode carries none of, and which is break-revert-pinned absent, `:20-28`).

**But it is a decision, not an inference**, because thin mode is what an agency with **no relation
at all** sees (AH-080 §2). The kickoff should state it explicitly, and if age goes into thin mode
the AH-080 §5.34 spec set needs a companion case: `CreatorProfileContent.spec.ts` currently pins
that five blocks are ABSENT on a thin payload (`creator-profile-everywhere-review.md:80-85`) — age
would be the first field deliberately PRESENT in both modes, which is worth an explicit
both-modes-render case rather than silence.

---

## I4 — The filter

### I4.1 · Roster filter machinery today

`apps/main/src/modules/roster/pages/CreatorRosterPage.vue`:

- **URL-backed state** (AH-076) — `useListQueryState({ … })` at `:137-159`: `page`, `per_page`,
  `status`, `country`, `language`, `category`, `q`, `available_from`, `available_to`. The comment at
  `:131-136` records the discipline: every filter is validated against the **same bounded
  vocabulary its control offers**, so a hand-typed `?status=bogus` reads as unset rather than
  reaching the API.
- **Status chips** — `STATUS_FILTER_VALUES` `:121-129` (`all` — labelled "Active" per AH-051's
  eyes-on fix #2 — `roster`, `prospect`, `external`, `pending_request`, `declined`, `ended`);
  chip group template ~`:404-415`.
- **Select filters** — country / language / category, ~`:424-453`; vocabularies at `:86-119`
  (`CATEGORY_FILTER_KEYS` `:90-119`, country from `COUNTRY_OPTIONS`, language from
  `worldLanguageOptions()`).
- **The two-bound range precedent — this is the closest analogue to an age min–max filter.**
  Availability from/to: refs `:145-148`, template `:468-494` (two native `type="date"` inputs with
  reciprocal `:max`/`:min` binding at `:474` / `:487`, both `clearable`). The rule is stated at
  `:145-147` and enforced in the backend: **the filter is sent only when BOTH bounds are set**; a
  one-sided range issues no param.
- **The "present-but-inert" precedent** — the metrics filters (follower range, engagement),
  `:495-536`, driven by `METRICS_FILTERS_CONNECTED = false` (`:72`, reasoning `:66-71`): faded,
  `disabled`, span-wrapped so the tooltip still fires, **no `v-model` and no watcher so they
  cannot query** (`:464-467`). Relevant twice over: it is the house pattern for showing a filter
  before its data exists, and it is the only "numeric range" affordance in the roster UI — and it
  is **not wired**, so an age-range filter has **no live numeric-range precedent to copy**.

### I4.2 · Discover filter machinery today

`apps/main/src/modules/discover/pages/DiscoverPage.vue` — `useListQueryState` at `:86-98`:
`page`, `q`, `country`, `language`, `category` only (codecs `:94-97`). Filter selects ~`:278-307`.
**No status chips, no range filter of any kind, no date param.** So discover is the thinner of the
two: an age range would be its **first** multi-bound filter, whereas roster already has one to
mirror.

### I4.3 · What a min–max age param costs

**Frontend — one new codec.** `apps/main/src/composables/useListQueryState.ts` ships exactly six
codecs: `pageParam` `:71-80`, `perPageParam` `:86-97`, `textParam` `:104-112`, `oneOfParam`
`:115-124`, `oneOfParamWithFallback` `:131-143`, `isoDateParam` `:145-155`. **There is no integer
or bounded-number codec.** An `age_min` / `age_max` pair therefore needs a new
`boundedIntParam(min, max)` in this file — the same shape as `isoDateParam` (validate-or-unset,
`:148-155`) with a numeric range check instead of the `ISO_DATE` regex (`:145`). Everything else is
free: the composable already preserves foreign params (`:180-182`), replaces rather than pushes
(`:192`), and omits defaults from the URL (`:48-49`).

**Backend — the date arithmetic, and the precedent for it.** The only house precedent for
date-window filtering on a creator list is `AgencyCreatorController::applyAvailabilityFilter()`
(~`:268-282`, documented at `:47-50` and `:237-241`): both-bounds-or-nothing (`:270-272`),
server-side normalisation to a half-open day-granular window
(`CarbonImmutable::parse(...)->startOfDay()` / `->addDay()`, `:277-278`), and a `MAX_WINDOW_DAYS`
clamp so expansion stays bounded (`:96-102`, `:280-281`). Two differences worth naming for the
kickoff: (a) availability **cannot** be a SQL predicate at all (per-creator RRULE intervals — the
comment says so explicitly at `:242`), whereas an age range **can** — the sargable form is a
`WHERE date_of_birth BETWEEN <today − max−1day> AND <today − min>` on the raw column, with the
arithmetic done in PHP so the column stays index-eligible; and (b) the "normalise server-side so
the client never constructs the boundary" rule (`:274-276`) applies directly — the SPA should send
ages, and the controller should derive the date bounds.

**The request-validation precedent is exact.**
`apps/api/app/Modules/Agencies/Http/Requests/ListAgencyRosterRequest.php` validates the existing
range as `'available_from' => ['sometimes','date']` and
`'available_to' => ['sometimes','date','after_or_equal:available_from']` (`:39-40`) — an
`age_min`/`age_max` pair maps onto that one-for-one (`integer`, `min:`/`max:` bounds, plus
`gte:age_min` on the upper bound). Two conventions recorded in its docblock transfer directly: the
other filters "stay permissive" and are normalised in the controller rather than rejected (`:15`),
and the param names are deliberately **prefixed** (`available_*`, "not bare from/to") to avoid
collision (`:21`) — i.e. `age_min`/`age_max`, not `min`/`max`.

**Roster vs discover cost.** Roster: one new request-validation entry (`ListAgencyRosterRequest`)
plus a scope leg beside the existing filters. Discover: same leg on the discover index controller
plus its own request class, and it introduces the both-bounds rule to that page for the first time.
Both are read-only additive params behind values nothing previously sent — the same "strictly
additive behind a param nothing previously sent" posture AH-079 declared NONE-risk for
(`admin-all-creators-filters-review.md:30-33`).

### I4.4 · The index question — the house volume-trigger posture

`creators` has three btree indexes today (`country_code`, `application_status`,
`verification_level`) plus the Postgres-only categories GIN index
(`2026_05_14_100000_create_creators_table.php:97-110`). **Nothing would serve a `date_of_birth`
range predicate**, and the house posture for exactly this situation is settled and written down
three times over:

- **AH-054** — `docs/tech-debt.md:2261-2271` (shape-validated not registry-validated; the deferred
  partial index on `listed_on_jobs_board` is the sibling entry).
- **AH-056** — `docs/tech-debt.md:2428-2446`: a non-sargable `ends_at` leg on a trailing-position
  index; accepted because "at current volume … this is invisible, and adding an index for a query
  nobody has measured is the kind of speculative tuning this codebase avoids" (`:2436-2437`), with
  **trigger: volume** — "the first agency with a few hundred listed campaigns, or a board request
  appearing in slow-query logs" (`:2440-2442`).
- **AH-079** — `docs/tech-debt.md:2524-2557`: the `?connected=` correlated subquery with no
  supporting index, explicitly citing "the same posture as … the AH-054/AH-056 precedents"
  (`:2531-2534`), resolution named but deferred (`:2550-2552`).

So the expected disposition for an age-range filter is: **ship without an index, record a tech-debt
entry in the same shape and trigger class**, with the honest note that unlike AH-079's correlated
`EXISTS`, an age range on a scalar date column IS index-servable later by a plain
`(date_of_birth)` btree — i.e. cheaper to fix when the trigger fires. Volume context: the ~279 live
creators figure comes from the kickoff brief, not from a check performed in this session (the local
dev database holds 39 creators; production was not queried here).

### I4.5 · Does discover's public resource already emit anything age-adjacent? — No

Verified by reading both discover resources end to end: `CreatorDiscoveryResource.php:56-81` and
`CreatorPublicProfileResource.php:61-89` carry **no** age, birth year, or age bracket. The nearest
demographic signals are `country_code`, `region` (detail only), `primary_language`, `accent`, and
`content_companions` — and the last of those is about the content's cast, not the creator
(`CreatorDiscoveryResource.php:64-69`). So an age-range filter on discover has **nothing to filter
on and nothing to display** until the column and the accessor exist; there is no partial
implementation to reconcile with.

---

## I5 — Privacy boundary

### I5.1 · The exact-keyset pins that exist on agency/admin-facing resources — named

The single spine is
`apps/api/tests/Feature/Modules/Creators/ContactDetailsWithholdingTest.php` (whole file read). Its
docblock `:24-40` states the model: the four contact columns are surfaced ONLY on the gated
roster-detail surface and the owner/admin `CreatorResource`; **every other creator-bearing surface
must withhold them**, and each assertion "would FAIL if a contact key were added to that surface —
they are real guards, not absent tests" (`:32-34`).

- The keyset constant: `AH005_CONTACT_KEYS = ['phone','whatsapp','address_street','address_postal_code']`
  — `:43`. The value constant (leak-by-value check): `AH005_CONTACT_VALUES` `:46`.
- The helpers: `expectNoContactKeys()` `:48-58` — asserting against the **key set**, with a comment
  (`:50-53`) recording that `toHaveKey($key, $message)` would silently neuter the guard;
  `expectNoContactValues()` `:60-65`.
- The pinned surfaces, one `it()` each:

| Surface                                                   | Test                                            | Resource / endpoint                                                                                                     |
| --------------------------------------------------------- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Discover DETAIL (the **break-revert artifact**, `:36-39`) | `:71-78`                                        | `CreatorPublicProfileResource`                                                                                          |
| Discover LIST card                                        | `:84-90`                                        | `CreatorDiscoveryResource`                                                                                              |
| Talent-pool row                                           | `:96-102`                                       | `TalentPoolMemberResource`                                                                                              |
| Campaign assignment row                                   | `:108-117`                                      | `CampaignAssignmentResource` (`creator` sub-block)                                                                      |
| Roster LIST row                                           | `:123-140`                                      | `GET /api/v1/agencies/{agency}/creators` (keys **and** values)                                                          |
| Agency message-thread list                                | `:146-173`                                      | thread list endpoint (values)                                                                                           |
| Roster DETAIL — the AH-051 D-1 gate                       | positive `:185-202`, negative matrix `:204-228` | contact EXPOSED only for `roster` + non-blacklisted; WITHHELD for `pending_request` / `declined` / `prospect` / `ended` |

Adjacent pins that would also feel a new column:

- **`apps/api/tests/Feature/Modules/Creators/CreatorResourceTest.php` — the load-bearing one for
  §I5.2.** Its two-block model is stated at `:35`, and there are TWO explicit cases: _"exposes the
  AH-005 contact details in the base (owner-self) attributes"_ `:64-73`, and — decisively —
  _"exposes the AH-005 contact details **to admin via the same base attributes** (view-only, not
  `admin_attributes`)"_ `:76-88`, which asserts `attributes.phone` is present for the admin view
  while `admin_attributes` carries none of the four keys. So "admin inherits the base block" is not
  an inference from reading the resource — **it is a pinned, asserted invariant.** Any DOB decision
  that puts the raw date in base `attributes` is therefore asserting, by the same test's logic, that
  admin sees it.
- `CreatorMeBootstrapTest.php` (bootstrap structure + wizard step keys), `AdminCreatorIndexTest.php`
  (list-card keys), `AgencyCreatorRosterTest.php` (roster row keyset) — surveyed, not line-cited;
  each would need a look at build time.

### I5.2 · Would any agency/admin resource emit a raw DOB accidentally?

**The honest answer: it depends on ONE choice, and the default is a leak.**

- **Agency surfaces: structurally safe.** `AgencyCreatorDetailResource`,
  `CreatorPublicProfileResource`, `CreatorDiscoveryResource`, `TalentPoolMemberResource`,
  `CampaignAssignmentResource` and the roster row all build **explicit** payload arrays, key by key
  (`AgencyCreatorDetailResource.php:121-148`; `CreatorPublicProfileResource.php:64-87`;
  `CreatorDiscoveryResource.php:59-79`; `CampaignAssignmentResource.php:97-101`;
  `TalentPoolMemberResource.php:58-63`). None spreads `$creator->toArray()` or
  `$creator->getAttributes()`. **A new column cannot leak into them by accident** — it can only
  arrive by someone typing the key. That is the good news, and it means the kickoff's demand —
  `date_of_birth` never crosses, only `age` — is enforceable on these surfaces purely by not typing
  the key, and then pinning that absence.
- **Admin is the exposure, via the shared resource.** `AdminCreatorController::show()` returns
  `(new CreatorResource($creator, $this->calculator))->withAdmin(true)` (`:170-178`), and
  `withAdmin` only **appends** `admin_attributes` (`CreatorResource.php:211-253`) — the base
  `attributes` block `:114-184` ships either way. The AH-005 contact fields sit in that base block
  **on purpose**, with the trade recorded verbatim: they live there "because the creator must read
  their own values to hydrate the wizard's Contact-details form. The admin inherits them through
  the same resource (admin view-only, D6)" (`:119-125`). The resource's own docblock rules out
  audience-splitting as a matter of standing design: "adding fields per-audience would split into
  separate resources, which we explicitly chose not to do" (`:33-36`).

  **Therefore: putting `date_of_birth` in `CreatorResource`'s base attributes — the obvious,
  precedent-following move — hands the raw birthday to every platform admin, which the locked
  decision forbids.** The kickoff must pick a mechanism. The candidates, with their costs:
  1. **A `withAdmin`-aware fork inside `CreatorResource`** (emit `date_of_birth` when
     `!$isAdminView`, `age` when `$isAdminView`). Smallest diff; but it is the first deliberate
     asymmetry in a resource whose docblock says asymmetry was rejected (`:33-36`, `:86-89`), so it
     needs an explicit overturn in the review, not a silent edit.
  2. **Keep raw DOB out of `CreatorResource` entirely; emit `age` there for both audiences, and
     serve the raw date to the owner from a self-only surface.** Cleanest privacy seam, largest
     diff, and it complicates `ProfileBasicsForm`'s hydration (which reads the bootstrap payload —
     `CreatorProfilePage.vue` / `Step2ProfileBasicsPage.vue` both hydrate from `store.bootstrap()`).
  3. **Accept admin seeing raw DOB** — i.e. treat it exactly as contact is treated. **This
     contradicts the locked decision as written** ("agencies/discover/admin need '24,' not the
     birthday") and is listed only so the kickoff rejects it on the record rather than drifting into
     it.

- **No DOB withholding pin exists yet, and the DOB pin must be STRICTER than the contact pin.**
  Contact legitimately crosses to a connected, non-blacklisted agency on the roster detail
  (`ContactDetailsWithholdingTest.php:185-202`). DOB, per the locked decision, crosses to **nobody**
  — so a DOB keyset guard belongs on **all seven** surfaces in the table above **including** the
  roster detail's contact-included/full mode, which is the one surface the contact spine
  deliberately exempts. That asymmetry is the single most important thing for the kickoff to state
  out loud, because a reviewer copying `AH005_CONTACT_KEYS`'s shape mechanically would inherit the
  exemption and pin the wrong invariant.

### I5.3 · The creator's OWN surfaces — where the raw date must render for editing

| Layer                 | File:line                                                                                                                                        | Role                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------- |
| Wizard step host      | `apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue` (gate `:53`)                                                                 | wizard entry                                  |
| Self-edit host        | `apps/main/src/modules/creators/pages/CreatorProfilePage.vue` (floor logic `:101-109`, docblock `:34-38`)                                        | post-onboarding edit                          |
| The shared field body | `apps/main/src/modules/onboarding/components/ProfileBasicsForm.vue` (fields `:6-9`, `hydrate()`/`isPristine` `:26-28`, field-key union `:79-80`) | **the one place the picker lives**            |
| Self GET              | `apps/api/app/Modules/Creators/Http/Controllers/CreatorWizardController.php:56` — `GET /api/v1/creators/me` (endpoint map `:27-35`)              | returns `CreatorResource`                     |
| Self PATCH            | same controller, `updateProfile()` `:76` — `PATCH /api/v1/creators/me/wizard/profile`                                                            | validated by `UpdateProfileRequest.php:59-97` |
| Self resource         | `apps/api/app/Modules/Creators/Http/Resources/CreatorResource.php:114-184`                                                                       | **the audience problem in §I5.2**             |

Open question the kickoff should answer while it is here: **does the creator also see their own
computed age?** The decision text only guarantees they see the raw date for editing. Rendering both
(date input + "you appear as 24") is free once the accessor exists and removes a support-ticket
class; it is not, however, a locked decision.

---

## I6 — Ripple

### I6.1 · Wizard + floor + recompute specs

| Spec                     | Path                                                                           | What moves                                                                                                                                                                                                     |
| ------------------------ | ------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Floor-mirror parity      | `apps/main/tests/unit/architecture/floor-mirror-parity.spec.ts:62-69`          | `FLOOR_FIELDS` gains one pair; both assertions (`:91-102`, `:104-115`) then require both sources to match                                                                                                      |
| Calculator weights/floor | `apps/api/tests/Feature/Modules/Creators/CompletenessScoreCalculatorTest.php`  | the fully-complete-scores-100 fixture must set DOB — the trap is documented inline at `:109-113`; the weights-sum and D4-split pins are unaffected if DOB is floor-only (no `PROFILE_OPTIONAL_WEIGHTS` change) |
| Recompute command        | `apps/api/tests/Feature/Modules/Creators/RecomputeCreatorCompletenessTest.php` | fixture only — three cases (stale→current, idempotent second run, `--dry-run` leaves the row) per `onboarding-floor-reweight-review.md:225-231`                                                                |
| Step-2 forward gate      | `Step2ProfileBasicsPage.spec.ts` (gate source `Step2ProfileBasicsPage.vue:53`) | a creator without DOB must not advance                                                                                                                                                                         |
| Profile-page floor       | specs around `CreatorProfilePage.vue:101-109`                                  | hard-block (pending/rejected) + soft-warn (approved) cases gain the DOB-missing shape                                                                                                                          |
| Form body                | `ProfileBasicsForm` specs                                                      | the new field's render, hydrate, and save-payload inclusion                                                                                                                                                    |

### I6.2 · Resource keysets

Named in [§I5.1](#i51--the-exact-keyset-pins-that-exist-on-agencyadmin-facing-resources--named):
`ContactDetailsWithholdingTest.php` (the spine — needs a DOB companion set, stricter than contact),
`CreatorResourceTest.php`, `CreatorMeBootstrapTest.php`, `AdminCreatorIndexTest.php`,
`AgencyCreatorRosterTest.php`. Plus, if age lands in the AH-080 dialog:
`CreatorProfileContent.spec.ts` (currently pins five blocks absent in thin mode —
`creator-profile-everywhere-review.md:80-85`) and, per AH-080 §6's own method, a check for whether
an exact-keyset pin exists on any resource that gains `age` before assuming one does
(`creator-profile-everywhere-review.md:200-209` is the worked example of that check).

### I6.3 · i18n scope

24 locales, confirmed by count: `apps/main/src/core/i18n/locales/*/creator.json` → **24 files**;
`apps/admin/src/core/i18n/locales/*/creators.json` → **24 files**. Locale set: `bg cs da de el en es
et fi fr ga hr hu it lt lv mt nl pl pt ro sk sl sv`.

| Axis                                      | Where                                                                                                                                                                                                                  | New leaves × 24 |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- |
| Wizard/profile field label (+ help text)  | `apps/main/.../creator.json` `ui.wizard.…fields.*` — `en` block at `:165-203`                                                                                                                                          | 1–2             |
| Age display label on surfaces #1–#3       | `apps/main/.../app.json` → `app.roster.fields.age` (one key serves roster detail, discover detail, and the AH-080 dialog — the AH-080 reuse rule, `creator-profile-everywhere-review.md:260-268`)                      | 1               |
| Age display label, admin                  | `apps/admin/.../creators.json` → `admin.creators.detail.fields.age`                                                                                                                                                    | 1               |
| Filter labels (roster + discover min/max) | `apps/main/.../app.json` → e.g. `app.roster.filters.age.{min,max}` (mirrors `app.roster.filters.availability.{from,to}`, used at `CreatorRosterPage.vue:473`, `:486`); discover reuses if the key is namespaced shared | 2               |

Gates: `apps/main/tests/unit/architecture/i18n-locale-parity.spec.ts` and the admin twin (keyset +
`{named}` placeholder + plural-form parity across 24 locales); backend `LangParityTest` only if any
`lang/**` PHP string is touched (a display-only field should touch none). The house standard for new
copy is real per-locale translation with a **flaky-10** spot-check (`bg, el, et, fi, ga, hu, lt, lv,
mt, ro`) — `admin-all-creators-filters-review.md:197-210` is the worked example. **Note the AH-026
precedent for the required-field marker**: the `*` is a locale-neutral literal appended by
`requiredLabel()` (`ProfileBasicsForm.vue:75-77`), so marking DOB required adds **zero** keys.

### I6.4 · E2E exposure

**The spec: `apps/main/playwright/specs/creator-wizard-happy-path.spec.ts`.** Its step-2 block is
`:145-182`: URL + page assertions `:151-152`, display name `:154-157`, country via the Vuetify
option-click idiom `:162-163` (with the comment at `:159-161` recording that `.fill()` on the hidden
native select does **not** propagate to the store), **region** `:169` — added by AH-026 with a
comment naming exactly this hazard (`:165-168`: "without a region the 'Save and continue' button
stays disabled and this traversal would stall here"), primary language `:171-172`, category chip
`:175`, optional companion chip `:180`, submit `:182`. Later legs: connections `:190-228`, contract
`:240-274`, review `:280-283`, dashboard `:291-293`.

**So a floor-joining DOB stalls this spec unless the fill is added** — the AH-026 region comment is
the standing warning. The new cost relative to AH-026: region was "a plain text field" (`:168`), and
DOB is not. If the build picks `VDatePicker` there is **no existing Playwright interaction
precedent** for driving a picker in this SPA (the only `VDatePicker` in the repo,
`DateTimeField.vue`, sits in the availability module, which the wizard happy-path never enters); if
it picks native `type="date"`, a `.fill('YYYY-MM-DD')` matches the existing native-date usages. That
is the concrete E2E consequence of the I1.3 picker choice.

Also worth checking at build (named, not assumed): `apps/main/playwright/specs/creator-detail.spec.ts`
(the roster row-click leg, whose timeout was bumped in AH-080's flake rider —
`creator-profile-everywhere-review.md:321-331`) traverses a surface that would gain an Age row, but
it asserts navigation, not field lists.

### I6.5 · api-client types

| Type                                 | File:line                                                         | Change                                                                          |
| ------------------------------------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `CreatorAttributes`                  | `packages/api-client/src/types/creator.ts:117` (block `:117-200`) | `date_of_birth` and/or `age` — the shape depends on the §I5.2 audience decision |
| `CreatorProfileUpdatePayload`        | `packages/api-client/src/types/creator.ts:323` (block `:323-343`) | `date_of_birth?: string \| null`                                                |
| `AgencyCreatorDetailProfile`         | `packages/api-client/src/types/agency.ts:368`                     | `age: number \| null`                                                           |
| `CreatorPublicProfile`               | `packages/api-client/src/types/agency.ts:584`                     | `age: number \| null` (if thin mode carries it — §I3.3)                         |
| `DiscoveryCreatorListItem`           | `packages/api-client/src/types/agency.ts:522`                     | `age: number \| null` (if cards carry it)                                       |
| `WIZARD_HIDDEN_STEPS` / wizard types | `packages/api-client/src/wizard.ts:30`                            | **no change** — no step is added or hidden                                      |

Gates: `packages/api-client` `tsc --noEmit` + its Vitest suite; `vue-tsc --noEmit` on both SPAs.

---

## Cross-cutting notes and open questions for the kickoff

1. **The §I5.2 audience decision is the one real fork.** Everything else in this feature is
   mechanical. Pick the mechanism before any code: (a) fork inside `CreatorResource` with an
   explicit overturn of its "no per-audience fields" docblock, (b) `age`-only on `CreatorResource`
   plus a self-only raw route, or (c) admin sees raw DOB (contradicts the locked decision).
2. **The DOB withholding pin must NOT inherit the contact spine's roster-detail exemption.** Contact
   crosses to connected agencies; DOB crosses to nobody. State it; pin it on all seven surfaces.
3. **Age in AH-080 thin mode** — presumed yes (it is accent-class, not contact-class), needs a
   sentence in the kickoff and a both-modes-render case if adopted (§I3.3).
4. **Picker choice drives the E2E cost**, not the form cost (§I1.3 + §I6.4).
5. **Index: expect a tech-debt entry, not an index** (§I4.4) — and note it is cheaper to fix later
   than AH-079's correlated subquery, because a scalar date column is plainly index-servable.
6. **No minimum-age enforcement is a recorded decision**, not an omission. The place it would have
   lived is `UpdateProfileRequest.php:59-97`; a future reviewer finding no age rule there should read
   this line rather than "fix" it.
7. **`age` is a derived value with a moving answer.** Nothing in the platform currently emits a field
   whose value changes with wall-clock time while its row is untouched. Two consequences worth a
   kickoff sentence: any cache or snapshot of a creator payload silently ages, and a birthday changes
   an agency-visible fact with no audit row. Neither blocks the build; both are new to this feature.

---

_Provenance: read-only inventory produced by Cursor in a fresh session at `HEAD` = `origin/main` =
`ef064a94`, before any kickoff. No file outside this one was modified. Every exact citation above was
opened and read in this session; the handful of ranges written with a leading `~` are approximate
template spans (large Vue templates) rather than verified boundaries, and are marked as such
deliberately. The three shaping answers requested at commission (I2's registry-vs-hand-list reality,
I3's surface list, I1's wizard-step placement) are in the headline block, plus one unrequested
finding — the `CreatorResource` single-resource/two-audiences collision with the age-only decision,
pinned by `CreatorResourceTest.php:76-88` — raised to the same prominence because it changes the
build's shape._
