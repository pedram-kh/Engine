# Sprint 12 — Boards & Automation · Chunk 2 Review — Board Kanban UI (frontend)

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); awaiting independent review + merge.

**Reviewed against:** the Sprint 12 Chunk 2 kickoff (D-1…D-12, the IN/OUT scope, the plan-pause seams + test obligations), the approved plan (Q1/Q2/Q3 answers + the one mandatory dual break-revert + the 9 sub-steps), the closed Chunk 1 contracts ([review](sprint-12-chunk-1-review.md) — the Resources/endpoints/error codes), `10-BOARD-AUTOMATION.md` (§4 card, §7 columns, §8 automation UI, §10.2 polling, §11 endpoints, §12 FE breakdown, §13 movements, §14 edge cases), `01-UI-UX.md` + `packages/design-tokens` (the `boardStatus` palette), `PROJECT-WORKFLOW.md §5` (5.11 handoff verification, 5.15 allowlist discipline, 5.35 break-revert).

This chunk ships the SPA Kanban **only** — no new board backend logic (Chunk 1 closed the engine/API; Chunk 3 owns the time-triggered overdue events). It consumes the Chunk 1 wire contract verbatim.

## ⚠ Mandatory Q1 dual break-revert evidence (§5.35) — the app's first dynamic `:style` colour

The board colour strip binds `:style="{ backgroundColor: boardColorHex(token) }"`, where the hex lives in [`boardTokens.ts`](../../apps/main/src/modules/boards/support/boardTokens.ts) (a `.ts`, never a `.vue`). The claim was that this clears BOTH colour guards. Proven empirically — failing-then-passing:

**Break #1 — `no-hard-coded-colors`.** Temporarily added a literal hex (`#123456`) into `BoardCard.vue`:

```
× finds no hex/rgb/hsl literals in any .vue file
  → Found hard-coded color literals in apps/main/src/ .vue files:
    - modules/boards/components/BoardCard.vue — disallowed hex color literal
 Tests  1 failed (1)
```

Reverted → `✓ finds no hex/rgb/hsl literals in any .vue file (1 test)`.

**Break #2 — `no-inline-color-styles`.** Temporarily added a literal `style="background: red"` into `BoardCard.vue`:

```
× finds no style="color:..." or style="background:..." in any .vue file
  → Found inline color/background style overrides in apps/main/src/ .vue files:
    - modules/boards/components/BoardCard.vue — inline color/background style override
 Tests  1 failed (1)
```

Reverted → both guards green together: `✓ no-hard-coded-colors (1) ✓ no-inline-color-styles (1)`.

**Why the real binding passes (now proven, not just reasoned):** the inline-style regex is `style\s*=\s*"[^"]*(?<!-)\b(?:background-color|background|color)\s*:`. A camelCase object-binding `{ backgroundColor: … }` contains no `\bcolor\s*:` (the `Color` in `backgroundColor` has no word-boundary before it) and no `background\s*:` (a `Color` token sits between `background` and the `:`), so it never matches. And the hex is in the `.ts`, so there is no `.vue` hex literal. Both guards bite in the shape expected. `boardStatus` is theme-invariant status semantics (a "Paid" column is green in both themes) → it stays OUT of the Vuetify theme (Q1 Option A, the lower-blast-radius choice).

## The three load-bearing specs (built as distinct assertions)

