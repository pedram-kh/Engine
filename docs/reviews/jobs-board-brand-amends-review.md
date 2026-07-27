# Jobs Board arc — brand amends + campaign listing fields (AH-053 + AH-054) — Review

- **Status:** **Closed — approved.** Three commits: `b7ea3e1` (AH-054 feat), `2568a96` (AH-053 feat),
  and this docs commit.
- **Verdict:** independent review complete: D1–D10 verified and F1–F8 dispositions accepted; all four
  break-reverts confirmed, with **BR-3 accepted as the discriminating proof of A2** — the merged-state
  cases go red while the pure blocking cases stay green, which is what distinguishes an enforced
  predicate from an incidentally-satisfied one; the §5.34 sets, the cross-tenant logo isolation table
  and the content-security set all green; both found-in-build defects fixed per the rulings and
  covered by named tests (`StorageWriteFailedException` in both upload services, the E2E media disk);
  the E2E leg accepted as honest — the `/e2e-media` disk gives it real bytes to serve and the
  `naturalWidth > 0` assertion proves they arrived, rather than asserting an `<img>` exists; the
  flaky-10 i18n magnitude finding recorded against AH-001 as a measured number.
- **Date:** 2026-07-27
- **Provenance:** drafted by Cursor, reviewed and closed by Claude.
- **Scope:** Merged chunk 1+2 of the Jobs Board arc, built as one pass because the two halves share the floor-predicate shape and one i18n gate. Chunk 1 (**AH-053**) is the brand side: a completeness floor, a logo pipeline, a form relabel. Chunk 2 (**AH-054**) is the campaign side: six listing columns, the D3/D5 gates, the Settings toggle, and the read-time scope chunk 3 will bind to. No creator-facing surface ships here — the board itself is chunk 3.

## Production posture (§5.40)

**PROD-DATA RISK: LOW.** Three operations touch existing rows, and none of them rewrites data:

1. **The migration is purely additive.** `2026_07_27_100000_add_jobs_board_listing_to_campaigns` adds six nullable columns plus one boolean defaulting to `false`. No column is dropped, renamed, retyped or backfilled; every existing campaign row is untouched and reads back byte-identical. `down()` is an honest inverse — it drops exactly the six columns it added, nothing else.
2. **D6 is a new refusal, not a new write.** The brand floor never mutates a brand. An incomplete brand keeps its data, stays readable, stays listable, keeps carrying campaigns, and can still be archived and restored. What changes is that its _next content edit_ is refused until the missing fields are supplied. Nothing is backfilled, nulled, or defaulted on its behalf.
3. **`brands:audit-floor` is a pure read.** It bypasses the tenancy scope and includes soft-deleted rows so the operator sees the true platform-wide number, and it issues nothing but `SELECT`s. A named test asserts the command writes nothing.

The one honest caveat is a **behaviour** change rather than a data one: agencies with brands that predate the floor will meet a 422 on their next brand edit. That is the intended product decision (D6), it is surfaced inline by the form rather than discovered through the API, and `brands:audit-floor` exists precisely so the size of that population is knowable before the deploy rather than after.

---

## Per-decision evidence

### D1 — Six listing columns on `campaigns`

`listed_on_jobs_board` (boolean, default `false`), `listing_duration` / `listing_fee` (varchar 120), `listing_languages` / `listing_regions` (jsonb), `listing_examples_url` (varchar 2048). All nullable except the boolean. Added to `$fillable`, cast (`boolean`, `array`, `array`), emitted by `CampaignResource`, and mirrored in `packages/api-client/src/types/campaign.ts`.

**F2 disposition (store-whitelist asymmetry):** the read pass flagged that `CampaignController::store()` writes through an explicit whitelist, so fields added only to `$fillable` would validate, return 201, and silently never persist. All six are in the whitelist. Pinned by `accepts the listing fields on create but never lists a fresh campaign (D2/D4)`, which asserts the persisted values, not just the response.

