# Agency Files library, chunk A — REVIEW

- **Status: Closed — approved, pushed.**
- **Date:** 2026-08-27
- **Entry:** AH-091.
- **Built from:** [`agency-files-plan.md`](agency-files-plan.md) (D1–D7) and
  [`agency-files-inventory.md`](agency-files-inventory.md) (I1–I6, plus its own
  "Corrections" section, added post-plan-pause).
- **Commits:** `706a0b6e` (the feature — table, service, controller/policy/resource, SPA
  surface, i18n ×24, Pest + Vitest coverage) · `4447c4c4` (the docs pass — this review, the
  plan, the inventory's corrections, the data-model/master-architecture/tech-debt updates, the
  ad-hoc log + deploy-log + resumption-template entries) · the close commit carrying this verdict
  (cannot record its own hash — `git rev-parse --short HEAD` is the authority, re-derive it, do
  not carry this session's number forward).
- **CI at the pushed tip (§5.41):** see the close-out note at the bottom of this file — filled in
  once the push's run is confirmed green, per Pedram's explicit instruction not to wave through a
  red run.
- **Reviewed against:** `docs/00-MASTER-ARCHITECTURE.md` §4 (tenancy), `docs/03-DATA-MODEL.md`
  §16/§20, `docs/02-CONVENTIONS.md`, `docs/05-SECURITY-COMPLIANCE.md`, `docs/tech-debt.md`.

---

## §0. What shipped, in one paragraph

Agencies get a new left-menu **"Files"** section — deliberately not "Drive": no folders, no
sharing, no preview. Any agency member can upload a document (images + PDF/Office/text/CSV, up
to 25 MB, no video), see it listed newest-first with server pagination, and download it via a
freshly-signed, forced-`attachment` URL. Admin and manager roles can delete a file, which
hard-deletes the S3 object synchronously before soft-deleting the row. Nothing in this chunk
links a file to a campaign or exposes it to a creator — that is chunk B, and this chunk ships a
guard seam (`hasAttachments()`, vacuously false today) so chunk B adds a query, not a redesign.

---

## §1. Production posture

### The risk line, and why it does not climb past LOW-MEDIUM

> **⚠️ PROD-DATA RISK: LOW-MEDIUM**, as re-derived at the plan-pause and unchanged by the build.
> One migration, and it is **additive only** — a `CREATE TABLE`, no existing table touched, no
> column added anywhere, no backfill. What DOES carry real risk, and is the reason the line does
> not read LOW: this is a genuinely new user-upload surface writing real objects into the shared
> `media` bucket, and its delete path performs a real, synchronous, irreversible S3
> `DeleteObject` call. Both are new capability, not schema risk.

**`down()` honesty.** `dropIfExists('agency_files')` is a true structural inverse with a lossy
content inverse: a rollback discards every row (and, if the objects were themselves cleaned up in
step with the rollback, every reference to them) but never touches the S3 objects themselves —
those are inherently outside migration `down()`'s reach. A rollback followed by a redeploy comes
back with an empty table; nothing auto-repopulates it.

**Blast radius of a bug, worst first.** (1) A cross-tenant read/delete — closed by the explicit
`$file->agency_id === $agency->id` check before policy (break-revert #1, §4). (2) A forged
`storage_key`/`upload_id` reaching outside the caller's own agency prefix — closed by the
`str_starts_with($file->storage_key, "agencies/{$agency->ulid}/files/")` re-assert on both
`completePresignedUpload()` and `delete()`, defense-in-depth over the caller's own agency-keyed
path construction. (3) A failed S3 delete silently soft-deleting the row anyway, orphaning live
bytes behind an invisible row — closed by `StorageDeleteFailedException` + object-first ordering
(break-revert #3, §4). (4) An inline-rendered malicious upload from the bucket's own domain —
mitigated two ways: the MIME allowlist excludes `text/html`/`image/svg+xml` (inherited from the
shared `AttachmentMimeTypes` set, unchanged by this chunk), AND this surface's own signed reads
force `Content-Disposition: attachment` (D4, break-revert #2, §4) — belt AND suspenders, where
the six pre-existing signed-read call sites still only have the belt (named in `tech-debt.md`).

### Server-side metadata verification — a deliberate improvement on the template it copies

`AgencyFileUploadService::completePresignedUpload()` never trusts the client's declared MIME type
or byte count past `initiatePresignedUpload()`. On complete, it derives the MIME type from the
**server-chosen extension** (not the client's `Content-Type`) and reads the **landed object's
actual size** via `Storage::size()`, rejecting an empty (0-byte) or over-25MB object outright. This
is stricter than every one of the five upload services it otherwise follows the shape of, none of
which re-derive size/MIME from the landed object on complete — they trust the `declared_bytes` /
`mime_type` from `init` through to persistence. **Named here so the next presigned-upload surface
copies this one, not the other five** — see `tech-debt.md`'s AH-091 entry, item 4, for the
follow-up (a shared base class is NOT this chunk's job, but this specific improvement is cheap to
carry forward per-surface even before that base class exists).

---

## §2. The three ratified corrections to the inventory

Recorded in full in `agency-files-inventory.md`'s own "Corrections" section (added post-plan-pause,
not silently folded into the original prose). Summarized:

1. **AV-debt surface count.** The inventory's "four, soon a fifth" undercounted —
   `AssignmentContractUploadService` (contract-PDF attachments) carries the identical no-AV-scan
   posture and was omitted from every prior tally. With this chunk's `AgencyFileUploadService`
   added, the count is **six**, corrected in `tech-debt.md`'s AH-010a entry rather than preserved.
2. **The disposition claim.** "No `Content-Disposition: attachment` override anywhere in the
   codebase" was wrong — `PortfolioItemPresenter::signedUrl()` already sets one. This chunk's
   `signedDownloadUrl()` is the **second** precedent, not the first, and copies the pattern rather
   than inventing it.
3. **`03-DATA-MODEL.md` §16 distinction.** §16 describes a generic `files` registry that was never
   built — every real upload path, `agency_files` included, writes its own narrow table instead.
   §16 now carries an explicit note plus the shipped `agency_files` table shape, including the
   `varchar(128)` `mime_type` (§16's stale `varchar(64)` default is too narrow for several Office
   MIME strings — a detail that would have bitten at runtime had it been copied verbatim).

---

## §3. Review priorities, addressed in the kickoff's own order

1. **Tenancy.** `AgencyFileController` checks `$file->agency_id === $agency->id` explicitly
   BEFORE calling the policy (route binding precedes tenancy context) and `abort(404)`s on a
   mismatch — not 403, so existence is never leaked cross-tenant. `AgencyFileUploadService`
   re-asserts the `agencies/{agency}/files/` storage-key prefix on both `completePresignedUpload()`
   and `delete()`, independent of the controller check. Proven by break-revert #1 (§4) and the
   dedicated `it 404s (not 403) on cross-tenant show and delete` test.
2. **D4's disposition, on the actual URL.** `AgencyFileSignedDownloadDispositionTest` parses the
   real presigned URL's query string (dummy S3 credentials, local computation — no live AWS call)
   and asserts `response-content-disposition` equals `attachment; filename="..."`, not merely that
   the calling code intends to pass the option. Proven by break-revert #2.
3. **Delete's object-first ordering + invisibility.** `AgencyFileUploadService::delete()` calls
   `Storage::disk('media')->delete()` and inspects its return value BEFORE the controller ever
   soft-deletes the row; a `false` return throws `StorageDeleteFailedException`, which the
   controller lets propagate as a 500 with the row untouched. Proven by break-revert #3 (both the
   service-level throw and the HTTP-level row-visibility assertion redden independently).
4. **The allowlist extraction, byte-identical.** `AttachmentMimeTypesTest` pins `ALL` to the exact
   pre-extraction 14 entries in order, and the three existing consumers
   (`MessageAttachmentUploadService`, `RelationshipMessageAttachmentUploadService`,
   `AssignmentOfferAttachmentUploadService`) now import `AttachmentMimeTypes::ALL` with their own
   test suites unchanged and green. Proven by break-revert #4 (removing one entry reds the pin
   test immediately).
5. **MIME/size rejection, including the video subtraction.** `AgencyFileUploadService` accepts
   `AttachmentMimeTypes::NON_VIDEO` only; three dedicated dataset cases assert each `VIDEOS` entry
   is rejected with `agency_file.presign_failed`, alongside zero-byte and over-25MB rejections at
   both `init` (declared) and `complete` (landed-object-derived) time. Proven by break-revert #5.
6. **i18n ×24.** `app.files.*` + `app.nav.files` landed in all 24 locales in the same pass as the
   English source of truth; the locale-parity architecture test (keyset + placeholder + plural-form
   parity) is green. Proven by break-revert #6 (removing one locale's placeholder-bearing key reds
   the parity test with the exact missing key named).
7. **Full board + CI at tip.** See §5. CI itself is not yet applicable — push is held (see header).

---

## §4. Break-revert proofs (all six, restored, clean diff verified after)

Each of the six was broken, watched red, then reverted; `git diff --stat` on every touched file
confirmed a clean restore (no leftover proof artifacts) before the commit.

1. **Tenancy 404.** Disabled `AgencyFileController::assertBelongsToAgency()`'s body →
   `it 404s (not 403) on cross-tenant show and delete` failed (`Expected... [404] but received
200`). Restored.
2. **D4 disposition.** Removed the `ResponseContentDisposition` option from
   `signedDownloadUrl()`'s `temporaryUrl()` call → both
   `AgencyFileSignedDownloadDispositionTest` cases failed (`Undefined array key
"response-content-disposition"`). Restored.
3. **Object-first delete.** Swallowed the `Storage::delete()` failure instead of throwing →
   `it delete throws StorageDeleteFailedException...` failed (`Exception... not thrown`) AND
   `it leaves the row fully visible when the object delete fails` failed (`Expected... [500] but
received 204`, proving the row would have been silently soft-deleted over a live object).
   Restored.
4. **Allowlist byte-identity.** Removed `image/gif` from `AttachmentMimeTypes::IMAGES` →
   `AttachmentMimeTypesTest`'s two pinning cases failed immediately (array diff + count mismatch).
   Restored.
5. **Video subtraction.** Swapped `AttachmentMimeTypes::ALL` in for `NON_VIDEO` in
   `initiatePresignedUpload()`'s allowlist check → all three video-rejection dataset cases failed
   (`Undefined array key "video/webm"` — video suddenly resolved as an accepted extension).
   Restored.
6. **i18n parity.** Removed `app.files.delete.confirmMessage` (a placeholder-bearing key) from
   `fr/app.json` → the locale-parity spec failed, naming the exact missing key
   (`fr/app.json: MISSING app.files.delete.confirmMessage`). Restored.

---

## §5. Gate board

**Backend (`apps/api`).** Pest: **2852 tests, 0 failures** (10903 assertions) — full suite,
including all 37 Agency Files tests across four new test files. PHPStan: **0 errors**
(`level: 8`, full `analyse`). Pint: **passed** (full codebase `--test`).

**Frontend (`apps/main`).** Vitest: **170 files / 1673 tests, all passing** — full suite,
including the 19 new Agency Files tests (`files.api.spec.ts` 6, `AgencyFileUploadDialog.spec.ts`
3, `AgencyFilesPage.spec.ts` 10). `vue-tsc --noEmit`: clean. ESLint: 0 new errors. Prettier:
clean on all touched files.

**`packages/api-client`.** `tsc` typecheck: clean.

**Playwright — honest gap, not silently skipped.** The full existing board could not be executed
in this session: it requires a live Postgres instance (`php artisan serve` + `migrate:fresh`
against `catalyst_e2e`), and this sandbox has neither a reachable Postgres server nor a working
Docker daemon (`docker ps` fails with "Cannot connect to the Docker daemon"). This is an
environment limitation, not a decision to skip the suite — see `tech-debt.md`'s AH-091 entry,
item 3, for the resulting gap this leaves (no NEW E2E spec drives the presigned upload
round-trip, and there is no missing-receiver mechanism if a future nav item ships without one).
**CI runs the full Playwright board on every push** (per the repo's existing workflow) and will
be the actual verification that the new "Files" nav entry doesn't regress any spec enumerating
the agency sidebar — cite the CI run once Pedram triggers the push, per §5.41.

---

## §6. Scope fences, restated as shipped (D7)

- **No campaign linkage.** `AgencyFile` has no FK to any campaign table; `category`'s only value
  is `campaign_attachment`, naming the future use, not implementing it.
- **No creator visibility.** Nothing in this chunk's routes, policy, or SPA surface is reachable
  from the creator side.
- **No new disk or bucket.** `AgencyFileUploadService` uses the existing `media` disk, under the
  new `agencies/{agency}/files/` prefix.
- **No AV scanning.** Inherited gap, recorded as the sixth affected surface (§2, item 1).
- **No quota.** Considered and rejected at plan time — see `tech-debt.md`'s AH-091 entry, item 2,
  for the full reasoning (a count cap bounds nothing that matters, ignores orphans, needs
  atomicity and a tier rule that doesn't exist) and its trigger (a storage-economics or abuse
  signal, not a hypothetical).
- **No folders, no preview, no detail page.** The "not-Drive" fence — list, upload, download,
  delete is the entire chunk-A surface.

---

## §7. Tech-debt entries opened (four, plus the AV-count correction)

All recorded in full in `tech-debt.md`'s new **AH-091** section:

1. The six existing signed-read call sites still don't force `Content-Disposition: attachment` —
   this chunk fixes only its own reads (D4's scope fence, restated as debt).
2. No aggregate byte/count quota per agency (D7's named-unbuilt, with the rejected count-cap
   reasoning on record).
3. No Playwright spec for the Files surface itself, and no missing-receiver mechanism for future
   nav items shipping without one.
4. The MIME-allowlist extraction stopped at the constant, not the three-call presigned flow —
   `AgencyFileUploadService` is the sixth hand-duplicated copy of that flow, following the
   `AssignmentContractUploadService` template rather than a shared base class (D2's scope fence,
   restated as debt).

Plus the AV-surface-count correction (§2, item 1) landed in the pre-existing AH-010a entry rather
than a new one, since it corrects that entry's own tally.

---

## §8. Deploy obligation

One additive migration (`2026_08_27_130000_create_agency_files_table.php` — `CREATE TABLE`, ships
empty). No new flag, no new secret, no new bucket/disk. Full detail in
[`deploy-log.md`](../runbooks/deploy-log.md)'s AH-091 entry (stacked on the still-open AH-090
range, since neither has deployed yet).

---

## §9. Close-out verdict (2026-08-27, Pedram)

**D1–D7 and the plan's §3 E2E-and-quota rulings verified as built, not just claimed:**

- **D1–D3 (table, service, limits).** `agency_files`' shape, `AgencyFileUploadService`'s template
  lineage, the `NON_VIDEO` subtraction, and the 25 MB cap all match the kickoff exactly — confirmed
  by re-reading the shipped code against D1–D3, not just the test names.
- **The six break-reverts are load-bearing, not decorative** — each was independently broken,
  watched red with the exact failure message on record (§4), and restored with a clean diff. The
  delete-ordering proof (#3) is explicitly a **two-assertion** proof: the service-level
  `StorageDeleteFailedException` throw AND the HTTP-level row-visibility assertion both reddened
  independently from the same one-line break, confirming object-first ordering is enforced at both
  layers, not just the one the unit test happens to exercise.
- **The three-consumer allowlist extraction is verified byte-identical**, with the pre-existing
  `MessageAttachmentUploadService`, `RelationshipMessageAttachmentUploadService`, and
  `AssignmentOfferAttachmentUploadService` suites green and untouched — the extraction changed
  where the array lives, not what it contains or how any consumer behaves.
- **D4's disposition is proven on the actual URL**, not the calling code's intent — the
  break-revert parses the real presigned query string and asserts the literal
  `response-content-disposition` value, and removing the option reddens that exact assertion.
- **The server-side metadata verification is ratified as a deliberate improvement on its
  template**, and flagged in §1 for the next presigned-upload surface to copy rather than
  reinventing: deriving MIME from the server-chosen extension and re-reading the landed object's
  actual size on complete is stricter than any of the five surfaces this chunk otherwise follows
  the shape of.
- **The three inventory corrections are accepted** — the AV-surface count (four → six), the
  disposition-precedent correction (`PortfolioItemPresenter` predates this chunk), and the
  `03-DATA-MODEL.md` §16 distinction (shipped narrow library vs. the spec's unbuilt generic
  registry, plus the `mime_type` width fix) — all landed in the inventory file itself as an
  appended "Corrections" section, not a silent rewrite.
- **Q1 (E2E) and Q2 (quota), both ratified.** No Playwright spec for the surface itself this
  chunk — the round-trip is pinned at the API layer (Pest, against the real local-disk `media`
  driver) and the SPA layer (Vitest, against a mocked API); the actual browser-to-S3-to-browser
  leg is untested, named honestly in `tech-debt.md` rather than silently accepted. No per-agency
  quota — the count-cap rejection reasoning (bounds nothing that matters, ignores orphans, needs
  atomicity and an undefined tier rule) is on record as debt with an explicit trigger, not a
  silent omission.
- **The local Playwright gap is accepted as environmental, not a build shortfall.** This session's
  sandbox had neither a reachable Postgres nor a working Docker daemon — verified directly
  (`docker ps` fails to connect), not assumed. CI is the actual verification, run on the pushed
  tip below.

**Status: Closed — approved, pushed.**
