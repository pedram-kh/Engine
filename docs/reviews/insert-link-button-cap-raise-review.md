# Insert-link button + cap raise (AH-082)

- **Status: Closed — approved.** Compact full loop — kickoff with locked decisions (D1–D4) →
  brief plan-pause (all proposals ratified verbatim) → build → two-commit pair, push held.
  **Verdict:** D1–D4 verified as built; the zero-diff proof accepted as literal; the maxlength
  guard ratified as the chunk's catch; the shared component + single namespace as ruled; the
  found boundary gap closed and correctly named; the append-inner honesty note carried — if
  eyes-on reads it wrong, the fallback is a template-only swap, no reopen.
- **Date:** 2026-08-19
- **Provenance:** built by Cursor; kickoff (D1–D4) and plan-pause rulings by Claude (relayed and
  decision-owned by Pedram — the D3 caps are his product calls); independent review closure by
  Claude.
- **Evidence base:** the AH-081 build (`minimal-rich-text-review.md`) for the renderer/sanitizer
  boundary this chunk deliberately does not touch, and `RelationshipThreadView.vue`'s existing
  link-insert dialog (`linkUrl`/`linkName`/`addLink`, the `/^https?:\/\//i` validation) as the
  direct UI/i18n-phrasing precedent.
- **PROD-DATA RISK: NONE, as declared at kickoff.** Editor-only sugar writing markdown into a
  textarea, plus two validation-number changes. The stored format, the sanitizer, and every
  render site are untouched — see [§2](#2-d2--zero-pipeline-change-the-zero-diff-proof).

---

## 1. What shipped, against the kickoff's D1–D4

| Decision              | Asked                                                                                                                                                                 | Shipped                                                                                                                            |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **D1**                | An "Insert link" affordance on both editors — selection wraps as `[selection](url)`, no selection asks for a label and inserts `[label](url)`, cursor restored after. | Held. `InsertLinkButton.vue`, `append-inner` placement. See [§3](#3-d1--the-shared-component-placement-and-selection-mechanism).   |
| **D2**                | Zero pipeline change — the button writes markdown only; `RichBrief`/sanitizer/caps/render sites untouched; the dialog's check is UX-only.                             | Held — `git diff` on both renderer files returns nothing. See [§2](#2-d2--zero-pipeline-change-the-zero-diff-proof).               |
| **D3**                | `campaigns.description` 5000 → [Pedram's number], `offer_description` unchanged / [Pedram's number].                                                                  | Resolved: `campaigns.description` → **10000** (both requests), `offer_description` stays **3000**. See [§4](#4-d3--the-cap-raise). |
| **D4**                | Button tooltip/aria + dialog labels ×24 locales, flaky-10 MT; the two AH-081 hint keys amended, not duplicated.                                                       | Held — 8-key `app.campaigns.insertLink.*` namespace + 2 amended keys, all 24 locales. See [§5](#5-d4--i18n).                       |
| **Maxlength guard**   | Compute-before-insert; inline error naming the overage; never silent truncation.                                                                                      | Held — its own spec case. See [§6](#6-the-maxlength-guard).                                                                        |
| **Boundary-test gap** | `campaigns.description` had no length boundary test before this chunk — add it, named as a found gap.                                                                 | Held — two new tests (create + update), both sides of the new 10000 boundary. See [§4](#4-d3--the-cap-raise).                      |

---

## 2. D2 — zero pipeline change: the zero-diff proof

The kickoff's D2 claim was specific: this chunk writes markdown into the textarea and nothing
else — `RichBrief.vue`, `useRichBriefRenderer.ts`, the sanitizer allowlist, and every render site
stay byte-identical. Proven, not asserted:

```bash
$ git diff --stat -- apps/main/src/composables/useRichBriefRenderer.ts apps/main/src/components/RichBrief.vue
$ git status --porcelain -- apps/main/src/composables/useRichBriefRenderer.ts apps/main/src/components/RichBrief.vue
```

Both commands returned **empty output** — not "no functional diff," literally zero bytes
changed. This is why `InsertLinkButton.vue` duplicates the renderer's four-character
`/^https?:\/\//i` regex as its own `HTTP_URL_RE` constant rather than importing
`validateHttpOnlyLink` from `useRichBriefRenderer.ts`: importing would still show as a
zero-_functional_-diff change on that file's export surface, which is a weaker claim than the one
the kickoff asked for. The dialog's check is explicitly **UX-only** — a user typing a bad scheme
gets stopped before an insert, but the actual enforcement for anything that reaches the renderer
(including hand-typed markdown that never touched this button) remains
`validateHttpOnlyLink` + the pinned `DOMPurify.ALLOWED_URI_REGEXP`, both from AH-081, both
untouched. No security claim rides this component.

---

## 3. D1 — the shared component, placement, and selection mechanism

**One component, not two copies.** `InsertLinkButton.vue` lives in `campaigns/components/`
(co-located, per the plan-pause — promotable to a shared location on a third consumer, the same
discipline `StarRatingInput.vue` set). It takes `modelValue` (the textarea's current string),
`textareaRef` (the parent's native-textarea handle), an optional `maxlength`, and a `testPrefix`;
it emits `update:modelValue` exactly like the textarea itself, so both consumers wire it with the
same shape they already use for the field.

**Placement: `append-inner`, the composer precedent, shipped as designed.** Both editors show
the `🔗` icon (`mdi-link-variant`) inside the textarea's own field via Vuetify's `append-inner`
slot — the same slot shape `ChatPanel.vue`'s send button and `RelationshipThreadView.vue`'s own
link-insert flow already use. The named fallback (a text-button row below the field, for a case
where the icon floats awkwardly on a tall `auto-grow` field) was **not needed**: Vuetify's
`v-textarea` aligns `append-inner` content to the field's own flex row regardless of how many
rows the field has grown to, so the icon sits correctly beside the field's border in both
`OfferFieldsForm.vue` (2-row, `auto-grow`) and `CampaignForm.vue` (3-row, `auto-grow`). **Honesty
note:** this was reasoned from Vuetify's `append-inner` layout behavior and the existing composer
precedent, not from a live-browser screenshot this session — unlike AH-081's eyes-on catch, no
human visual pass happened on this specific placement before push. If it reads wrong in practice,
the swap to the fallback row is a template-only change, isolated to the two call sites.

**Selection handling — hand-rolled, no library, ~140 lines incl. the doc comment.**
`VTextarea` forwards the native `<textarea>`'s `selectionStart` / `selectionEnd` /
`setSelectionRange` / `focus` onto its own component instance via Vuetify's `forwardRefs`
utility — confirmed against `VTextarea.js`'s `forwardRefs({}, vInputRef, vFieldRef,
textareaRef)`, not assumed. A plain template ref on `<v-textarea ref="...">` in the parent is
enough; no `$el.querySelector('textarea')` reach-around needed. Because `VTextarea`'s public TS
surface doesn't declare those forwarded members, the parent's ref is typed against a narrow
`TextareaHandle` interface (exported from `InsertLinkButton.vue`) and cast at the handoff point,
with the cast's safety documented in both the component's own doc comment and at each of the two
call sites. The cast is hoisted into a `computed()` in each parent rather than written inline in
the template, because `eslint-plugin-vue`'s `no-deprecated-filter` rule misreads a `|` union type
inside a template expression as a Vue 2 filter pipe — a real false-positive hit during this
build, fixed by moving the cast to script.

**The flow:**

- **With a selection** — the dialog shows only a URL field; the selected substring becomes the
  link's label; `insertLink()` wraps it as `[selection](url)`.
- **With no selection** — the dialog also shows a required "link text" field; leaving it blank
  and clicking Insert is rejected inline (`textRequired`), never a silent empty-label insert.
- **Cursor restore** — after a successful insert, `nextTick()` (so the DOM already reflects the
  new text) calls `focus()` + `setSelectionRange(cursorPos, cursorPos)` on the textarea, landing
  the cursor immediately after the inserted `)`, not re-selecting the inserted text.

`InsertLinkButton.spec.ts` — **5 passed**, the exact five cases the plan-pause named: wraps a
selection as the label, requires + then accepts the label on no-selection, cursor lands at the
right offset, the http/https check rejects `javascript:` client-side, and the maxlength guard
rejects an over-cap insert without truncating.

---

## 4. D3 — the cap raise

| Field                                | Before                                   | After                | Where                                                                                          |
| ------------------------------------ | ---------------------------------------- | -------------------- | ---------------------------------------------------------------------------------------------- |
| `campaigns.description` (BE, create) | `max:5000`                               | `max:10000`          | `CreateCampaignRequest.php`                                                                    |
| `campaigns.description` (BE, update) | `max:5000`                               | `max:10000`          | `UpdateCampaignRequest.php` — validates independently of create, both moved together           |
| `campaigns.description` (FE)         | `maxlength="5000"`                       | `maxlength="10000"`  | `CampaignForm.vue`, mirrored to `InsertLinkButton`'s own `maxlength` prop for the insert guard |
| `offer_description` (BE + FE)        | `max:3000` / `maxlength="3000"` (AH-081) | unchanged — **3000** | `ValidatesAssignmentOffer.php`, `OfferFieldsForm.vue`                                          |

**The found gap, closed rather than folded in.** No length-boundary test existed for
`campaigns.description` before this chunk — `CampaignCrudTest.php` exercised the field's presence
and content but never its ceiling. Two new tests pin the new 10000 boundary on both sides of the
independent-validation split the kickoff flagged:

- `it('rejects a campaign description over 10000 characters (422)')` — create endpoint, asserts
  `10_001` chars 422s and `10_000` chars creates successfully.
- `it('rejects a Settings-edit description over 10000 characters (422)')` — update endpoint, same
  boundary pair, against the Settings PATCH.

Both are explicitly commented as a **found pre-existing gap**, not new behavior riding along with
the cap raise — the review states this plainly per the plan-pause instruction.

---

## 5. D4 — i18n

**The two AH-081 hint keys amended, not duplicated.** Both `app.campaigns.invite.formattingHint`
and `app.campaigns.fields.formattingHint` gained an appended clause pointing at the new button
(en: `"...links, and line breaks, or use the 🔗 button."`), in every one of the 24 non-English
locales, phrased in each locale's own idiom rather than a mechanically-appended English fragment
(e.g. German: `"...verwenden, oder nutze die Schaltfläche 🔗."`; Polish: `"...wierszy, lub użyj
przycisku 🔗."`).

**One new namespace, `app.campaigns.insertLink.*`, 8 keys** — mirroring
`RelationshipThreadView`'s existing `addLink`/`linkUrl`/`linkInvalid` phrasing rather than
inventing new conventions:

| Key            | en                                                                      |
| -------------- | ----------------------------------------------------------------------- |
| `buttonLabel`  | "Insert link"                                                           |
| `dialogTitle`  | "Insert link"                                                           |
| `urlLabel`     | "URL"                                                                   |
| `textLabel`    | "Link text"                                                             |
| `invalidUrl`   | "Enter a valid http or https link."                                     |
| `textRequired` | "Enter the text this link should show."                                 |
| `tooLong`      | "This link is too long to fit here — shorten the URL or the link text." |
| `insertButton` | "Insert"                                                                |

**Real per-locale MT, all 24 locales, not English placeholders** — every one of the 8 new leaves
and both amended leaves carries distinct, non-English copy in every non-`en` locale. **Flaky-10
spot-check** (`de, fr, pt, es, it, bg, ga, mt, hu, el`) — all ten carry grammatically-distinct
translations for every key, none fell back to English or resolved `undefined`.
`i18n-locale-parity.spec.ts` — **5 passed** — the keyset/placeholder/plural-form parity gate
across all 24 locales and every namespace file, including these 10 net-new leaves.

---

## 6. The maxlength guard

The plan-pause called this "the plan's best catch," and it gets the treatment: the native
`maxlength` HTML attribute only constrains typed/pasted input — assigning a longer string to
`v-model` (exactly what an insert does) bypasses it silently. `insertLink()` computes the
resulting string length **before** emitting anything; if it would exceed the `maxlength` prop, the
insert is refused with an inline error naming the situation (`tooLong`) and **nothing is
emitted** — never a truncated insert, never a value that would only surface as a 422 on submit.
Pinned by its own case in `InsertLinkButton.spec.ts`: a `maxlength={10}` field with a
much-too-long label produces zero `update:modelValue` emissions and shows the `tooLong` copy.

---

## 7. Gate board

| Gate                                                         | Result                                                                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full suite                                   | **2531 passed, 1 skipped** · 9391 assertions                                                                                                                                                                                                                                                                                                                                  |
| `CampaignCrudTest.php` (targeted — the boundary-test diff)   | **19 passed** · 59 assertions, incl. the 2 new boundary tests                                                                                                                                                                                                                                                                                                                 |
| PHPStan (project-wide)                                       | **0 errors** (919 files)                                                                                                                                                                                                                                                                                                                                                      |
| Pint (project-wide)                                          | **passed**                                                                                                                                                                                                                                                                                                                                                                    |
| Frontend (Vitest), full `apps/main` suite                    | **1625 passed / 166 files**                                                                                                                                                                                                                                                                                                                                                   |
| `InsertLinkButton.spec.ts` (new)                             | **5 passed** — the five named cases                                                                                                                                                                                                                                                                                                                                           |
| `OfferFieldsForm.spec.ts` (new)                              | **1 passed** — the thin integration case (real textarea, real selection, `buildOffer()` payload proof)                                                                                                                                                                                                                                                                        |
| `CampaignForm.spec.ts`                                       | **6 passed** (5 existing + 1 new integration case)                                                                                                                                                                                                                                                                                                                            |
| `i18n-locale-parity.spec.ts`                                 | **5 passed** — keyset, placeholder, plural-form parity across all 24 locales                                                                                                                                                                                                                                                                                                  |
| `vue-tsc --noEmit` (`apps/main`, project-wide)               | **clean**                                                                                                                                                                                                                                                                                                                                                                     |
| ESLint (`apps/main`, project-wide)                           | **0 errors, 2 pre-existing `vue/no-v-html` warnings unrelated to this chunk** (`ClickThroughAccept.vue`, `ProfileBasicsForm.vue`) — 0 new. One real false-positive (`vue/no-deprecated-filter` misreading a `\|` union type as a Vue 2 filter) found and fixed by hoisting the cast into a computed, see [§3](#3-d1--the-shared-component-placement-and-selection-mechanism). |
| Zero-diff proof (`useRichBriefRenderer.ts`, `RichBrief.vue`) | **empty `git diff --stat` and `git status --porcelain`** — literally zero bytes changed                                                                                                                                                                                                                                                                                       |
| Playwright                                                   | **Not run this chunk** — editor micro-interaction, Vitest territory, as stated at kickoff. No E2E leg touches this surface.                                                                                                                                                                                                                                                   |

---

## 8. What this chunk deliberately did not do

- **No new Playwright leg.** Confirmed at kickoff as Vitest territory; the button never reaches a
  trust boundary the sanitizer doesn't already own.
- **No import of `validateHttpOnlyLink` from the renderer.** The regex is duplicated on purpose,
  so the zero-diff claim on `useRichBriefRenderer.ts` is literal, not "zero functional diff."
- **No change to `offer_description`'s cap.** Stayed at 3000 (AH-081's number), per D3's
  resolution.
- **No two-copy alternative for the insert-link UI.** One `InsertLinkButton.vue`, both consumers.
- **No live-browser visual check of the `append-inner` placement this session** — reasoned from
  Vuetify's layout behavior and the composer precedent, named honestly in [§3](#3-d1--the-shared-component-placement-and-selection-mechanism)
  rather than claimed as verified.
