# Blacklist visibility in talent pools — Review

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); reviewed + spot-checked (independent pass, 2026-06-05) — passed, no PMC.

**Reviewed against:** the blacklist-in-pools kickoff (single chunk) + its locked decisions **D-1…D-7**; the blacklist-in-pools read-pass inventory; the existing blacklist surfaces ([`AgencyCreatorDetailResource`](../../apps/api/app/Modules/Agencies/Http/Resources/AgencyCreatorDetailResource.php) 2a withhold, the roster-list chip in [`AgencyCreatorController`](../../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php), the discovery scope in [`AgencyCreatorDiscoveryController`](../../apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorDiscoveryController.php)); `02-CONVENTIONS.md`, `docs/security/tenancy.md` (per-agency scope), `07-TESTING.md` §5.17 + §5.35 (break-revert).

This chunk closes the read-pass footgun: a blacklisted creator could sit in a staffing pool — and be added to one — with **no indication**. **Locked decision: WARN, don't remove.** The creator stays a member (no silent removal, no hard block — engagement enforcement stays at the connection-request layer); the blacklist is made **visible** where you'd act on it.

---

## The seam (kept legible for review)

| Half                  | Cost                                                                                                                                    | Files                                                               |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| **Member-list badge** | scoped **backend** extension (the member resource is Creator-rooted via the pivot; relation-borne blacklist status needs a scoped join) | `TalentPoolMemberResource`, `TalentPoolMembershipController::index` |
| **Add-time warning**  | pure **FE** (the roster row already carries `is_blacklisted` + `blacklist_type`)                                                        | `AddCreatorsToPoolDialog.vue`                                       |
| **Shared badge**      | code extraction (`packages/ui`) — 4th use is the right moment                                                                           | `BlacklistBadge.vue` + the two behavior-preserving migrations       |

The two-commit pairing follows this seam: **(1) backend** (member resource + query + tests), **(2) FE + ui** (badge extraction + migrations + pool surfaces + i18n + tests).

---

## Plan confirmation — all seven locks held

- **D-1 — `BlacklistBadge` extracted** in `packages/ui` ([`BlacklistBadge.vue`](../../packages/ui/src/components/BlacklistBadge.vue)), mirroring `KycStatusBadge`/`ContractStatusBadge`: i18n-free, label passed in, the hard=`error`/soft=`warning` tonal `v-chip` map. The inline chip had 2 copies (roster list + 2a detail); this chunk adds the 3rd + 4th (pool member list + picker rows) → four copies is where extraction is correct.
- **D-2 — Behavior-preserving migration.** The roster + detail inline chips are replaced by `BlacklistBadge` with **identical** colors (hard=error/soft=warning), labels (`app.roster.blacklist.badge.{hard,soft}`), and **`data-test`** (`roster-blacklist-{id}`, `creator-detail-blacklist`). The safety net held: **the roster + detail specs stayed green unchanged** (same discipline as the `FiltersCreatorColumns` extraction). The badge takes an optional `size` prop (default `small`) so the roster's `x-small` sizing is preserved exactly; deliberately **icon-free** (the inline chips had no icon — an icon would not be behavior-preserving).
- **D-3 — Member resource + query extended.** `TalentPoolMemberResource` emits `is_blacklisted` + `blacklist_type` (the roster-list subset — status + hard/soft, **NOT the reason**, mirroring the 2a withhold). The query gains two scoped `addSelect` subqueries on `agency_creator_relations`.
- **D-4 — The join IS scoped to the pool's agency.** Both subqueries filter `agency_creator_relations.agency_id = $talentPool->agency_id` (+ `creator_id = creators.id`) — the badge reflects the **pool-owning agency's own** blacklist, never another agency's. Mirrors the existing `requireRosterRelation()` scoped `(agency_id, creator_id)` lookup + the discovery EXISTS scope. **Pinned by a break-revert test.**
- **D-5 — The badge renders in the member list** ([`PoolDetailPage.vue`](../../apps/main/src/modules/pools/pages/PoolDetailPage.vue)), additively beside `v-list-item-title` (`ml-2`, mirroring the roster-list chip placement). Shows for **both** hard + soft (informational). `TalentPoolMemberResource` FE type gained the two fields.
- **D-6 — Per-row flag + confirm-on-add** in the picker. Every picker row shows the `BlacklistBadge` (hard + soft) before selecting; `addSelected` fires a `window.confirm` **before** the add loop when any selected creator is **hard**-blacklisted (names them / counts them) — proceed adds all, cancel aborts. **Soft does NOT trigger the confirm** (it still shows the per-row flag).
- **D-7 — Hard-only is the confirm gate; the badge is for both.** Visibility everywhere (badge on hard + soft, member list + picker); friction only where the mistake is costly (the add confirm fires on hard only).

