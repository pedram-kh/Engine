# AH-049 — Master Agreement Content Refresh + Version Bump to v1.1

**Status:** Closed — approved
**Reviewer:** Claude (independent review) — incorporating implementation details from Cursor's draft
**Reviewed against:** `WORKING-PROCESS.md` §4–§6, `PROJECT-WORKFLOW.md` §5 (esp. §5.34/§5.35/§5.40), `docs/reviews/adhoc-changes-log.md` (AH-029, AH-028), `docs/tech-debt.md` (contract version-label ambiguity)

## Scope

- Replace the server-rendered click-through master creator agreement content
  (`apps/api/resources/contracts/master-agreement.en.md`) with the supplied
  Catalyst Creator Terms & Conditions PDF.
- Bump the agreement version label `1.0 → 1.1` (single owner:
  `ContractTermsRenderer::CURRENT_VERSION`), plus the in-file `**Version:**` line.
- Strengthen the two content-coupled Pest tests so the swap is actually guarded,
  verified by break-revert.
- Add a §5.34 snapshot-immutability case (pre-swap `1.0` row byte-untouched).
- No re-consent flow (deferred, AH-029 counsel thread); no migration; no i18n change.

## What changed in the content (vs the previous live markdown)

| Clause | Change                                                                                                                                                                              |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2.4    | **New** — revision rounds: up to three (3) per Deliverable unless the Brief specifies otherwise; further amendments are a Change under 2.3 only on Catalyst's written confirmation. |
| 4.3    | **New** — Fees payable within 30 days of Catalyst's wave sign-off; wave-spanning rule.                                                                                              |
| 7.3    | **Expanded** — restores the portfolio-consent request mechanism (request written consent for portfolio use; granted at discretion; no standing right).                              |

Unchanged: contracting entity (Catalyst Performance Ltd, company number 13632394),
governing law (England & Wales, clause 10.6), the 10-clause structure, the H1
(`# Catalyst Creator Terms and Conditions`), and the `## 2. Services` heading (both
kept byte-identical as the test contract).

## Transcription deviations (fidelity record)

Every deliberate departure from the literal PDF text. This list is what makes
"faithful" verifiable rather than asserted (per the kickoff ruling):

1. **Privacy-notice URL** — the PDF clause 10.4 carries the drafting placeholder
   `[INSERT LIVE PRIVACY NOTICE URL]`. Substituted with the live URL already in the
   previous markdown: `https://www.catalyst-growth.com/legal/privacy`. The placeholder
   is a drafting instruction, not a term. (Q1 ruling.)
2. **"Deliveable" → "Deliverable"** — the PDF misspells the capitalized defined term
   "Deliverable" twice (clauses 4.3 and 7.3). Corrected, because transcribing a typo of
   a defined legal term literally would introduce ambiguity rather than preserve meaning.
   (Q2 ruling.)
3. **Punctuation normalization** — en-dash "–" and curly apostrophes/quotes in the PDF
   normalized to the surrounding markdown's conventions (em-dash "—", straight `'`/`"`),
   and a missing space (`Deliverables.Creator` in 7.3) restored. No wording change.
4. **Page-marker stripping** — the PDF's `-- 1 of 4 --` … `-- 4 of 4 --` footers (which
   interleave mid-clause) are not agreement body and were removed.

No tables, footnotes, or nested numbering beyond the (a)–(e) warranties and the 10.7
definitions, both rendered as bulleted lists matching the existing markdown convention.

## Version mechanism

- `ContractTermsRenderer::CURRENT_VERSION` `'1.0' → '1.1'` is the single owner; the
  terms endpoint, the click-through snapshot write, and the SPA label all derive from it.
- **Integer column `contracts.version` stays `1`** — `(int)'1.1' === 1`, the documented
  lossy major-version mapping. The **precise `signed_signature_data.version` string**
  (`'1.1'` for new acceptances) and the **body_markdown snapshot** are the authority.
- **No code compares version labels** — re-verified post-AH-042/048. The only other
  `.version` references in the codebase are the unrelated `CampaignDraft` revision counter.
- The SPA label `"Master Creator Agreement v{version}"` interpolates the version at
  runtime → **no locale file changed**, parity unaffected.

## Production posture (§5.40)

- **PROD-DATA RISK: NONE.** No migration, no backfill, no data-mutation command, no write
  to any existing row. The change is a resource markdown file + one PHP constant + tests.
- **What the feature writes:** new click-through acceptances snapshot the new body + title
  - precise `'1.1'` onto a fresh `contracts` row (unchanged write path).
- **What happens to existing rows:** nothing. Existing signed contracts keep their
  immutable accept-time snapshots and their `'1.0'` string. The idempotency guard
  (`click_through_accepted_at !== null`) short-circuits re-entry, so no path re-snapshots.
  The historical backfill migration (`2026_05_17_100001`) is unchanged, still hardcodes
  `'1.0'`, and is not re-run.
- **Blast radius of a bug:** a rendering/transcription error would show wrong terms to
  _new_ signees only; it cannot corrupt or re-label existing signed rows. The §5.34 case
  below pins that invariant.
- **Deferred (conscious):** no re-consent for pre-swap signees. Legal soundness of that is
  a counsel question (AH-029 thread), not blessed by this engineering review.

