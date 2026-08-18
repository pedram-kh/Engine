# Minimal rich text: links + bold/italic + breaks in briefs and descriptions (AH-081)

- **Status: Landed (push HELD).** Full loop — kickoff with locked decisions (D1–D6) → plan-pause
  (six proposals ratified verbatim) → build → two-commit pair, push held. Review file is the
  record, pending the close instruction.
- **Date:** 2026-08-18/19
- **Provenance:** built by Cursor directly against Pedram's kickoff (D1–D6 below, locked at
  kickoff time); no separate independent-review round in this loop.
- **Evidence base:**
  [`admin-filter-profile-modal-richtext-inventory.md`](admin-filter-profile-modal-richtext-inventory.md)
  §0.2, I2.1–I2.3 — the three-field render-site survey, the sanitizer-reality finding (no markdown
  lib existed yet outside `useBioRenderer.ts`), and the length-cap inventory this chunk builds
  from. Re-verified against the post-AH-080 tip at plan-pause; all cited line numbers below are
  current as of this build.
- **§5.40 risk: LOW, as declared at kickoff.** The risk center is a new sanitized-HTML render
  surface for agency-authored content in a CREATOR's browser — genuine cross-party trust boundary,
  most acute on `CreatorJobDetailPage.vue`. Treated with XSS-grade review: see
  [§3](#3-d3--the-xss-set-and-the-real-break-revert-case).

---

## 1. What shipped, against the kickoff's D1–D6

| Decision | Asked                                                                                                                                        | Shipped                                                                                                                            |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **D1**   | One shared sanitized renderer, `renderBio`'s tighter sibling — 5-tag allowlist, http(s)-only links, forced `noopener noreferrer` + `_blank`. | Held. `useRichBriefRenderer.ts` (`renderRichBrief`) + `RichBrief.vue`. See [§2](#2-d1--the-renderer-and-the-one-v-html-call-site). |
| **D2**   | All three field pipelines converted, every render site enumerated and converted, Field C checked.                                            | Held — 5 render sites converted, Field C confirmed same column/pipeline. See [§4](#4-d2--the-render-site-completeness-table).      |
| **D3**   | The XSS set on the security-relevant site (`CreatorJobDetailPage.vue`), incl. an in-spec break-revert case; the mail question re-verified.   | Held. See [§3](#3-d3--the-xss-set-and-the-real-break-revert-case).                                                                 |
| **D4**   | Length caps raised: `offer_description` 3000/3000, `campaigns.description` 5000 BE + FE mirror closed. No counter UI.                        | Held. See [§5](#5-d4--length-caps).                                                                                                |
| **D5**   | Editor stays a textarea + a formatting hint, 2 new i18n keys ×24 locales.                                                                    | Held. See [§6](#6-d5--the-editor-hint).                                                                                            |
| **D6**   | Legacy content renders identically; literal asterisks pre-feature now bold — stated honestly, no escaping strategy.                          | Held. See [§7](#7-d6--the-legacy-content-posture).                                                                                 |

---

## 2. D1 — the renderer and the one `v-html` call site

`useRichBriefRenderer.ts` exports `renderRichBrief(markdown)`, built on `markdown-it` + `DOMPurify`,
tighter than `useBioRenderer.ts` in every dimension the kickoff named:

| Axis                           | `renderBio` (existing)                                                                                                                                        | `renderRichBrief` (this chunk)                                                                                                                  | Why tighter                                                                                                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Allowed tags                   | `p, br, strong, em, a, ul, ol, li, code`                                                                                                                      | exactly `p, br, strong, em, a`                                                                                                                  | Briefs are prose, not documents — no lists, no code, no images, no headers.                                                                                                        |
| `breaks`                       | `false` (paragraph-only)                                                                                                                                      | `true`                                                                                                                                          | The "line breaks" ask — a single newline becomes `<br>`.                                                                                                                           |
| Link validation                | markdown-it's default `validateLink` (blocklists `vbscript:/javascript:/file:/data:` non-image, otherwise permissive — mailto/tel/protocol-relative all pass) | a custom `validateHttpOnlyLink` — a strict **allowlist** of `^https?:\/\//i`, everything else rejected before markdown-it emits an `<a>` at all | A brief's links are never mailto/tel/relative; narrowing to an allowlist closes what renderBio's blocklist leaves open.                                                            |
| `DOMPurify.ALLOWED_URI_REGEXP` | not pinned (library default)                                                                                                                                  | pinned to the same `^https?:\/\//i`                                                                                                             | Independent second defence — a regression in the first layer (e.g. a future `validateLink` override) still can't produce a non-http(s) `href`.                                     |
| Link `rel`/`target`            | `rel="noopener nofollow"`                                                                                                                                     | `rel="noopener noreferrer"` + `target="_blank"`                                                                                                 | The house-wide external-link posture (`DraftReviewPanel.vue`, `ChatPanel.vue`, `CreatorJobDetailPage.vue`'s own existing external links) rather than renderBio's older convention. |

`html: false` on the `markdown-it` instance escapes any raw HTML in the input to inert text at the
markdown layer — the same defence `useBioRenderer.ts` uses. This matters for the break-revert case
below: because raw `<script>` in the _markdown source_ never reaches DOMPurify as a real element,
proving the sanitizer's `ALLOWED_TAGS` array is itself load-bearing (not merely decorative behind
`html: false`) needs a dedicated test that bypasses markdown-it — see [§3](#3-d3--the-xss-set-and-the-real-break-revert-case).

**`RichBrief.vue`** is the one display component every render site imports — a single `v-html`
call site in the whole codebase for this content, so a future loosening of the allowlist has
exactly one place to audit. Followed the AH-063 precedent (`AuthFooterMonogram.vue`): an inline
`<!-- eslint-disable-next-line vue/no-v-html -->` immediately above the directive, with a
justification comment citing `useRichBriefRenderer.ts`. **One structural correction found during
build and fixed before it shipped**: a leading HTML comment directly before the template's root
element turns Vue into treating the template as a multi-root fragment, which silently defeats
`$attrs` fallthrough (`data-test`/`class` stopped reaching the rendered DOM in a build-time repro —
verified, then fixed). The fix: the outer `<div>` is the sole root with **no** leading comment; the
`v-html` + its eslint-disable comment live one level down, on an inner `<div>` — the same shape
`AuthFooterMonogram.vue` already uses for exactly this reason.

`useRichBriefRenderer.spec.ts` — **12 passed**. `RichBrief.spec.ts` — **4 passed** (prop → sanitized
HTML, attrs fallthrough proving the fragment fix holds, no `<script>` even given raw HTML input,
null/undefined/empty safety).

---

## 3. D3 — the XSS set, and the real break-revert case

The security-relevant site is `CreatorJobDetailPage.vue` — agency-authored `campaigns.description`
rendered in a **creator's** browser, the one place in this chunk where the content genuinely
crosses a trust boundary. `useRichBriefRenderer.spec.ts`'s XSS set:

| Case                                                                           | Result                                                                                                                                                                                      |
| ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `<script>` tags in the source                                                  | Escaped to inert text by `html: false` (never a real element DOMPurify has to strip).                                                                                                       |
| Inline event handlers (`onclick`, `onerror`) on a raw `<a>` in the source      | Never appear on any REAL `<a>` tag — the only real anchor in that scenario is the one `linkify: true` auto-generates, and `RichBrief`'s own attribute set is exactly `href`/`target`/`rel`. |
| A markdown-syntax link with a `title` annotation carrying an injection attempt | `title` is not in `ALLOWED_ATTR` — DOMPurify drops it whole, so nothing of the attempt survives.                                                                                            |
| `javascript:` href                                                             | Rejected by `validateHttpOnlyLink` before markdown-it emits an `<a>` — no anchor at all, not a neutered one.                                                                                |
| `data:`, `file:`, `vbscript:`, `mailto:`, `tel:`, protocol-relative `//` hrefs | All rejected by the same allowlist — none produce an `<a>`.                                                                                                                                 |
| Disallowed tags (`h1`, `ul`, `code`, `img`) from real markdown syntax          | Stripped — none of `<h1`/`<ul`/`<code`/`<img` survive.                                                                                                                                      |

**The break-revert case, made real rather than hypothetical.** An earlier draft of this case just
called `renderRichBrief('<script>...')` and asserted no `<script>` in the output — but that
assertion would pass even with an EMPTY `ALLOWED_TAGS`, because `html: false` already neutralizes
raw `<script>` in the markdown source before DOMPurify ever sees it as a real element. That doesn't
prove the allowlist array itself matters. Fixed: `ALLOWED_TAGS` is now an exported, live constant
from `useRichBriefRenderer.ts`, and the break-revert test sanitizes a **raw** `<script>` string
directly against DOMPurify with that same exported reference — bypassing markdown-it entirely, the
realistic model of "some future code path calls DOMPurify with this allowlist directly." **Verified
both directions during build**: temporarily widened the source's `ALLOWED_TAGS` to
`[..., 'script']` — the break-revert assertion went **red** exactly as required
(`expected [ Array(6) ] to not include 'script'`); reverted, and the suite is green again. This is
the in-spec proof the kickoff asked for, not a claim.

**Site-level proof, not just unit-level.** `CreatorJobDetailPage.spec.ts` gained 4 cases mounting
the real page against a mocked API response: bold/italic/link render as real HTML with the correct
`rel`/`target`, a `<script>` in the description never executes (`window.__pwned` stays
`undefined`), a `javascript:` href produces zero `<a>` tags, and a bare newline becomes a real
`<br>`.

**The mail question, re-verified.** Neither `offer_description` nor `campaigns.description` flows
into any mail template — confirmed by re-grepping the mail views/mailables for both column names
during the plan-pause read; no result. Mail stays plaintext this chunk, as the inventory forecast
and the kickoff recorded.

---

## 4. D2 — the render-site completeness table

The seven-row proof, before/after, cross-checked against the inventory's grep at plan-pause and
re-verified now:

| #   | Field                                                        | Site                                                                                                                                                                                    | Before                                          | After                                                                       |
| --- | ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- | --------------------------------------------------------------------------- |
| 1   | `offer_description`                                          | `CreatorAssignmentsPage.vue` (creator assignment list)                                                                                                                                  | `{{ item.attributes.offer_description }}`       | `<RichBrief :text="item.attributes.offer_description" />`                   |
| 2   | `offer_description`                                          | `CreatorAssignmentDetailPage.vue` (creator assignment detail)                                                                                                                           | `{{ assignment.attributes.offer_description }}` | `<RichBrief :text="assignment.attributes.offer_description" />`             |
| 3   | `offer_description`                                          | `BoardCardDrawer.vue` (agency board drawer, Detail tab)                                                                                                                                 | `{{ offerDescription }}`                        | `<RichBrief :text="offerDescription" />`                                    |
| 4   | `campaigns.description`                                      | `CampaignDetailPage.vue` (agency campaign overview)                                                                                                                                     | `{{ campaign.attributes.description }}`         | `<RichBrief :text="campaign.attributes.description" />`                     |
| 5   | `campaigns.description`                                      | `CreatorJobDetailPage.vue` (creator job-board detail — **the XSS-critical site**)                                                                                                       | `{{ job.attributes.description }}`              | `<RichBrief :text="job.attributes.description" />`                          |
| 6   | `campaigns.description` (AH-054 listing "description" field) | **Field C finding**: the listing-floor's `description` is the SAME `campaigns.description` column, not a separate field — no separate render site exists; sites #4/#5 already cover it. | —                                               | No change needed — one column, one pipeline, confirmed rather than assumed. |
| 7   | `brands.description`                                         | Out of scope, as decided at kickoff — a different entity's field, no render pipeline touched this chunk.                                                                                | —                                               | Untouched.                                                                  |

Every conversion kept the pre-existing `data-test`/`data-testid` selector on `RichBrief` itself
(fallthrough attrs, not a wrapper), so no unrelated spec needed a selector change — the five
existing render-site specs (`CreatorAssignmentsPage`, `CreatorAssignmentDetailPage`,
`BoardCardDrawer`, `CampaignDetailPage`, `CreatorJobDetailPage`) all re-run green with their
pre-existing assertions **unmodified**, each gaining one or more new cases proving the markdown
actually became HTML (not just that the plain-text case still reads right).

---

## 5. D4 — length caps

| Field                        | Before                 | After              | Where                                                                                 |
| ---------------------------- | ---------------------- | ------------------ | ------------------------------------------------------------------------------------- |
| `offer_description` (BE)     | `max:2000`             | `max:3000`         | `ValidatesAssignmentOffer.php:48`                                                     |
| `offer_description` (FE)     | `maxlength="2000"`     | `maxlength="3000"` | `OfferFieldsForm.vue`                                                                 |
| `campaigns.description` (BE) | `max:5000` (unchanged) | `max:5000`         | `CampaignRequest`'s validation (unchanged this chunk)                                 |
| `campaigns.description` (FE) | no `maxlength` (gap)   | `maxlength="5000"` | `CampaignForm.vue` — the FE mirror gap closed, matching the BE cap for the first time |

The one boundary test that pins the old cap moved with it:
`CampaignApplicationAcceptTest.php:519` — `str_repeat('x', 2001)` → `str_repeat('x', 3001)`,
re-verified 422 at the new boundary. No counter UI added, per the minimalism decision — none
existed before, and D4 explicitly declined to introduce one.

---

## 6. D5 — the editor hint

Both editors stay a plain `<v-textarea>` — no toolbar editor this chunk, the deliberate cheap
choice, upgrade path named at kickoff (a rich-text toolbar component, if creator/agency feedback
ever asks for one). Net-new copy is exactly 2 leaves, phrased on the existing bio-hint convention
(`creator.json:169`'s `bio_help`), adapted to mention line breaks:

| Key                                   | Site                                                                                                                                                                                                              | Text (en)                                                               |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `app.campaigns.invite.formattingHint` | `OfferFieldsForm.vue` — a wholly new hint (none existed; `hide-details` removed so it renders).                                                                                                                   | "You can use **bold**, _italics_, links, and line breaks."              |
| `app.campaigns.fields.formattingHint` | `CampaignForm.vue` — concatenated onto the existing `descriptionHint`, not replacing it (`${descriptionHint} ${formattingHint}`), the same concatenation shape `ProfileBasicsForm.vue` uses for its own bio hint. | Same text, appended after the existing deliverables/licensing guidance. |

**24 locales, real per-locale MT — not English placeholders.** Unlike `creator.json`'s `bio_help`
(which has several locales still carrying verbatim English text, a pre-existing gap this chunk
didn't touch), `app.json`'s neighbourhood (`descriptionHint`, `descriptionLabel`) is fully
translated in every locale, so the new `formattingHint` key matches that established quality bar
for this specific file rather than the lower one. **Flaky-10 spot-check** (random sample:
`mt, lt, ga, nl, ro, hu, lv, bg, en, da`) — all ten carry distinct, non-English copy (except `en`
itself) for both leaves; none fell back to English or resolved `undefined`.
`i18n-locale-parity.spec.ts` (keyset + placeholder + plural-form parity across all 24 locales)
passed as part of the full suite.

---

## 7. D6 — the legacy content posture

**Stated plainly, as decided:** existing `offer_description`/`campaigns.description` content
written before this chunk renders through the same `renderRichBrief` pipeline as new content —
there is no separate "legacy" code path. Paragraph and line-break semantics are preserved for
plain-text content (a brief with no markdown syntax renders its visible text unchanged, just
wrapped in a `<p>`). **The one behaviour change, named rather than silent:** any brief that
happened to contain literal `**` or `*` characters before this feature shipped will now render as
bold/italic emphasis instead of literal asterisks, because there is no way for the renderer to
distinguish "asterisks a user typed as punctuation" from "asterisks meant as markdown." This is the
exact same posture `useBioRenderer.ts` already ships for creator bios — precedent-backed, not a new
risk category. **No escaping strategy implemented**, per the kickoff's decision; this is accepted
as the honest trade-off of adding markdown support to a field that previously had none.

---

## 8. Gate board

| Gate                                                                    | Result                                                                                                                                                                                                                                                                                                                                                      |
| ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full suite                                              | **2529 passed, 1 skipped** · 9387 assertions                                                                                                                                                                                                                                                                                                                |
| Backend, Campaigns-scoped filter                                        | **529 passed** · 2242 assertions                                                                                                                                                                                                                                                                                                                            |
| `CampaignApplicationAcceptTest.php` (targeted — the boundary-test diff) | **27 passed**                                                                                                                                                                                                                                                                                                                                               |
| PHPStan (project-wide)                                                  | **0 errors** (919 files)                                                                                                                                                                                                                                                                                                                                    |
| Pint (project-wide)                                                     | **passed**                                                                                                                                                                                                                                                                                                                                                  |
| Frontend (Vitest), full `apps/main` suite                               | **1618 passed / 164 files**                                                                                                                                                                                                                                                                                                                                 |
| `useRichBriefRenderer.spec.ts` (new)                                    | **12 passed**, incl. the XSS set + the verified-both-directions break-revert case                                                                                                                                                                                                                                                                           |
| `RichBrief.spec.ts` (new)                                               | **4 passed**                                                                                                                                                                                                                                                                                                                                                |
| `CreatorAssignmentsPage.spec.ts` (site #1)                              | **6 passed** (1 new — HTML conversion proof), pre-existing cases unmodified                                                                                                                                                                                                                                                                                 |
| `CreatorAssignmentDetailPage.spec.ts` (site #2)                         | **42 passed** (2 new), pre-existing cases unmodified                                                                                                                                                                                                                                                                                                        |
| `BoardCardDrawer.spec.ts` (site #3)                                     | **35 passed** (1 new), pre-existing cases unmodified                                                                                                                                                                                                                                                                                                        |
| `CampaignDetailPage.spec.ts` (site #4)                                  | **42 passed** (1 new), pre-existing cases unmodified                                                                                                                                                                                                                                                                                                        |
| `CreatorJobDetailPage.spec.ts` (site #5 — XSS-critical)                 | **26 passed** (4 new: HTML render, script-strip, javascript-href, line-break), pre-existing cases unmodified                                                                                                                                                                                                                                                |
| `i18n-locale-parity.spec.ts` (`apps/main`)                              | **passed** — keyset, placeholder, and plural-form parity across all 24 locales, incl. the 2 new leaves                                                                                                                                                                                                                                                      |
| `vue-tsc --noEmit` (`apps/main`, project-wide)                          | **clean**                                                                                                                                                                                                                                                                                                                                                   |
| ESLint (`apps/main`, project-wide)                                      | **0 errors, 2 pre-existing `vue/no-v-html` warnings unrelated to this chunk's files** (`ClickThroughAccept.vue`, `ProfileBasicsForm.vue`) — 0 new                                                                                                                                                                                                           |
| Playwright, full `apps/main` suite                                      | **27 passed, 1 failed** — the failure (`2fa-enrollment-and-sign-in.spec.ts`) is pre-existing and unrelated: reproduced identically after `git stash`-ing this entire chunk's diff and re-running against the clean pre-AH-081 tip (same locator timeout, same step). Not a regression from this chunk; CI's last run at this tip (`32120322693`) was green. |
| Playwright, `creator-jobs-board.spec.ts` (c3 leg)                       | **passed** — the seeded description now carries a `**bold**` fragment, and the spec asserts a real `<strong>` element in the rendered job-detail page, proving the trust-boundary conversion end to end, not just at the unit level.                                                                                                                        |

---

## 9. What this chunk deliberately did not do

- **No toolbar editor.** Textarea + hint only, per D5 — the cheap choice, upgrade path named.
- **No escaping strategy for legacy asterisks.** Per D6 — the honest, precedent-backed trade-off.
- **No counter UI.** Neither field had one before; D4 didn't add one.
- **No mail-safe variant of the renderer.** Neither field flows into a mail template — re-verified,
  not assumed.
- **No change to `brands.description`** or any other field outside the three named pipelines.