1. **Optimistic move → revert + toast (load-bearing #1).** Two halves, both pinned:
   - Store: [`useBoardStore.spec.ts`](../../apps/main/src/modules/boards/stores/useBoardStore.spec.ts) "reverts the card to its ORIGIN column when the server rejects the move (422)" — drag → optimistic local move → 422 → card back at origin, `isMovePending` cleared, `moveCard` returns `false`.
   - UI: [`BoardView.spec.ts`](../../apps/main/src/modules/boards/components/BoardView.spec.ts) "reverts the card AND raises the toast when the server rejects a drag" — the rejected move re-homes to origin **and** the error snackbar shows.
2. **Poll-skip-while-pending (load-bearing #2 — kept SEPARATE from #1).** [`useBoardStore.spec.ts`](../../apps/main/src/modules/boards/stores/useBoardStore.spec.ts) "skips the pending card but STILL applies server moves for non-pending cards in the same reconcile (gate is specific, not a blanket freeze)" — the move POST is held open (k1 in-flight); ONE reconcile carries both k1 (server still at origin c1) and a bystander k2 (server moved c1→c2 by automation). Two assertions in that single reconcile: **(a)** k1's optimistic c2 is preserved (not snapped back), **(b)** k2 DOES take the server c2 — so the gate is scoped to `inFlightMoves`, not a global freeze that would defeat polling. (A standalone "takes the server column for a NON-pending card" test backs (b) with no pending card present.)
3. **Delete-safeguard error binding (load-bearing #3).** [`BoardColumnDeleteDialog.spec.ts`](../../apps/main/src/modules/boards/components/BoardColumnDeleteDialog.spec.ts) — `board.column.last_column` and `board.column.destination_required` surface as `ApiError.code` **banners**, NOT `extractFieldErrors` field-pointers (a confirm dialog has no field to pin them to). The dialog is **deliberately absent** from the `CANONICAL_422_FILES` allowlist (commented there); only the column add/edit form joins it.

**Plus the requested edge (no ghost card):** [`useBoardStore.spec.ts`](../../apps/main/src/modules/boards/stores/useBoardStore.spec.ts) "revert-then-remove leaves no ghost when the card is deleted server-side mid-move" — the move 404s → revert restores the card at origin → the next reconcile (card no longer pending, server omits it) removes it. No ghost survives.

## Decisions (built as approved)

- **D-1 · BoardView + tab mount.** [`BoardView.vue`](../../apps/main/src/modules/boards/components/BoardView.vue) owns the store lifecycle + poll; mounted in `CampaignDetailPage.vue` under `v-if="tab === 'board'"` (Q3 — the Messages-panel precedent). Leaving the tab unmounts it → `onBeforeUnmount` stops the poll (no background polling) + resets the store. `board` removed from `comingSoonTabs`.
- **D-2 · `useBoardStore`** (setup-store, the `useAgencyStore` shape) is the optimistic source of truth: flat `cards` bucketed by `relationships.column.data.id` into `cardsByColumn`, plus `columns`, `automations`, and an `inFlightMoves` set. "Non-empty" reads the store's bucketed `cardCountByColumn`, NOT the Resource `card_count` (as approved).
- **D-3 · Optimistic move + revert** — in the store (see load-bearing #1).
- **D-4 · 30s poll** — [`useBoardPoll.ts`](../../apps/main/src/modules/boards/composables/useBoardPoll.ts) clones the `useMessageThread` discipline (setTimeout-reschedule, `onBeforeUnmount` cleanup, refs-only, no localStorage) at `BOARD_POLL_INTERVAL_MS = 30000`. Its tick calls `store.refresh()` → `reconcile()` (skip-while-pending, add/remove semantics). The initial fetch is the BoardView's `load()`, so the tick starts at +30s (no double-fetch on mount).
- **D-5 · Card DnD** — [`BoardColumn.vue`](../../apps/main/src/modules/boards/components/BoardColumn.vue) hosts the `group="board-cards"` draggable; an `added` drop emits `move` → `store.moveCard`.
- **D-6 · Column CRUD** — [`BoardColumnDialog.vue`](../../apps/main/src/modules/boards/components/BoardColumnDialog.vue) (add/rename/recolor/terminal; binds `extractFieldErrors` on `name`/`color_token`) + the `group="board-columns"` reorder draggable in [`BoardColumns.vue`](../../apps/main/src/modules/boards/components/BoardColumns.vue) — a SEPARATE group from the cards (no nested-draggable fight, pinned by a spec).
- **D-7 · Delete safeguard** — [`BoardColumnDeleteDialog.vue`](../../apps/main/src/modules/boards/components/BoardColumnDeleteDialog.vue) (destination dropdown when non-empty; code banners — load-bearing #3).
- **D-8 · Automation config** — [`BoardAutomationDialog.vue`](../../apps/main/src/modules/boards/components/BoardAutomationDialog.vue): per-event target dropdown (+ "No automation" = `action_type: none`, null target), enable toggle, and the broken-state affordance (enabled `move_to_column` with a null target → §14.4 warning to re-pick).
- **D-9 · Card drawer** — [`BoardCardDrawer.vue`](../../apps/main/src/modules/boards/components/BoardCardDrawer.vue): wide v-dialog (the ReviewDraftDrawer pattern), Detail tab via `campaignsApi.showAssignment`, Movement-history tab via `boardApi.movements` (newest-first; a since-deleted column id renders "(removed)", null-safe).
- **D-10 · Reduced card face** — [`BoardCard.vue`](../../apps/main/src/modules/boards/components/BoardCard.vue): creator name + status badge + days-remaining + colour strip, null-safe over `relationships.assignment.data` (a null assignment renders a "removed" tile). The richer §4.2 face (avatar/platform/unread) is NOT on the wire — the Chunk 1 Resource was not reopened; logged in tech-debt.
- **D-11 · FE token map** — [`boardTokens.ts`](../../apps/main/src/modules/boards/support/boardTokens.ts) strips the `status-` prefix → `boardStatus` hex (see Q1 above).
- **D-12 · api-client types + `board.api.ts`** — [`board.ts`](../../packages/api-client/src/types/board.ts) mirrors the 5 Resources + envelopes + payloads (exported via `types/index.ts`); [`board.api.ts`](../../apps/main/src/modules/boards/api/board.api.ts) wraps all 8 endpoints. URL + body shape per endpoint pinned by `board.api.spec.ts`.

## Q-answers honoured

- **Q1 — Option A (FE token helper):** built + dual break-revert proven (above).
- **Q2 — `reason` omitted on drag:** `moveCard` sends `{ target_column_id }` only (`triggered_by: user` still recorded server-side). One-line tech-debt note added ("manual-move reason not promptable via drag; add via a drawer control if ever needed").
- **Q3 — `v-if="tab === 'board'"`:** poll stops on tab-leave, restarts on re-entry. Pinned by `BoardView.spec.ts` (poll ticks at +30s, store resets + poll stops on unmount) + `useBoardPoll.spec.ts` (onBeforeUnmount discipline).

## Role gates (as approved)

Staff can DRAG cards (the columns aren't disabled for them) but see no column-config menu, no "Add column", and no "Automations" button — `canConfigure` (admin + manager) gates those. Pinned by `BoardView.spec.ts` + `BoardColumns.spec.ts`.

## Verification

- **62 new board tests green** across 11 files (`board.api` 10, `useBoardStore` 11, `useBoardPoll` 3, `boardTokens` 4, `BoardCard` 5, `BoardColumns` 6, `BoardColumnDialog` 3, `BoardColumnDeleteDialog` 4, `BoardAutomationDialog` 6, `BoardCardDrawer` 5, `BoardView` 5).
- **Full `apps/main` unit sweep green** (1017 tests) — including the updated `CampaignDetailPage.spec.ts` (board tab now mounts the live BoardView, not coming-soon), the `form-error-pattern` allowlist (+`BoardColumnDialog.vue`), and the colour/`no-direct-http` guards.
- **`@catalyst/api-client` green** (100 tests) + `tsc --noEmit` clean (new board types).
- **`vue-tsc --noEmit` clean**; **ESLint clean** on the boards module + touched files.
- i18n `board` keys added to all three locales (en/pt/it) for schema parity; `comingSoon.board` removed.

## Out of scope (unchanged from the plan)

- No new board backend logic (Chunk 1 closed it; Chunk 3 owns overdue events).
- Manual-move reason affordance (Q2 — tech-debt), richer card face (D-10 — tech-debt), intra-column reorder (P2 — `position` inert).
- `reset-to-defaults` (deferred from Chunk 1 — not requested in this chunk's plan).

## Files

**New (api-client):** `packages/api-client/src/types/board.ts` (+ `types/index.ts` export).
**New (boards module):** `api/board.api.ts`, `stores/useBoardStore.ts`, `composables/useBoardPoll.ts`, `support/boardTokens.ts`, `components/{BoardView,BoardColumns,BoardColumn,BoardCard,BoardColumnDialog,BoardColumnDeleteDialog,BoardAutomationDialog,BoardCardDrawer}.vue` (+ specs for each).
**Edited:** `modules/campaigns/pages/CampaignDetailPage.vue` (+ spec), `tests/unit/architecture/form-error-pattern.spec.ts` (allowlist), `core/i18n/locales/{en,pt,it}/app.json`, `docs/tech-debt.md`.

> **Note for the reviewer:** the docs + this review file are intentionally **uncommitted** — they route to chat for the independent review + merge.

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Spot-check passed (no PMC). Sprint 12 Chunk 2 (board Kanban UI, frontend) complete.

**Reviewed against:** the Ch2 kickoff (D-1…D-12 + Q1/Q2/Q3 + the mandatory dual break-revert), the closed Ch1 contracts, `10-BOARD-AUTOMATION.md` (§4/§7/§8/§10.2/§12/§13/§14), `PROJECT-WORKFLOW.md §5` (5.15 allowlist, 5.35 break-revert).

This chunk ships the SPA Kanban only — no new board backend (Ch1 closed the engine; Ch3 owns overdue events + reset-to-defaults). The visualization-layer invariant (the board reflects reality, never drives it) is now defended from the FE side too: optimistic moves revert on rejection and the poll never clobbers a pending card.

### Spot-check anchors → evidence (verified against test bodies)

| Anchor                                               | Evidence                                                                                                                                                                                                                    |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Poll-skip is SPECIFIC, not a blanket freeze          | One reconcile carries `k1` (pending → optimistic `c2` preserved) AND `k2` (non-pending → takes server `c2`). The `k2` assertion proves the gate is scoped to `inFlightMoves`; a global freeze would fail it.                |
| Revert restores the captured pre-mutation origin     | Non-trivial fixture (origin `c2`, not `columns[0]`); `originColumnId` captured before `setCardColumn`, restored on catch. A "revert-to-first" or "re-read-current" bug fails (`c1`/`c3`).                                   |
| No-ghost on delete-mid-move                          | Move 404s → revert to origin (still present) → next reconcile omits the card → final assertion `find(k1) === undefined`. `clearInFlight` in `finally` ensures non-pending before reconcile, so the omit-removes path fires. |
| Delete-safeguard = `ApiError.code` banners           | `last_column` + `destination_required` surface via `board-column-delete-banner` mapped by `err.code`; no `extractFieldErrors` import in the delete dialog; `deleted` never emitted on error.                                |
| Allowlist on/off split is real                       | `BoardColumnDialog.vue` (the form) added to `CANONICAL_422_FILES`; `BoardColumnDeleteDialog.vue` deliberately absent with an inline rationale comment (whole-operation refusal, no field to pin).                           |
| Q1 dual break-revert (accepted from pasted evidence) | Literal hex → `no-hard-coded-colors` fails; literal `style="background:"` → `no-inline-color-styles` fails; both revert clean; the camelCase `:style` object-binding matches neither regex (shown, not asserted).           |

### Strengthened during spot-check (before commit)

- **Poll-skip spec** gained the non-pending-bystander half (was skip-only) — now proves the gate is specific, not a blanket freeze.
- **Revert spec** moved to a non-trivial origin (`c2`, not the first column) so the two plausible revert bugs fail loudly.

### Decisions confirmed (built as approved)

D-1 `BoardView` under `v-if="tab==='board'"` (poll stops on leave, Q3); D-2 `useBoardStore` optimistic SoT + `inFlightMoves` + bucketed `cardCountByColumn` for non-empty; D-3 optimistic move + origin-captured revert; D-4 30s poll cloning the `useMessageThread` discipline, reconcile skip-while-pending; D-5/D-6 two DnD surfaces (separate `board-cards`/`board-columns` groups); D-7 delete-safeguard code banners; D-8 automation config + broken-state (null target) affordance; D-9 card drawer (wide `v-dialog`, `showAssignment` content, null-safe movement history); D-10 reduced card face from `relationships.assignment.data` (null-safe; richer face → tech-debt, Ch1 Resource untouched); D-11 FE `status-<x>`→`boardStatus` token map; D-12 api-client types + `board.api.ts`. Q1 Option A; Q2 reason omitted on drag (tech-debt note); Q3 `v-if` poll-stop.

### As-built note (accepted)

The card face reads display data from `relationships.assignment.data` (the Ch1 Resource nests it), not flat attributes as the kickoff's D-10 phrasing implied — read null-safe (null assignment → "removed" tile). A correction to the kickoff's imprecision, not a deviation.

### Verification

62 new board tests across 11 files; full `apps/main` sweep 1017 green (incl. the updated `CampaignDetailPage.spec`, the `form-error-pattern` allowlist, the colour guards); `@catalyst/api-client` 100 green + `tsc` clean; vue-tsc + ESLint clean; i18n `board` keys en/pt/it (parity), `comingSoon.board` removed.

### Out of scope

- **Ch3:** time-triggered overdue events + scheduler + draft-deadline field + **reset-to-defaults** (moved from Ch2 — net-new destructive backend, belongs with Ch3's other new-backend work).
- **Tech-debt:** richer card face (avatar/platform/unread — Ch1 Resource not reopened); manual-move reason affordance (not promptable via drag). Intra-column ordering (P2, `position` inert).

---

_Provenance: drafted by Cursor (Sprint 12 Ch2 build); verdict appended + spot-checked by Claude (poll-skip-specificity / origin-captured revert / no-ghost-removal / code-banner-binding + allowlist-split all verified at test-body level; Q1 dual break-revert accepted from pasted regex evidence). Poll-skip + revert specs strengthened pre-commit. No PMC._