### D2 — Listing copy is accepted at create; visibility is not

Create accepts all five content fields and ignores `listed_on_jobs_board` entirely — the column is not in `CreateCampaignRequest`'s rules, so a client that sends `true` gets a campaign that is not listed. A campaign becomes visible only through an explicit update.

### D3 — A listed job is never half-empty (resulting-state rule)

`ValidatesJobsBoardListing::LISTING_FLOOR_FIELDS` = `description`, `listing_duration`, `listing_fee`, `listing_languages`, `listing_regions`. The gate is evaluated against the **resulting** state: if the campaign will be listed after this write — whether the payload flips the switch or the campaign was already listed — every floor field must be filled. Every missing field is named in one response so the SPA binds them all in a single round-trip.

Resulting-state, not transition, is what makes `refuses to gut a LISTED campaign` possible: emptying `listing_fee` on a live listing is refused even though the payload never mentions `listed_on_jobs_board`.

### D4 — `listed_on_jobs_board` defaults false, always

Model `$attributes` and the column default both say `false`, and create does not accept the field. A campaign is never listed by accident.

### D5 — Terminal campaigns cannot be listed (transition rule)

D5 is deliberately the _other_ kind of rule. Only the `false → true` transition is refused for a `completed` or `cancelled` campaign. This is what A1 requires: a campaign that was already listed when it ended keeps its flag and stays editable, so `keeps a terminal LISTED campaign editable (the flag is re-sent, not re-toggled)` passes. Had D5 been a resulting-state rule it would have blocked every subsequent save of a listed-then-completed campaign, and the two-mechanisms drift A1 warns about would have arrived immediately.

When D5 fires it returns alone — the floor errors are not piled on top, so the user sees the real reason rather than five field messages hiding it.

### D6 — The brand floor (merged-state predicate, A2)

`Brand::FLOOR_FIELDS` = `name`, `slug`, `description`, `industry`, `website_url`, `logo_path`. `Brand::floorMissingFields(array $overrides = [])` is the single source of truth, consumed by `UpdateBrandRequest`, `AuditBrandFloor`, and — under a source-scan parity spec — the frontend mirror.

The predicate takes **merged state**: payload value where the payload supplies one, stored value otherwise. This is what keeps PATCH a PATCH. Full-payload-required was rejected on the AH-032 evidence: forcing clients to echo fields they cannot see is the exact mechanic that produced the brief wipe-bug.

Create requires every floor field **except** `logo_path` (F7 — the logo needs a row to attach to; see D7).

`Brand::isFilled()` treats whitespace as empty, and the frontend mirror agrees; both sides are pinned.

### D7 — Brand logo pipeline (the avatar pattern)

`POST`/`DELETE /api/v1/agencies/{agency}/brands/{brand}/logo`. Direct multipart, MIME inferred from **content** (a `.png`-named PHP script is refused at both the request rule and the service), re-encoded from the decoded pixel buffer so EXIF/GPS is stripped and any smuggled payload is destroyed, scaled to 1024px, stored at `agencies/{agency_ulid}/brands/{brand_ulid}/logo/{file_ulid}.{ext}` on the private `media` disk, emitted only as a short-lived signed URL from inside authorized serialisation.

**Q5 — replace does not delete.** A second upload writes a new key and repoints the column, mirroring the avatar precedent. The chunk therefore has exactly one code path that can destroy a stored object, and that path keeps its over-reach negative.

**F7 — the create flow is honestly non-atomic.** `POST /brands` then `POST .../logo`. If the second write fails the user lands on the detail page with a warning that says exactly what happened, and the D6 edit gate is the backstop that will not let the brand be saved again without one.

**Intervention exceptions are not `RuntimeException`s.** A truncated or non-raster file that satisfied the MIME sniff would have escaped the controller's catch and surfaced as a 500. `reencode()` catches `ImageException` and rethrows as `RuntimeException`, so an undecodable upload is a 422 like every other content failure. Pinned by `rejects an undecodable file that claims a supported type (422, not a 500)`.

