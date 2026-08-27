# Campaign attachments from the Files library, chunk B — REVIEW

- **Status: Closed — approved, push HELD** (per Pedram's explicit instruction).
- **Date:** 2026-08-27
- **Entry:** AH-092.
- **Built from:** [`campaign-attachments-plan.md`](campaign-attachments-plan.md) (D1–D6, the
  plan-pause rulings Q1–Q3) and [`campaign-attachments-inventory.md`](campaign-attachments-inventory.md)
  (the read-only inventory that preceded the kickoff).
- **Commits:** the feature + docs land as a two-commit pair per Pedram's instruction; both SHAs are
  recorded once assigned (a commit cannot record its own hash — `git rev-parse --short HEAD` is the
  authority, re-derive it, do not carry this session's numbers forward).
- **CI at the pushed tip:** not yet applicable — push is held. This session's full local gate board
  (§5) is the verification of record until a push triggers CI; that run, once it exists, is what
  actually closes this chunk per the AH-091 precedent this file follows.
- **Reviewed against:** `docs/00-MASTER-ARCHITECTURE.md` §4 (tenancy), `docs/security/tenancy.md`,
  `docs/02-CONVENTIONS.md`, `docs/05-SECURITY-COMPLIANCE.md`, `docs/tech-debt.md`.

---

## §0. What shipped, in one paragraph

Agencies can now link an existing Files-library document (AH-091) to a campaign. A new
`campaign_attachments` join table (`campaign_id` CASCADE, `agency_file_id` RESTRICT, a
`visible_to_creators` boolean defaulting `false`, `attached_by_user_id`, timestamps, no ulid, no
soft-deletes) records the link. The campaign detail page gains an **Attachments** tab (placed
immediately after Overview) with an attach picker driven by the library's own `?for=select`
unpaginated projection, per-row visibility toggle, and hard-delete detach. Creators see exactly the
rows that are both flagged `visible_to_creators` AND still point at a live (non-soft-deleted) file,
through TWO independent doors — the assignment-detail page (ownership-gated) and the Jobs Board
job-detail page (`JobsBoardVisibility`-gated) — never on the Jobs Board's card list or in an apply
response. AH-091's `hasAttachments()` seam (chunk A's `return false` stub) is now the real query
backing the 409 `agency_file.attached` guard on the library's own delete route.

---

## §1. Production posture

### The risk line, and why it does not climb past LOW-MEDIUM

> **⚠️ PROD-DATA RISK: LOW-MEDIUM**, re-derived at the plan-pause and unchanged by the build. One
> migration, and it is **additive only** — a `CREATE TABLE`, no existing table touched, no column
> added anywhere, no backfill. What keeps the line off LOW: this chunk closes AH-091's `hasAttachments()`
> seam into a REAL guard on an EXISTING delete route, so a library file that was previously always
> deletable can now legitimately 409 the moment it is attached — a real behavior change on a
> shipped endpoint, not merely new capability. What keeps it off MEDIUM-HIGH: the guard only ever
> BLOCKS a delete (fails closed), it never silently drops or corrupts anything, and the new table's
> own worst-case (the Q1 race, below) is verified unreachable-or-contained, not merely assumed so.

**`down()` honesty.** `dropIfExists('campaign_attachments')` is a true structural AND content
inverse — the table is new this chunk, so a rollback loses only this chunk's own attach/visibility
history. No pre-existing `campaigns` or `agency_files` row, and no S3 object, is touched in either
direction.

### Q1, verified rather than assumed — the one place this session corrected its own draft

The plan-pause named the attach/soft-delete race as debt: a concurrent attach and library-file
soft-delete could interleave between `hasAttachments()`'s read and the file's `UPDATE`, producing an
attachment row that outlives its file. The plan's own §2.2 predicted the creator-visible scope's
`whereHas('agencyFile')` would render that state invisible to creators. **This session verified that
claim against a real database** (a targeted Pest probe: soft-delete the file behind a live
attachment row, then read `$attachment->agencyFile`) rather than leaving it as an inference — it
resolves to `null`, confirming the creator side. The SAME probe surfaced that the plan's parallel
claim for the agency side — "a display inconsistency, not a crash" — was **wrong as drafted**:
`CampaignAttachmentController::index()` eager-loads `agencyFile` and `CampaignAttachmentResource`
dereferences it unconditionally (`$file->ulid`), so an unfiltered dangling row would 500 the agency's
own attachments list. **Fixed in this chunk, not carried as debt**: `index()` now filters out any
attachment whose `agencyFile` resolved to `null`, giving the agency side the identical
"invisible, not an error" posture the creator scope already had. See `tech-debt.md`'s AH-092 entry
for the corrected debt line and the dedicated regression test
(`CampaignAttachmentApiTest.php`, "omits an attachment whose file was soft-deleted underneath it").
What remains open as debt is only the race's window itself (the row's existence), never a crash on
top of it — verified, not assumed, per the discipline this session was asked to hold to.