## §5.34 evidence — snapshot immutability

`ClickThroughContractRecordTest.php` gains `it('leaves a pre-swap (v1.0) contract snapshot
byte-untouched after the source + version bump', …)`: it plants a row carrying the OLD body
and `'1.0'` string, confirms the source is now v1.1 (`CURRENT_VERSION === '1.1'`, source
contains `**2.4**`), re-enters the accept endpoint, then asserts the planted row is
unchanged (`body_markdown` equals the old text and does NOT contain `**2.4**`,
`version === 1`, `signed_signature_data.version === '1.0'`) and no duplicate row was minted.

## Break-revert evidence (§5.35) — the strengthened pins are not content-blind

Mutation: delete clause 2.4 from `master-agreement.en.md`. Expectation: the new content
pins red; the old (H1/`2. Services`/version-via-constant) pins alone would have stayed green.

Broken run (verbatim, filtered):

```
  FAIL  Tests\Feature\Modules\Creators\ClickThroughContractRecordTest
  ✓ it creates a contracts row with the correct version, signer, timest…
  ⨯ it snapshots the agreed title and RAW markdown source (not rendered…
  ✓ it records method + ip + user_agent + version + accepted_at in sign…
  ✓ it sets creators.signed_master_contract_id to the new contracts row…
  ✓ it step-8 satisfaction keys off signed_master_contract_id, not the …
  ✓ it is idempotent — a second accept creates no duplicate contracts r…
  ✓ it makes acceptances retrievable WITH version from one place (contr…
  ⨯ it leaves a pre-swap (v1.0) contract snapshot byte-untouched after …
  ✓ it never exposes signed_signature_data (IP/UA) on the creator-facin…
  FAIL  Tests\Feature\Modules\Creators\ContractTermsEndpointTest
  ⨯ it returns the rendered HTML, version, and locale for the authentic…
  ✓ … (remaining endpoint tests green)
  Tests:    3 failed, 14 passed (80 assertions)
```

Revert (re-insert clause 2.4 — restores the intended v1.1 file, NOT `git checkout`, which
would have wiped the whole v1.1 rewrite back to committed v1.0) → clean `git diff`
(clause 2.4 present, `**Version:** 1.1 — Effective 2026-07-17`) → re-green:

```
  PASS  Tests\Feature\Modules\Creators\ClickThroughContractBackfillTest
  PASS  Tests\Feature\Modules\Creators\ClickThroughContractRecordTest
  PASS  Tests\Feature\Modules\Creators\ContractTermsEndpointTest
  Tests:    20 passed (104 assertions)
```

## Verification results

| Gate                                                   | Result                                                                                                                            |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| Backend Pest (full, serial, 2G)                        | ✅ 1870 passed, 1 skipped, 6604 assertions                                                                                        |
| Pint `--all` (CI-authoritative, §5.18)                 | ✅ passed                                                                                                                         |
| PHPStan / Larastan (`--memory-limit=2G`)               | ✅ no errors (823 files)                                                                                                          |
| apps/main Vitest                                       | ✅ 1187 passed (3 concurrent-load 5s-timeout flakes in `roster`/`campaigns`, re-run green in isolation — unrelated to onboarding) |
| apps/main vue-tsc                                      | ✅ clean                                                                                                                          |
| apps/main ESLint                                       | ✅ 0 errors (2 pre-existing `vue/no-v-html` warnings, incl. the intentional trusted-render in `ClickThroughAccept.vue`)           |
| api-client typecheck + Vitest                          | ✅ clean, 196 passed                                                                                                              |
| Locale parity (23 non-en)                              | ✅ all PASS (no locale files touched)                                                                                             |
| Playwright `creator-wizard-happy-path` (contract step) | ✅ 1 passed — new longer content traverses the AH-028 scroll gate                                                                 |
| Prettier `--check` (edited md + vue)                   | ✅ clean                                                                                                                          |

## Notes for the reviewer

- **Frontend `ClickThroughAccept.spec.ts`** mocks the endpoint with an arbitrary
  `version: '1.0'` sample and asserts the label interpolates it — it tests the component's
  version-agnostic interpolation, **not** the backend constant, so it stays green and was
  intentionally left untouched (updating the mock to `'1.1'` would add no guard value and
  risks implying a coupling that does not exist).
- **`ContractFactory`** default (`'1.0'` string, `'Master Creator Agreement'` title) left
  untouched per Q5 — it models an arbitrary historical row; coupling it to `CURRENT_VERSION`
  would make history drift with the present.
- Playwright required the local dev stack down (:8000/:5173) and ran against the isolated
  `catalyst_e2e` DB. Dev stack left down per Pedram's instruction; Chromium was installed
  for the runner during this session.

## Cross-chunk note

None this round. The AH-028 scroll gate and the e-sign vendor bridge (sentinel path,
does not read the markdown) are untouched and were re-verified during the read pass.

---

_Provenance: independent review complete (transcription-deviations block verified against the Q1–Q5 rulings; §5.34 immutability case verified; break-revert confirmed content-blindness fixed — 3 pins red on clause deletion, clean re-insert restore; production posture verified NONE; engineering-only boundary stands, AH-029 counsel thread open) — drafted by Cursor, reviewed and closed by Claude._