### D8 — Brand form relabel and removals

`description` is relabelled "Monthly deliverables" with a hint naming the shape (`2 Reels and 3 Stories`, plus usage terms) across all 24 locales. The `default_currency` / `default_language` selects are removed from the form.

**The columns, their defaults, their validation and their API emission are untouched.** Two tests pin that the contract did not narrow: an API client can still send both fields, and an edit that omits them preserves the stored values.

### D9 — `brands:audit-floor`

A read-only operator command reporting, platform-wide: how many brands each floor field blocks, the lifecycle split of the blocked population (active / archived / soft-deleted), and the total across distinct agencies. Output shape below.

### D10 — Settings-only surface (Q4)

The toggle and the listing inputs live on the campaign Settings tab. No Overview rows were added — chunk 3 will decide what the board surface reads, and rows added now would be throwaway.

---

## Read-pass findings — dispositions

| ID  | Finding                                                                   | Disposition                                                                                                             |
| --- | ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| F1  | `BrandFactory` used `fake()->optional()`, so suites would go randomly red | Rebuilt deterministic and floor-complete by default, with explicit `incomplete()` and `missingFloorField()` states      |
| F2  | `store()` whitelist asymmetry would silently never persist the new fields | All six added to the whitelist; pinned on persisted values                                                              |
| F3  | Should the visibility flip be audited?                                    | Ratified — `listed_on_jobs_board` joins the `campaign.updated` snapshot; the four free-text/jsonb fields stay out       |
| F4  | Region/language values are unbounded free text                            | Q1 = A + C cap: uppercase-normalised, `size:2`, distinct, max 60 regions / 24 languages; registry deferred to tech-debt |
| F5  | The `/health` upload assertion knew only one cap                          | Q2 = B: `brand_logo_max_bytes` added, `requiredBytes()` now returns the max of all registered caps                      |
| F6  | Restore could be gated by routing accident                                | Pinned explicitly outside the gate, with a break-revert (BR-4)                                                          |
| F7  | Brand create cannot be atomic with the logo upload                        | Accepted and made honest: two writes, a named failure banner, and the edit gate as backstop                             |
| F8  | The `Partial<>` update payload mirror was inaccurate (`slug?`)            | A3 — `Partial<>` stays, docblock added, and the pre-existing mirror inaccuracy corrected                                |

---

## Coverage

### §5.34 negative-case sets

| Set                                | Test                                                                                                                                                                                                                                            |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| At-rest untouchability             | `preserves stored listing fields when the edit omits them`; `keeps stored default_currency and default_language when an edit omits them (preserve-by-omission)`                                                                                 |
| Terminal-status block              | `refuses to switch listing ON for a completed or cancelled campaign (D5)`; `refuses a request that ends the campaign and lists it in one move (D5)`; `permits listing while draft, active or paused (D5 — the complete positive set)`           |
| Scope negatives (disjoint)         | `scopeListedOnJobsBoard excludes every non-qualifying case (§5.34 — disjoint and complete)`; `scopeListedOnJobsBoard admits each listable status (the positive partition)`; `pins LISTABLE_STATUSES as the complement of the terminal statuses` |
| Logo delete over-reach             | `deletes ONLY this brand logo — the remove action has no reach beyond its own object (§5.34)`                                                                                                                                                   |
| Preserve-by-omission (PATCH ≠ PUT) | `preserves omitted fields — the gate did not turn PATCH into PUT (§5.34)`                                                                                                                                                                       |

### Cross-tenant logo isolation