### Blast radius of a bug, worst first

1. **A cross-tenant attach/toggle/detach** — closed by the explicit `$campaign->agency_id ===
$agency->id` and `$file->agency_id === $agency->id` checks before every policy call, 404 never
   403 (the AgencyFileController/CampaignAssignmentController precedent). Proven by break-revert
   #4-class cases in `CampaignAttachmentApiTest.php` (not re-broken this session — pre-existing
   coverage from S2, re-verified green in the full run, §5).
2. **A not-visible attachment leaking to a creator** — closed by `CampaignAttachment::scopeCreatorVisible()`
   requiring BOTH the flag and a live file, composed with the caller's own outer gate at both read
   doors. Proven by break-revert #1 (§4).
3. **A dangling row 500ing the agency's own list** — closed this chunk by the `index()` filter
   above. Proven by the new regression test (§5) and the Q1 verification (above).
4. **A file staying deletable while attached** — closed by the now-real `hasAttachments()` 409
   guard. Proven by break-revert #2 (§4).
5. **A visible attachment appearing on the Jobs Board card list or an apply response** — structurally
   impossible, not merely untested: `attachments` is added only in `CreatorJobDetailResource`, which
   extends but does not modify the shared `cardAttributes()` block `CreatorJobCardResource` (the
   card AND the apply-adjacent list) both use. Proven by break-revert #3 (§4).

---

## §2. Review priorities, addressed in the kickoff's own order

1. **Not-visible exact-keyset absence on both creator surfaces.** `CampaignAttachmentReadPathTest`
   case 2 asserts the array is `[]` — not `null`, not present-but-marked — on BOTH the assignment
   detail and Jobs Board job detail endpoints when a row exists but its flag is off. Proven by
   break-revert #1.
2. **The two-door composition.** Case 3 (unlisted campaign) and case 5 (severed roster relation) each
   prove one door dies while the other survives, using the SAME attachment row — the two doors are
   independently gated, not one gate feeding two renders. `CreatorCampaignAttachmentResource` itself
   enforces neither door (its own docblock says so); the composition lives only at the two call
   sites.
3. **The 409 + unreachable-state consequence.** The 409 guard is proven live by break-revert #2. The
   "unreachable state" claim for the FK backstop is now a VERIFIED "unreachable-through-the-API,
   contained-if-it-happens-anyway" claim — §1's Q1 section above.
4. **Card resource byte-identical.** Proven by break-revert #3 — the card and apply-list shapes
   cannot gain an `attachments` key without touching the SAME shared method the detail resource
   deliberately does not.
5. **The picker's `?for=select` convention.** Proven by break-revert #4 — an unpaginated, thin,
   alphabetically-ordered, `{original_filename, mime_type, size_bytes}`-only projection, the
   `BrandOptionResource` precedent (AH-085), not a bespoke shape.
6. **i18n ×24.** Proven by break-revert #5. `creator.ui.attachments.title` and the agency-side
   `app.campaigns.attachments.*` tree both landed across all 24 locales in the same pass as their
   English source.
7. **Full board + CI.** §5.

---

## §3. Break-revert proofs (five, restored, clean diff verified after)