---

## Honest deviations & notes

- **The kickoff names the FE type `TalentPoolMemberItem`; the actual existing type is `TalentPoolMemberResource`.** I extended the real one (added `is_blacklisted: boolean` + `blacklist_type: BlacklistType | null`). No new type introduced.
- **Scoped `addSelect` subqueries, not a `leftJoin`.** Two correlated subqueries (one per column) read cleaner than a join here: no `creators.*`/pivot column-collision risk, and the `agency_id = pool.agency_id` privacy scope is unmistakable on each. This mirrors the discovery controller's existing scoped-EXISTS idiom; it selects the value rather than testing existence.
- **`AgencyCreatorDetailResource.blacklist_type` narrowed `string | null` → `BlacklistType | null`** in the api-client types. The backend already only ever emits `hard`/`soft`/null; the narrowing is what lets the detail page pass `blacklist_type` straight into the badge's `'hard' | 'soft'` prop. Behavior-preserving (the detail spec uses those literals).
- **Confirm is `window.confirm`** — no confirm-dialog primitive exists in the codebase, and the kickoff specifies "a confirm". Testable via `vi.spyOn(window, 'confirm')`.

No locked decision was diverged from. None of the honest-deviation triggers fired: the migration kept the roster/detail specs green, the join scoped cleanly, and neither `blacklist_reason` nor any silent-remove/hard-block was surfaced.

---

## The build

**Backend (the scoped extension):**

- [`TalentPoolMemberResource`](../../apps/api/app/Modules/TalentPools/Http/Resources/TalentPoolMemberResource.php) — `is_blacklisted` (cast `(bool)` off the raw `acr_is_blacklisted` attribute) + `blacklist_type` (raw `acr_blacklist_type`, null when clean). Reason NOT emitted.
- [`TalentPoolMembershipController::index`](../../apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php) — two `addSelect` subqueries on `agency_creator_relations`, each `whereColumn('…creator_id', 'creators.id')->where('…agency_id', $talentPool->agency_id)->limit(1)`. The members are still the pool's own paginated set; only the **blacklist columns** are the new cross-agency surface, so the scope lives on them.

**`packages/ui`:**

- **New** [`BlacklistBadge.vue`](../../packages/ui/src/components/BlacklistBadge.vue) — `type: 'hard' | 'soft'` + `label` + optional `size` (default `small`); hard=`error`/soft=`warning`; `:aria-label`; exported from [`index.ts`](../../packages/ui/src/index.ts).

**Frontend (`apps/main`):**

- [`CreatorRosterPage.vue`](../../apps/main/src/modules/roster/pages/CreatorRosterPage.vue) + [`CreatorDetailPage.vue`](../../apps/main/src/modules/roster/pages/CreatorDetailPage.vue) — inline chips → `BlacklistBadge` (data-test stable).
- [`PoolDetailPage.vue`](../../apps/main/src/modules/pools/pages/PoolDetailPage.vue) — `BlacklistBadge` beside the member name (`data-test="pool-member-blacklist-{id}"`).
- [`AddCreatorsToPoolDialog.vue`](../../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.vue) — per-row `BlacklistBadge` + the hard-only confirm in `addSelected` (a `rosterById` map resolves a selected id back to its blacklist status).
- [`packages/api-client`](../../packages/api-client/src/types/agency.ts) — `TalentPoolMemberResource` gains the two fields; `AgencyCreatorDetailResource.blacklist_type` narrowed to `BlacklistType`.
- i18n — reused `app.roster.blacklist.badge.{hard,soft}` for the badges; **net-new** `app.pools.addCreators.confirmHard.{one,many}` in **en / pt / it**.

---

## Coverage (§5.17; break-revert §5.35)

**Backend — [`TalentPoolMembershipTest.php`](../../apps/api/tests/Feature/Modules/TalentPools/TalentPoolMembershipTest.php)** (5 new):

- a blacklisted member emits `is_blacklisted: true` + `blacklist_type: hard`;
- a soft blacklist emits `soft` distinctly;
- a clean member emits `is_blacklisted: false` + `blacklist_type: null`;
- **the reason is NOT emitted** (`blacklist_reason`/`blacklist_scope` absent — 2a parity);
- **⚠ scoping (D-4, break-revert — the privacy pin):** a creator hard-blacklisted by agency **A** but a member of agency **B**'s pool shows **no blacklist** in B's list. Break-revert: drop the `agency_id` clause → A's blacklist surfaces in B's pool → fail.