| Case                                         | Test                                                                          | Result              |
| -------------------------------------------- | ----------------------------------------------------------------------------- | ------------------- |
| Upload to another agency's brand             | `cannot upload a logo to another agency brand (404, non-fingerprinting)`      | 404, not 403        |
| Replace / delete another agency's brand logo | `cannot replace or delete another agency brand logo`                          | 404 both verbs      |
| Role posture within the tenant               | `lets a manager manage the logo but refuses staff (the brand update posture)` | manager ✓ / staff ✗ |
| Unauthenticated                              | `refuses an unauthenticated upload`                                           | 401                 |
| Emission                                     | `emits logo_url as a signed URL and null when there is no logo`               | signed only         |

The ownership check runs **before** the policy, because route-model binding resolves `{brand}` before tenancy context exists, and it answers 404 so probing another agency's ULID reveals nothing.

### Content-security set (D7)

`rejects a script disguised as an image by CONTENT, not extension` · `rejects the disguised script at the SERVICE layer too` · `rejects an undecodable file that claims a supported type (422, not a 500)` · `rejects a disallowed image type (gif is outside the 3-MIME allowlist)` · `rejects a file above the configured cap` · `strips EXIF by re-encoding from the decoded pixels`.

### Storage honesty (found in build — see below)

`refuses to record a logo when the disk reports the write failed` (endpoint: 500, `logo_path` still null after rollback) · `raises instead of returning a path when the disk reports the write failed` (avatar service).

---

## Break-reverts (§5.35 — verbatim)

### BR-1 — the D3 completeness gate

Replaced the floor loop in `UpdateCampaignRequest::withValidator()` with `foreach ([] as $field)`.

```
Tests:    4 failed, 27 passed (125 assertions)

⨯ it refuses to list a campaign that is missing listing fields, naming every one (D3)
⨯ it refuses to gut a LISTED campaign — the gate judges the resulting state, not the transition (D3)
⨯ it treats a whitespace-only value as empty (the isFilled agreement)
⨯ it treats an emptied array as a missing floor field
```

Reverted → `Tests:    31 passed (191 assertions)`.

### BR-2 — the D6 floor, blocking direction

Made `Brand::floorMissingFields()` never report a missing field (`if (false && ! self::isFilled($value))`).

```
Tests:    8 failed, 31 passed (126 assertions)

⨯ it hard-blocks the next edit of an existing incomplete brand, naming every missing field
⨯ it refuses to EMPTY a floor field on an already-complete brand (the other direction)   [×3 datasets]
⨯ it treats a whitespace-only floor value as empty
⨯ it blocks on a missing logo alone, and unblocks once one is uploaded (D7 interaction)
⨯ it restores an incomplete archived brand without gating, and gates only on the NEXT real edit
⨯ it unblocks the D6 edit gate once a logo is uploaded, and re-blocks when it is removed
```

### BR-3 — the D6 floor, merged-state direction

Left the gate active but made the predicate ignore the payload (`$value = $this->{$field};`), i.e. judge stored state only.

```
Tests:    5 failed, 66 passed (259 assertions)

⨯ it accepts the edit once the payload completes the floor in the same request
⨯ it refuses to EMPTY a floor field on an already-complete brand (the other direction)   [×3 datasets]
⨯ it treats a whitespace-only floor value as empty
```

This is the discriminating break: the pure blocking tests stay green while exactly the merged-state behaviour flips, which is the evidence that A2 is enforced rather than incidentally satisfied.

### BR-4 — restore stays outside the gate

Pulled restore inside the floor by adding `if ($brandModel->floorMissingFields() !== []) { abort(422); }` to `BrandController::restore()`.

```
Tests:    1 failed, 52 passed (227 assertions)

⨯ it restores an incomplete archived brand without gating, and gates only on the NEXT real edit
```

All four reverted; the combined set then reports `Tests:    108 passed (537 assertions)`, and `git diff --stat` on each touched file shows no residue.

---

## `brands:audit-floor` — output shape

Against a seeded throwaway database (10 brands across 2 agencies; one archived, one soft-deleted):