Each was broken, watched red with the exact failure on record, then reverted; `git status
--porcelain` / `git diff --stat` on every touched file confirmed a clean restore (matching either the
committed HEAD, for files this chunk did not otherwise touch, or this chunk's own in-progress state)
before this file was written.

1. **Not-visible exact-keyset.** Removed `->where('visible_to_creators', true)` from
   `CampaignAttachment::scopeCreatorVisible()` → `CreatorCampaignAttachmentReadPathTest`'s case-2
   test failed, the array now showing the not-visible file's full row instead of `[]`
   (`Failed asserting that two arrays are identical` — `-[]` / `+[{filename: 'enim.pdf', ...}]`).
   Restored.
2. **The 409 guard.** Changed `AgencyFile::hasAttachments()` to `return false;` unconditionally →
   `CampaignAttachmentApiTest`'s 409 case failed (`Expected response status code [409] but received
204` — the delete would have silently succeeded over a live attachment). Restored.
3. **Card byte-identity.** Added `'attachments' => []` directly into
   `CreatorJobCardResource::cardAttributes()` (the block both the card and the detail resource
   share) → the "never leaks another agency's file into the Jobs Board card" test failed
   (`Expecting [...] not to have key 'attachments'`). Restored.
4. **The picker convention.** Renamed the `AgencyFileController::index()` query-string check from
   `'select'` to `'select-broken-for-proof'` → 3 of 7 `AgencyFileSelectOptionsTest` cases failed
   immediately, falling back to the paginated default's wrong shape and wrong (newest-first, not
   alphabetical) order. Restored.
5. **i18n parity.** Removed `creator.ui.attachments.title` from `fr/creator.json` → the locale-parity
   architecture test failed, naming the exact missing key
   (`fr/creator.json: MISSING creator.ui.attachments.title`). Restored.

---

## §4. Gate board

**Backend (`apps/api`).** Pest: **2890 tests, 0 failures** (11048 assertions) — full suite,
including 38 net-new AH-092 tests across four new test files
(`CampaignAttachmentSchemaTest` 9, `CampaignAttachmentApiTest` 13, `AgencyFileSelectOptionsTest` 7,
`CreatorCampaignAttachmentReadPathTest` 9) plus the `AuditActionEnumTest` catalogue extension (three
new `campaign_attachment.*` verbs). PHPStan: **0 errors** (`level: 8`, full `analyse`). Pint:
**passed** (full codebase `--test`).

**Frontend (`apps/main`).** Vitest: **173 files / 1701 tests, all passing** — full suite, including
19 net-new AH-092 tests across three new spec files (`AttachFilesDialog.spec.ts` 8,
`AttachmentsTab.spec.ts` 8, `CreatorAttachmentsBlock.spec.ts` 3) plus extensions to
`CreatorAssignmentDetailPage.spec.ts`, `CreatorJobDetailPage.spec.ts`, `campaigns.api.spec.ts`,
`files.api.spec.ts`, and `format.spec.ts`. `vue-tsc --noEmit`: clean. ESLint: 0 new errors (the two
pre-existing, unrelated `v-html` warnings named in the AH-091 review are the only ones present).
Prettier: clean on all touched files (four newly-created files needed one auto-format pass, applied
and re-verified green).

**`packages/api-client`.** `tsc` typecheck: clean.

**Playwright — full local board run, both apps, Docker/Postgres reachable this session.**
`apps/main`: **27 passed** on the first full-board pass; 3 failed
(`2fa-enrollment-and-sign-in.spec.ts`, `creator-detail.spec.ts`, `talent-pools.spec.ts`). Re-run in
isolation: `creator-detail.spec.ts` and `talent-pools.spec.ts` both pass alone — full-board
resource-contention flakiness, confirmed unrelated (neither spec, nor any file either touches, was
changed this session). `2fa-enrollment-and-sign-in.spec.ts` fails consistently, alone and in the full
board, at a TOTP-timing assertion (`recovery-codes-display` not visible) — a pre-existing,
environment-specific flake: this session made **zero** changes to any 2FA/MFA/auth file (`git status
--porcelain` on the spec and its app-side dependencies is empty), and the file's own git history
already carries two prior de-flaking commits, naming it as a known-fragile spot before this chunk.
`apps/admin`: **2 failed** (`admin-mandatory-mfa-enrollment.spec.ts`, `admin-sign-in.spec.ts`), both
also TOTP-timing failures — this chunk makes **zero** changes anywhere under `apps/admin`
(`git status --porcelain apps/admin` returns nothing), so these are the same pre-existing class of
environmental flake, not a regression. **The new AH-092 E2E leg
(`creator-jobs-board.spec.ts`'s "the visible attachment renders on the job detail page; the
not-visible one is absent" case) passed in both the full-board run and an isolated re-run.** This
chunk's own test surfaces are unambiguously green; the three named TOTP flakes are pre-existing,
environment-tied, and out of this chunk's scope to fix.

---

## §5. Scope fences, restated as shipped (D6)

- **No multi-file bulk-attach endpoint.** The SPA's picker supports multi-select, but each selected
  file becomes its own `POST` — no batch endpoint, per the plan's "multi-select UI over single
  endpoint" ruling.
- **No attachments on Jobs Board cards, the flat `GET creators/me/assignments` list, or apply
  responses.** Structurally enforced (§1, item 5) and pinned (break-revert #3).
- **No anonymous access.** Every read door requires an authenticated creator or an authenticated,
  role-checked agency member; there is no public attachment route.
- **No ordering/reorder UI.** Attachments list `ORDER BY created_at` (agency side) with no drag
  handle or position column.
- **No new bucket or storage prefix.** Reuses the AH-091 `media` disk and the file's own existing
  `agencies/{agency}/files/` key — attaching a file does not move or duplicate its bytes.
- **E2E download-click exposure debt named, not silently accepted.** See `tech-debt.md`'s AH-091
  entry, item 3 — the render-only leg (this chunk's own E2E test) is the floor per the plan's Q3
  ruling; the download-click itself is infeasible in this environment (`MEDIA_DISK_DRIVER=local`
  forces every signed URL to `null`), named as an extension of the existing debt line rather than a
  new one.

---

## §6. Tech-debt entries touched

Recorded in full in `tech-debt.md`:

1. **New AH-092 entry** — the Q1 attach/soft-delete race, corrected mid-session from "agency-side
   display inconsistency" to "agency-side 500, fixed in this chunk" once the model-layer probe
   disproved the milder original claim. What remains open is the race's narrow window itself; the
   agency-side crash-on-top-of-it is closed with a dedicated regression test.
2. **AH-091 entry, item 3, extended (not closed).** The E2E render-only leg for attachments landed;
   the download-click leg is named infeasible in this environment, with the exact blocking mechanism
   cited (the same `MEDIA_DISK_DRIVER=local` override the debt item already named), not a new fact.

---

## §7. Deploy obligation

One additive migration (`2026_08_27_140000_create_campaign_attachments_table.php` — `CREATE TABLE`,
ships empty). No new flag, no new secret, no new bucket/disk (reuses AH-091's `media` disk). Three
new `AuditAction` cases, audit-only (no `NotificationType` tie — nobody is mailed or in-app-notified
when an attachment is added, toggled, or removed). Full detail in
[`deploy-log.md`](../runbooks/deploy-log.md)'s AH-092 entry (stacked on the still-held AH-091 range,
since neither has deployed yet).

---

## §8. Close-out verdict (2026-08-27)

**D1–D6 and the plan-pause's Q1–Q3 rulings verified as built, not just claimed:**

- **D1 (join table).** `campaign_attachments`' shape — `campaign_id` CASCADE, `agency_file_id`
  RESTRICT, `visible_to_creators` boolean default `false`, `attached_by_user_id` SET NULL, no ulid,
  no soft-deletes, `unique_campaign_attachment` on `(campaign_id, agency_file_id)` — matches the
  kickoff exactly, confirmed by re-reading the shipped migration against D1, not just its test names.
- **D2 (the AH-091 seam).** `hasAttachments()` is now a real `exists()` query; the 409 guard on
  `AgencyFileController::destroy()` is proven live by break-revert #2, not merely wired.
- **D3 (agency UI).** The Attachments tab lands after Overview, the picker uses `?for=select`, and
  attach/toggle/detach are gated on `CampaignPolicy::update` (view stays open to any active member)
  — all as ratified at the plan-pause, with no new policy class.
- **D4 (creator read path).** The `creatorVisible()` scope and the narrow
  `CreatorCampaignAttachmentResource` are shared, byte-identical, across both read doors — proven by
  break-revert #1 (the scope) and #3 (the card's structural exclusion), and the two-door independence
  is proven by cases 3 and 5 of the dedicated read-path test.
- **D5 (case matrix).** Every named case (1, 2, 3, 5, 6, 13, plus the flat-list and card-leak fences)
  has a dedicated Pest case in `CreatorCampaignAttachmentReadPathTest.php`, all green in the full
  suite run (§4).
- **D6 (scope fences).** Restated as shipped in §5; nothing in that list was found built beyond the
  fence during this review's re-read of the shipped code.
- **Q1, corrected rather than merely confirmed.** The plan-pause's "unreachable-or-harmless" framing
  survives for creators (verified, §1) but was WRONG for the agency side as originally drafted — this
  session caught and fixed a real 500-on-list defect before it shipped, rather than documenting a
  false "display inconsistency, not a crash" claim. This is the substantive finding of this review
  pass, recorded in `tech-debt.md` with the corrected framing and a dedicated regression test.
- **Q2 (agency-side URL minting), ratified as built.** `CampaignAttachmentResource` mints
  `download_url` via the AH-091 signer verbatim, matching the inventory's position over the plan's
  original volume-argument framing.
- **Q3 (E2E feasibility gate), ratified as built.** The render-only leg landed
  (`creator-jobs-board.spec.ts`); the download-click was evaluated and found infeasible in this
  environment, with the blocking mechanism named as an extension of the existing AH-091 debt line.
- **The full local gate board is green** on every surface this chunk touches — backend (Pest,
  PHPStan, Pint), frontend (Vitest, vue-tsc, ESLint, Prettier), and this chunk's own Playwright leg,
  isolated and in the full board. The three Playwright failures present in the full-board run are
  verified pre-existing and environment-tied (zero touched files across both apps' 2FA/MFA surfaces),
  not a regression from this work — named honestly rather than silently waved through, per the
  AH-091 review's own precedent for environmental gaps.

**Status: Closed — approved, push HELD. Awaiting Pedram's push instruction; CI's own run at the
pushed tip is this chunk's actual closing verification, per the build request's own terms.**
