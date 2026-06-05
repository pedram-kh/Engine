# Agency-side re-invite UI — Review

**Status:** Closed. Spot-check passed (no post-merge corrections).

**Reviewer:** drafted by Cursor (single-chunk FE build pass); spot-checked + closed.

**Reviewed against:** the re-invite UI kickoff (D-1…D-7) + Sprint 8 Chunk 2's shipped `reinvite()` endpoint/wrapper/types + `CreatorAssignmentsPage` counter-dialog shape + `PROJECT-WORKFLOW.md` §5.17 coverage + §5.15 allowlist-discipline.

> **Scope held:** counter→re-invite loop only. No per-row cancel, no all-status affordances, no assignments-tab redesign. Frontend-only — zero backend change.

---

## Plan confirmation (read-pass)

| Decision                                                          | Confirmed                               |
| ----------------------------------------------------------------- | --------------------------------------- |
| **D-1** FE-only, reuse `campaignsApi.reinvite()`                  | ✅ No backend files touched             |
| **D-2** Both fees on countered rows; agreed fee on all            | ✅ `formatMoney()` reused from Overview |
| **D-3** Status as `v-chip` via `app.campaigns.assignmentStatus.*` | ✅ Matches creator surface              |
| **D-4** Dedicated `ReinviteDialog.vue` on counter-dialog shape    | ✅ `extractFieldErrors` on fee field    |
| **D-5** Currency locked to campaign (read-only suffix)            | ✅ No picker                            |
| **D-6** Fail-closed: `countered && canInvite` only                | ✅ Mirrors `assertSource`               |
| **D-7** Post-success `loadAssignments()` + snackbar               | ✅ Same hook as `onInvited()`           |

**Divergences:** none flagged at plan-pause.

---

## What shipped

### `ReinviteDialog.vue` (new)

- Shows countered fee as context (`app.campaigns.reinvite.body`).
- Major-unit fee input → `Math.round(* 100)` on submit.
- Campaign currency as read-only suffix (D-5).
- Per-field 422 binding via `extractFieldErrors<FeeField>` (counter-dialog shape, not invite dialog's unbound field).
- Submits via `campaignsApi.reinvite()`; emits `success` → parent refreshes.

### `CampaignDetailPage.vue` — Creators tab rows

- Status `v-chip` (D-3).
- Fee display: both offered + countered on `countered` rows; agreed fee only otherwise (D-2).
- `#append` re-invite button gated `status === 'countered' && canInvite` (D-6).
- Opens `ReinviteDialog`; on success → `loadAssignments()` + success snackbar (D-7).

### i18n (en/pt/it)

- `app.campaigns.reinvite.*` — action, dialog, success toast.
- `app.campaigns.fees.offered` / `app.campaigns.fees.countered` — row fee labels.
- Reused existing `app.campaigns.assignmentStatus.*` for chips.

### Coverage (§5.17)

- **`CampaignDetailPage.spec.ts`:** countered row shows both fees + re-invite action; non-countered row shows agreed fee only + no action; staff sees re-invite (`canInvite` positive case); status renders as chip.
- **`ReinviteDialog.spec.ts`:** countered-fee context; major↔minor conversion (1500 → 150000); submit payload + success emit; fee 422 field binding; read-only currency suffix.
- **`form-error-pattern.spec.ts`:** `ReinviteDialog.vue` added to `CANONICAL_422_FILES`.

---

## Spot-check anchors

| Anchor                                                      | Verified                                        |
| ----------------------------------------------------------- | ----------------------------------------------- |
| Re-invite action fail-closed (`countered` + `canInvite`)    | Vitest                                          |
| Countered row shows both fees (decision inputs)             | Vitest                                          |
| Dialog uses counter-dialog 422 binding + major↔minor        | Vitest + allowlist                              |
| Currency locked to campaign (no picker)                     | Vitest                                          |
| Success refreshes list (`countered → invited`, action gone) | Wired via `onReinvited()` → `loadAssignments()` |
| Zero backend                                                | Diff is FE + i18n + tests + docs only           |
| Scope held (no cancel/other-action/tab-redesign)            | ✅                                              |

---

## Out of scope (logged)

- Fuller assignments-tab redesign (per-row cancel, all-status actions, expanded detail) — separate gaps/chunks.
- Agency-side campaign-detail Playwright E2E — logged in `tech-debt.md` (trigger: next chunk extending campaign-detail FE).
- Multi-round negotiation UI — the machine supports re-invite reopening to `invited` (creator can counter again); this chunk ships the single agency round-trip only.

---

## Docs follow-up

- **`tech-debt.md`:** closed the "re-invite backend-ready / UI-pending" gap; logged the no-agency-campaign-detail-E2E note.
- **`services.md` / `tenancy.md` / data-model:** no change (FE-only).

---

## Test run

```
vitest run CampaignDetailPage.spec.ts ReinviteDialog.spec.ts form-error-pattern.spec.ts
→ 27 passed
```

**Commit:** single FE-only commit after spot-check passed.