```
AH-053 D6 brand-floor audit (READ-ONLY, no writes).

Brands blocked by each floor field (a brand may be counted in several rows):
  name             0
  slug             0
  description      3
  industry         3
  website_url      3
  logo_path        4

Lifecycle split of the blocked brands:
  active           5
  archived         1
  soft-deleted     1

7 of 10 brand(s) across 2 agencies fail the floor.
```

The per-field rows deliberately overlap (a brand missing three fields appears in three rows); the closing line is the distinct count, which is the number that matters before a deploy.

---

## Found in build — two defects outside the plan

### 1. A failed object-storage write answered 200

Every object-storage disk in `config/filesystems.php` is `'throw' => false`, and neither `BrandLogoUploadService` nor its `AvatarUploadService` precedent checked the `put()` return. An unreachable bucket therefore made `put()` return `false`, the column was assigned anyway, and the API answered 200 over a row pointing at an object that does not exist.

Ruled **fix both**. New `App\Core\Storage\StorageWriteFailedException` — deliberately extending `Exception`, not `RuntimeException`, so it escapes the controllers' content-rejection catch (which would have blamed the user with a 422), rolls the surrounding transaction back, and surfaces as a reported 500. Both services check the return; both have a named test.

### 2. The E2E suite had no object store

The `e2e-main` CI job provisions Postgres and Redis only. Combined with defect 1, a Playwright logo leg would have stored nothing and passed — the exact dishonest-green this arc exists to prevent.

Ruled **point the media disk at the local driver for E2E only**. `MEDIA_DISK_DRIVER=local` selects a local-filesystem branch of the `media` disk; `serve => true` gives it a signed `storage.media` route so `signedViewUrl()` keeps its production semantics rather than being special-cased. Production is unaffected: the variable is unset everywhere else and the branch defaults to `s3`.

**A trap worth recording.** With `serve => true` and no explicit `url`, the route defaults to `/storage/{path}` — the same URI the `local` disk already registers, with a `.*` wildcard. First registration wins, so `storage.local` silently swallowed every `/storage/media/...` request, was handed `media/agencies/…`, found nothing, and 404'd. The signature still validated (it is computed over the URL, not the route), so the only symptom was a logo that never rendered. The E2E route is now `/e2e-media`, and `e2e-media-disk.spec.ts` pins all of it — including that the branch must not sit under `/storage`.

This is also why the Playwright assertion is `naturalWidth > 0` rather than "an `<img>` exists": the img element was present and the src was a well-formed signed URL throughout the failure.

---

## Gate table (local, at build HEAD)

| Gate                                   | Command                                  | Result                                                                                                                                                                                     |
| -------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Backend suite                          | `composer test`                          | **2074 passed, 1 skipped**                                                                                                                                                                 |
| Backend style                          | `composer pint -- --test`                | **passed**                                                                                                                                                                                 |
| Backend static analysis                | `composer stan` (PHPStan, 851 files)     | **no errors**                                                                                                                                                                              |
| Frontend unit (all workspaces)         | `pnpm test:frontend`                     | **pass** — `apps/main` 135 files / 1243 tests                                                                                                                                              |
| Frontend lint                          | `pnpm lint:frontend`                     | **0 errors** (2 pre-existing `v-html` warnings)                                                                                                                                            |
| Frontend typecheck                     | `pnpm typecheck:frontend`                | **pass** (5 projects)                                                                                                                                                                      |
| i18n locale parity (24 × every bundle) | included above                           | **pass** — keyset, placeholder, plural-form                                                                                                                                                |
| E2E — main SPA                         | `pnpm --filter @catalyst/main test:e2e`  | **23 passed**                                                                                                                                                                              |
| E2E — admin SPA                        | `pnpm --filter @catalyst/admin test:e2e` | **2 passed**                                                                                                                                                                               |
| Format                                 | `pnpm format:check`                      | clean for every file this chunk touched; 4 files remain dirty from before it (`apps/{main,admin}/index.html`, `docs/reviews/sprint-4-chunk-2-review.md`, `scripts/i18n/verify-locale.mjs`) |