**FE — [`BlacklistBadge.spec.ts`](../../packages/ui/tests/components/BlacklistBadge.spec.ts)** (new): hard→error, soft→warning, label verbatim + aria-label, default `small` / overridable `size`.

**FE — [`PoolDetailPage.spec.ts`](../../apps/main/src/modules/pools/pages/PoolDetailPage.spec.ts)** (+1): a hard member shows "Blacklisted", a soft member "Blacklist warning", a clean member shows no badge.

**FE — [`AddCreatorsToPoolDialog.spec.ts`](../../apps/main/src/modules/pools/components/AddCreatorsToPoolDialog.spec.ts)** (+4): per-row flag shows for hard + soft, none for clean; the **hard-only confirm** fires (cancel aborts, proceed adds); a **soft** creator shows the flag but no confirm; a clean creator adds with no confirm.

**Migration safety net (D-2):** the roster + detail specs stayed **green unchanged** — proof the extraction changed nothing.

---

## Verification results

| Gate                                                | Result                                                                    |
| --------------------------------------------------- | ------------------------------------------------------------------------- |
| Backend Pest — `tests/Feature/Modules/TalentPools/` | **46 / 46** (was 41 at entry; +5 membership blacklist tests)              |
| `packages/ui` Vitest                                | **43 / 43** (5 files; +3 `BlacklistBadge`)                                |
| `apps/main` Vitest — roster + pools (4 files)       | **43 / 43** (roster/detail green unchanged; pools +5)                     |
| PHPStan — `app/Modules/TalentPools` + tests         | **0 errors**                                                              |
| Pint — TalentPools files                            | passed                                                                    |
| `pnpm typecheck:frontend` (all 5 workspaces)        | **0 errors**                                                              |
| `pnpm lint:frontend`                                | 0 errors (2 pre-existing `v-html` warnings in unrelated onboarding files) |
| Prettier — all edited files                         | clean                                                                     |

**Pre-existing, unrelated:** `composer stan` reports 2 errors in `tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php` (`collect` template-type resolution) — that file was already modified in the working tree before this chunk and is untouched here; my files are stan-clean.

---

## Files touched

**Backend (`apps/api`):** `TalentPoolMemberResource.php`, `TalentPoolMembershipController.php`, `tests/Feature/Modules/TalentPools/TalentPoolMembershipTest.php`.

**`packages/ui`:** `src/components/BlacklistBadge.vue` (new), `src/index.ts`, `tests/components/BlacklistBadge.spec.ts` (new).

**`packages/api-client`:** `src/types/agency.ts`.

**Frontend (`apps/main`):** `roster/pages/CreatorRosterPage.vue`, `roster/pages/CreatorDetailPage.vue`, `pools/pages/PoolDetailPage.vue` (+ spec), `pools/components/AddCreatorsToPoolDialog.vue` (+ spec), `core/i18n/locales/{en,pt,it}/app.json`.

**Docs:** `docs/tech-debt.md` (entry closed — built, warn-don't-remove), `docs/reviews/blacklist-in-pools-review.md` (this file). `services.md`/`tenancy.md`/data-model — **no change** (the member resource gains two fields off an existing table; no new routes/tables; the `BlacklistBadge` extraction is code, not docs).

---

## Out of scope (logged in `tech-debt.md`)

- Silent removal / hard block of blacklisted pool members (the decision is warn-don't-remove).
- A "remove all blacklisted from this pool" bulk action (future nicety).
- Brand-scoped blacklist's effect on pools: brand-scoped is recorded-now / enforced-at-Sprint-8 and never touches the relation flag, so a brand-scoped-only blacklist does NOT set `is_blacklisted` → no pool badge, consistent. Flag if a reviewer wants brand-scoped to surface in the agency pool.

---

## Spot-check anchors

- **The member-query blacklist join is scoped to the pool's agency** (break-revert — the privacy pin: A's blacklist invisible in B's pool).
- **The member resource emits status + type but NOT the reason** (2a parity).
- **The `BlacklistBadge` extraction is behavior-preserving** — roster + detail specs green, `data-test` stable, colors/labels identical.
- **The member-list badge shows hard/soft** for blacklisted members, none for clean.
- **The picker per-row flag shows for hard + soft**; the **add confirm fires on hard only** (soft shows the flag but no confirm).
- **Warn-don't-remove:** no silent removal, no hard block — the creator stays a member.

---

_Provenance: drafted by Cursor (blacklist-in-pools build pass, 2026-06-05). Spot-checked + closed 2026-06-05 (no PMC); committed as a two-commit pair (backend / FE+ui)._