Playwright ran against the docker-compose stack (Postgres, Redis, MinIO) with `MEDIA_DISK_DRIVER=local` for the API webServer.

### New Playwright legs

`brands.spec.ts` now fills the whole D6 floor and attaches `playwright/fixtures/brand-logo.png` (a real 96×96 PNG, 324 bytes, committed):

- **Happy path** — create with the full floor + logo, assert the two-write create did not half-fail (no `brand-detail-logo-failed` banner), assert the detail-page logo actually resolves (`naturalWidth > 0`), edit by name alone (merged-state predicate satisfied from stored values), archive.
- **Floor gate** — submit held on an empty form with the hint naming the missing fields; still held with every text field filled and no logo; released when the logo lands; then, on the edit page, removing the logo re-latches the gate and re-uploading releases it again.

---

## i18n

38 keys per locale across all 24 (19 brand, 19 campaign), plus the D8 `description` relabel. Two glossary corrections were made during the pass:

- **Brand nouns.** `Reels` / `Stories` were being declined in `fi` (`Reelsiä`, `Storya`), `ga` (`Reel`, `Story`) and `hr` (`Reelsa`, `Storyja`). Glossary §1 requires byte-identity; the surrounding sentences were rephrased instead. This matches the existing `campaigns.fields.descriptionHint` precedent, which keeps them literal in every locale.
- **Domain term consistency.** `da` and `sv` now use the corpus loanword (`creatoren` / `creatorn`, 104 established uses each) rather than `kreatør` / `kreatör`. `bg`, `fi` and `lv` deliberately keep `криейтър` / `tekijä` / `veidotājs`: the competing `Творец` / `Luoja` / `Radītājs` appear **only** inside strings that are themselves still English (`"Творецs you invite or engage will appear here…"`), never in translated prose.

That second observation surfaced a much larger pre-existing problem, recorded in tech-debt: across `apps/main` the flaky-10 locales are 759–787 of 1351 leaves byte-identical to English, ~320 of them multi-word sentences, while the other 13 locales sit at 26–68 with none multi-word. See the AH-001 i18n-completeness entry, whose magnitude line this chunk updates.

---

## Out of scope — logged, not built

- **A region/language registry.** Q1 shipped the C cap (`size:2`, uppercase-normalised, distinct, bounded) rather than option B's validated registry. Tech-debt line points at B.
- **The jobs board itself.** Chunk 3. `Campaign::scopeListedOnJobsBoard()` ships now (Q3 = A) with a disjoint negative set, so chunk 3 binds to a tested contract rather than a promise.
- **Overview-tab listing rows.** Q4 — deferred until the board defines what it reads.
- **A dedicated audit verb.** Q6 — the flip rides `campaign.updated`; a new verb has not earned its keep.

---

## Commits

Three-commit split (the brand and campaign halves are independently green, and the docs land separately):

1. `b7ea3e1` — `feat(campaigns): jobs-board listing fields, gates and Settings toggle (AH-054)`
2. `2568a96` — `feat(brands): completeness floor, logo pipeline and form relabel (AH-053)`
3. `docs(jobs-board): AH-053/AH-054 review + change-log entries + tech-debt` (this commit, amended at close)

The i18n bundles carry both halves' keys in one file per locale, so the campaigns commit holds an
intermediate state where only the `app.campaigns` subtree has advanced. That state was verified green
in isolation — backend 2032 passed / 1 skipped, `apps/main` 132 files / 1225 tests, Pint, PHPStan and
typecheck clean — so the first commit is independently green rather than green by assumption.

**Pushed** on close. Deploy obligations (the additive migration and the pre-deploy
`brands:audit-floor` read) are carried in `RESUMPTION-TEMPLATE.md`, which is where push and deploy
state live.
