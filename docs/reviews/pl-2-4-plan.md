# PL-2.4 plan: Markets as groups of countries

**Date:** 2026-11-21. **Branch:** `pl`, from tip `f7a9587a`. **Status:** plan only. No code, no migration file, no migration run, no push.
**Inputs:** `pl-2-4-inventory.md` (read it first; "inventory §n" points into it), `pl-2-3-plan.md` (the template), `pl-2-1-plan.md`, `production-line-sprints.md`, `WORKING-PROCESS.md` §4.1 and §4.2, Pedram's rulings M1 to M9.
**Standing rules applied:** schema-only migrations, `SET LOCAL lock_timeout` first, a break-revert on every gate, the 24-locale gate, `waves_enabled` is the flag (no new flag), design fidelity (§4.2, ruling 10), audit payload keys literal with their Erasure decision in the same commit, no `v-html` in `modules/strategy` or `modules/waves`, the server never fetches a pasted URL, never write a feature flag in a database Pedram uses, throwaway database commands name their connection and print the database name first.

**Goal.** A wave's market is a **named group of one or more countries**, not one country code. Personas, matrix cells, concepts and concept images belong to a market (a row), not to a country code. A single-country market is as quick to make as today. The tab shows the name; the countries are one hover or tap away. Done now, before PL-2.5 and PL-3 build on markets, because nothing is in production.

## Rulings for this chunk (M1 to M9) and where each lands

| #      | Ruling (short)                                                                                                  | Where it lands         |
| ------ | --------------------------------------------------------------------------------------------------------------- | ---------------------- |
| **M1** | Name typed, one or more countries, short code. Single country: named by the country, code = country code.       | §1.1, §3.2, §4.1       |
| **M2** | One market per country within a wave, in the database and in code.                                              | §1.2, §6               |
| **M3** | Tab shows the NAME; hover shows code and name of each country; tap on an info affordance; keyboard and readers. | §4.2                   |
| **M4** | Short code suggested from the name, editable, unique within the wave, used only as the persona code prefix.     | §1.1, §3.2, §4.1, §1.7 |
| **M5** | Rename, add and remove countries any time. What depends on countries today, what PL-3 hangs on them.            | §1.8                   |
| **M6** | Removal with personas or concepts refused with counts. Cells without personas cannot exist (confirmed).         | §1.5, §3.4             |
| **M7** | Admin and manager manage markets (through the wave). Staff cannot.                                              | §3.3                   |
| **M8** | Part of the wave (`waves_enabled`). What the dialog and the edit show with strategy OFF.                        | §5                     |
| **M9** | Screens with no design built from the design system and named; frame 13:4000 difference is FUNCTIONAL.          | §4.4, §6               |

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data, and no existing production table is altered.** Every PL table is new in production: **no PL migration is on `main` or in production** (inventory §3: `git ls-tree origin/main`, the 17 added files, `origin/main` = `09169b5a`, the deploy log has no wave entry). So the first merge creates all of PL-1 and PL-2 from nothing, in their **final** shape. Nothing is read, written, copied or backfilled by any migration. Two new tables are created empty.

What the new and the edited foreign keys take (PostgreSQL: adding a foreign key takes `SHARE ROW EXCLUSIVE` on **both** tables, which blocks writes to the referenced table while it is held; the PL migrations already set `SET LOCAL lock_timeout = '5s'` first, and so do the two new ones):

| Table (new or edited)                                                       | References (locks taken)                                          | Existing in production today? |
| --------------------------------------------------------------------------- | ----------------------------------------------------------------- | ----------------------------- |
| `wave_markets` (new)                                                        | `agencies`, `waves`                                               | `agencies` only               |
| `wave_market_countries` (new)                                               | `agencies`, `waves`, `wave_markets`                               | `agencies` only               |
| `wave_personas` (edited in place)                                           | `agencies`, `waves`, **`wave_markets`** (was none for the market) | `agencies` only               |
| `wave_matrix_cells`, `wave_concepts` and the four concept children (edited) | the same targets as today; composite keys now on `market_id`      | `agencies` only               |

- **The hot table is `users`; none of the new or edited keys reference it.** Of the tables above only `agencies` exists in production, and the lock on it is the same brief `SHARE ROW EXCLUSIVE` the PL-1 migrations already take (measured in PL-1: `pl-1-1-review.md`, migration rehearsal).
- **Not measured.** This task runs no migration. The build rehearses all PL migrations on a synthetic PostgreSQL database created for the purpose, named, with `select current_database()` printed first, and records each statement's lock list from `pg_locks` in the review.
- **No data-writing migration. No backfill.** Nothing writes a row until the flags are on and a person types something.
- **`down()` is lossy** (drop tables, in reverse order); a rollback tool only before the flags are armed.
- **A behaviour change with the flag OFF:** none. With `waves_enabled` OFF the wave routes answer 404 and nothing renders. With `waves_enabled` ON and `strategy_enabled` OFF the New wave dialog and the edit **do change** (§5), because markets are part of the wave.
- **Stale databases.** Editing migrations in place (§2) means a database that already ran the old files is **not** updated. CI and the test suites rebuild every run; a developer database must be rebuilt (§2, Q9).

---

## 1. Data model

### 1.1 `wave_markets` (Waves module, `BelongsToAgency`, factory, `ulid` route key)

| Column      | Type               | Notes                                                                                                                                                  |
| ----------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `id`        | bigint             |                                                                                                                                                        |
| `ulid`      | ulid, unique       | The market's public id (Q3).                                                                                                                           |
| `agency_id` | FK `agencies`      | RESTRICT, indexed.                                                                                                                                     |
| `wave_id`   | FK `waves`         | RESTRICT.                                                                                                                                              |
| `name`      | `string(60)`       | Typed by the strategist, trimmed, 1 to 60 characters, shown as text. Not translated.                                                                   |
| `code`      | `string(6)`        | The short code, `[A-Z0-9]`, 2 to 6 characters (M4). Persona codes stay within `string(8)`: six letters, a slot digit and an `A..Z` suffix fit exactly. |
| `position`  | `unsignedSmallInt` | Order of the tabs; the order the strategist gave. Replaces the order of the jsonb array.                                                               |
| timestamps  |                    | No soft delete: a removed market is a hard delete (only allowed when nothing hangs off it); the wave itself soft-deletes and keeps its markets.        |

Keys: `unique (ulid)`, **`unique (wave_id, id)`** (the parent key every composite foreign key targets), **`unique (wave_id, code)`** (M4), **`unique (wave_id, name)`** (Q4: tabs must be told apart; the service also checks case-insensitively). Index `(wave_id, position)`.

### 1.2 `wave_market_countries`: a child table, not a column (recommendation)

| Column       | Type          | Notes                                                                                                                                  |
| ------------ | ------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `id`         | bigint        |                                                                                                                                        |
| `agency_id`  | FK `agencies` | RESTRICT.                                                                                                                              |
| `wave_id`    | FK `waves`    | RESTRICT.                                                                                                                              |
| `market_id`  | bigint        | **Composite FK `(wave_id, market_id)` to `wave_markets (wave_id, id)`**, RESTRICT: a country row cannot name a market of another wave. |
| `country`    | `char(2)`     | Uppercase ISO 3166-1 alpha-2.                                                                                                          |
| `created_at` | timestamp     |                                                                                                                                        |

Keys: **`unique (wave_id, country)`: this is M2 in the database.** Index `(market_id)`.

**Why a child table and not a jsonb column.** A key across the elements of an array is not possible in a jsonb column, and an expression or exclusion constraint would be PostgreSQL-only; the test suite runs on SQLite as well. A child table gives M2 as a plain unique key on both engines. (A column would still need the unique key "either way", as the brief says; only the child table can have one.) Cost: one more table, one more query per wave read (a single `whereIn` over the page's waves; no N+1).

**Limits:** at most 20 markets per wave (as today), at most 40 countries per market (Q6). A country is checked as two capital letters, as everywhere else in the code base (Q8: no server country list exists; the client list in `COUNTRY_OPTIONS` is the guard).

### 1.3 How the seven strategy tables move from a country code to a market reference

The brief says four; the inventory counts seven tables carrying `market char(2)` (inventory §1.1). In each, **`market char(2)` becomes `market_id bigint`**:

| Table                     | Change                                                                                                                                                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wave_personas`           | `market_id`, **composite FK `(wave_id, market_id)` to `wave_markets (wave_id, id)`**, RESTRICT. `unique (wave_id, market_id, slot)`, `unique (wave_id, market_id, code)`, `unique (wave_id, market_id, id)` (the parent key). |
| `wave_matrix_cells`       | `market_id`; FK `(wave_id, market_id, persona_id)` to `wave_personas (wave_id, market_id, id)` as today; index `(wave_id, market_id)`.                                                                                        |
| `wave_concepts`           | `market_id`; FK `(wave_id, market_id, persona_id)` to personas as today; `unique (wave_id, market_id, id)`; index `(wave_id, market_id, position)`.                                                                           |
| the four concept children | `market_id`; FK `(wave_id, market_id, concept_id)` to `wave_concepts (wave_id, market_id, id)` as today.                                                                                                                      |

**Nothing crosses a wave:** personas are tied to a market of the **same** wave by the composite key; cells and concepts are tied to a persona of the same wave and market; children to a concept of the same wave and market. Only `wave_personas` needs the new key to `wave_markets`; every other table inherits the proof through the chain. No new key on the others (a second, redundant key would add locks).

`wave_persona_angles` and `wave_usps` carry no market and do not change.

### 1.4 What happens to `waves.markets` (the PL-1 column)

**Removed** (the jsonb column is dropped from the `create_waves_table` migration, §2 option (a)). The wave exposes `markets` as a relation (`WaveMarket` rows ordered by `position`). The `'markets' => 'array'` cast and the fillable entry go. `Wave::$markets` becomes a loaded relation; the one place that used it as a list of codes (persona list order, `assertMarketInWave`, the concept summary, the matrix progress) now uses market rows (inventory §1.2).

### 1.5 Removal and "has personas" (M6)

A market that has personas or concepts cannot be removed (422 with the counts, as today). **Confirmed: a market with only matrix cells cannot exist without personas.** `wave_matrix_cells` has `RESTRICT` composite key to `wave_personas` (inventory §1.1) and deleting a persona deletes its cells first in `PersonaService` (the audit field `cells_removed`). A concept also needs a persona. So "has personas" is the only gate; the concept count is carried in the refusal for the message only. A market with no personas is removed with its country rows (children first, then the market, in one transaction under the wave lock).

### 1.6 Locks and ordering

Unchanged and sufficient: the **wave row is locked first** in every write (`WaveService::update` and every strategy service). Market and country writes happen inside that same lock, so two edits of one wave's markets, or an edit racing a persona create, are serialised. Unique keys are the belt. **Order inside the edit:** delete the country rows of changed markets first, then insert, so moving a country from one market to another in one request does not trip `unique (wave_id, country)` mid-statement.

### 1.7 Persona codes when a market's short code changes (M4)

**Existing persona codes stay** (`UKI1` stays `UKI1` after the market's code becomes `UKX`). New personas use the new prefix; the default-code logic (`<code><slot>`, then `A..Z` on a clash) is unchanged, so a clash with an old code is resolved by the existing suffix rule. The dialog says so beside the field when the market has personas ("Existing persona codes do not change"; one string, 24 locales).

### 1.8 What depends on a market's countries today, and what PL-3 will hang on them (M5)

- **Today: nothing but a label.** No backend code reads a market's country; the web uses it only for `labelForCountryCode` (inventory §6.1). So renaming, adding and removing countries is safe at any time, **and the plan adds no refusal for it.**
- **PL-3 will hang on them:** the **creator picker** matches a creator's `country_code` (`Creator.php:42`) against the countries of the market ("creators in this market"); **script languages** are derived from the market's countries (the language set PL-3 defines). So later "remove a country" may need to refuse or warn when creators were shortlisted from it or scripts were written for its language.
- **How the rule grows:** the same seam the persona and concept counts already use. `WaveMarketGuard` gets, in PL-3, a second method (for example `countryDependents(Wave, marketId, list<string> removedCountries)`), implemented by the module that owns the dependents, called by `WaveService::update` under the wave lock, and answered with the same style of 422 and counts. PL-2.4 builds **no** such method (nothing to count) and documents the seam in `WaveMarketGuard`'s doc comment.

---

## 2. Migrations

**Recommendation: (a) edit the unmerged PL migrations in place, plus two new create-table files.** Reasoning:

1. **Nothing is deployed.** All 17 PL migrations exist only on `pl` (inventory §3). Production will run them for the first time at the first merge. Editing them means production gets the **final shape in one step**: no `ALTER` of a populated table, no dropped column, no legacy `waves.markets`, no half-states.
2. **Option (b) buys nothing for production and costs forever.** It would add roughly ten files that alter seven tables on PostgreSQL (drop and re-create composite foreign keys and unique keys, add `market_id`, drop `market`, drop `waves.markets`) in a database where those tables are empty, and it would leave that history in the repository permanently. Its only benefit is that a developer database that already ran the old files keeps working, and that benefit is small: those databases hold walk data (the walks run on throwaway databases) and are rebuilt by the seeders.
3. **CI always builds from nothing** (`RefreshDatabase`, `migrate:fresh` in E2E; inventory §3), so edited files are exercised on every run, on SQLite and PostgreSQL.

**Files.**

- **New (2):** `2026_11_20_090000_create_wave_markets_table.php` and `2026_11_20_090001_create_wave_market_countries_table.php`. Dated after `waves` (`2026_11_17_100000`) and before the first table that references them (`wave_personas`, `2026_11_20_100002`). Each: `SET LOCAL lock_timeout = '5s'` first on PostgreSQL, `Schema::create`, indexes, `down()` = `dropIfExists` (lossy, rollback tool only).
- **Edited in place (9):** `2026_11_17_100000_create_waves_table` (drop `jsonb('markets')` and its doc line), `2026_11_20_100002_create_wave_personas_table`, `2026_11_21_100000_add_market_unique_to_wave_personas`, `2026_11_21_100001_create_wave_matrix_cells_table`, and the five concept tables `2026_11_21_200001` to `200005`. Their `down()` stay the structural inverse.
- **Dependency order after the edit:** waves, wave_markets, wave_market_countries, usps, personas, angles, persona unique, cells, concepts and children. `migrate:fresh` on both engines proves it.

**What happens to databases that already ran the old files (the cost of (a)).** Laravel does not re-run a migration whose name is in `migrations`; such a database would silently keep `market char(2)` and `waves.markets`, and the app would fail on first use. Handling, by database:

| Database                                               | What happens                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CI (backend, E2E), Pest on SQLite and PG               | Rebuilt from nothing every run. Nothing to do.                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Throwaway walk databases                               | Dropped and recreated per walk (as `pl23_walk` was). Nothing to do.                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Developer databases, **including Pedram's `catalyst`** | Must be rebuilt, or the PL tables rolled back and re-migrated. **Not done by me.** The build report gives the exact commands after a rehearsal on a **clone** (a `pg_dump` restored into a throwaway database, named and printed first), and Pedram runs them. The rehearsal decides between `migrate:rollback` of the PL batches (if the PL migrations are the newest batches only) and a `migrate:fresh`; `migrate:fresh` would wipe `catalyst`'s accounts, so it is the last resort. (Q9.) |
| **Production**                                         | Has none of the old files; runs the final ones at the first merge.                                                                                                                                                                                                                                                                                                                                                                                                                            |

**If (b) were chosen instead** (not recommended): new migrations on top; **no migration writes data**; any conversion of a developer database's existing waves (country code to a one-country market) is a **separate, dry-runnable artisan command** (`--dry-run` prints what it would create; refuses production; idempotent) or a reseed; `waves.markets` is dropped by the last new migration, **after** the command has been run, never before. The same PROD-DATA RISK line holds (the tables are empty in production).

**A guard against the silent-stale case** (cheap, in the build): a Pest schema test per edited table asserts `market_id` exists and `market` does not, so a half-migrated test database fails loudly; and the review states the rebuild instruction in its first lines.

---

## 3. API

### 3.1 The first lines of every action (unchanged)

Flags, brand, wave (404), policy, validation, service. Both flags for strategy routes; `waves_enabled` alone for the wave routes. A market of another agency or another wave is **404**, never 403.

### 3.2 Creating and editing a wave with markets

**Create** `POST agencies/{agency}/brands/{brand}/waves` (and the create-with-campaign path, same `insertWave`):

```
{ "name": "Wave 9", "go_live_on": "2027-03-01", "campaign_id": null,
  "markets": [
    { "name": "Slovakia",     "code": "SK",  "countries": ["SK"] },
    { "name": "UK & Ireland", "code": "UKI", "countries": ["GB", "IE"] }
  ] }
```

- `markets`: required, array, 1 to 20. Each: `name` required (string, trimmed, 1 to 60), `code` optional (`/^[A-Z0-9]{2,6}$/`; **when omitted the server derives the same suggestion the web shows**: the country code for a one-country market, else the initials of the name, de-duplicated within the request, so API callers, tests and seeders can leave it out), `countries` required (array, 1 to 40, each `size:2` and `/^[A-Z]{2}$/`).
- Across the whole body: country codes distinct (**422 `wave.country_in_two_markets`**, `meta: {country, markets: [name, name]}`), codes distinct (422 `wave.market_code_taken`), names distinct case-insensitively (422 `wave.market_name_taken`). Checked in the service under the lock; the unique keys are the belt. A unique-key violation that still gets through (it cannot under the lock) is caught and answered **422 `wave.market_conflict`** ("This wave changed; reload"), never a 500.

**Edit** `PATCH .../waves/{wave}`: `markets`, when present, is the **full replacement list**, as today. An entry with `id` (the market ulid) updates that market (name, code, countries); an entry without `id` creates one; an existing market not in the list is **removed** (refused if it has personas or concepts, §3.4). `position` is the list order. Decided on the row-locked wave in one transaction. A body without `markets` changes no market. Rename only = same `id`, new `name`.

**Response shape** (wave resource): `markets: [{ id, name, code, countries: ["GB","IE"] }]` in `position` order. Country names are **not** sent (the web resolves them from `COUNTRY_OPTIONS`; English only, as everywhere).

**Is the old country-list shape still accepted? No (recommendation, Q2).** `markets: ["SK","RO"]` answers 422 on `markets.0`. Reasons: one contract, no ambiguity between a string and an object, the response shape changes anyway, and nothing is deployed so there is no client to keep working. The cost is the test and seeder churn listed in §6; a factory helper (`->withMarkets(...)`) keeps that churn mechanical. (A one-string shorthand for a one-country market was considered; rejected because it keeps two shapes alive for one convenience that the factory already gives.)

### 3.3 Roles (M7)

`WavePolicy` is unchanged: admin and manager create and edit a wave and therefore manage its markets; **staff view only** (`WavePolicy.php:12-37`). Strategy writes by staff (personas, concepts) are unaffected; staff cannot create, rename, add or remove a market, nor change its countries. A staff `PATCH` with `markets` is 403 and changes nothing (gate §6).

### 3.4 Removal refused (M6)

422 `wave.market_has_personas`, `meta.markets: [{ market: <ulid>, name, code, persona_count, concept_count? }]` (the same shape plus `name` and `code`; `market` is now the ulid). The edit is refused as a whole; nothing is saved. Decided on the locked wave via `WaveMarketGuard`, whose methods now take **market ids** and return counts keyed by id (inventory §1.2). Bound whether or not `strategy_enabled` is on, so the refusal holds with strategy OFF.

### 3.5 Strategy endpoints

`market` is the **market ulid** everywhere it was a code: `GET .../matrix?market=`, `PUT .../matrix/cells {market}`, `GET .../personas?market=`, `POST .../personas {market}`, `POST .../personas/copy {from_market, to_market}`, `GET .../concepts?market=`, `POST .../concepts {market}`. Validation `size:26` instead of `/^[A-Z]{2}$/`; an unknown or foreign ulid is `persona.market_not_in_wave` (422) as today's unknown code. The strategy summary's `markets` becomes `[{ market: <ulid>, name, code, countries, persona_count }]`; `concepts.by_market`, `matrix.by_market` are keyed by the market ulid. Persona, angle, concept and matrix resources carry `market: <ulid>`. The matrix id is `{wave}:{market ulid}`. `concept.market_required` stays (the `market` query is missing or not a ulid). Messages that quoted a code ("A market has at most 8 personas (SK)") quote the market **name**. Limits unchanged (8 personas, 30 concepts per market).

### 3.6 Audit (literal keys; Erasure decision in the same commit)

- **Strategy payloads:** keys `market`, `from_market`, `to_market` stay (already decided in `AuditPayloadKeys.php:85`); their value is the market **ulid** (an id, stable when the market is renamed or recoded). No code or name is written.
- **Wave payloads:** `wave.created` and `wave.updated` keep the literal key `markets` (decided, `AuditPayloadKeys.php:82`), now the **list of market ulids in order**. New literal keys: **`market_countries`** (a map market ulid to its list of country codes) and **`markets_renamed`** (the ulids whose name or short code changed). A rename-only or country-only edit still changes the payload, so the existing "written only when something changed" rule holds. **A market name is never put in a payload** (free text, like a persona name). Decisions in the same commit: `market_countries` and `markets_renamed` are added to the "PL-1.1 wave attributes" group of `notPersonalKeys()` (ids and ISO country codes of an agency's planning data; no creator or user is a field), with the reason stated; `AuditPayloadKeysGuardTest` stays green.
- **Erasure registry:** `wave_markets` and `wave_market_countries` get `TableRule(Disposition::NotApplicable, 'Agency-authored (§3.7); ...')` in the same commit as the migrations; the typed `name` column is decided as an agency-typed label, not a person, as `wave_personas.name`. The Erasure tests that enumerate every table (`ErasureRegistrySchemaTest`, `ErasureSelectionsCoverageTest`) prove it.

### 3.7 Tenancy

Both tables use `BelongsToAgency`; every query goes through `agencies/{agency}/brands/{brand}/waves/{wave}`. Cross-agency gate: agency B asking for a market ulid of agency A on any strategy or wave route gets 404.

---

## 4. Web

### 4.1 The market builder in the dialog (replaces `MarketPicker`)

Used by **New wave and Edit wave** (`NewWaveDialog.vue`). Built from the design system (Vuetify cards, text fields, chips, menus); **named here, no design exists** (M9).

- **A list of market rows**, in order. Each row: **Name** (text field), **Short code** (text field, 2 to 6 characters, uppercase as typed), **Countries** (chips with code and name, each removable) with **Add country**, and a **Remove market** button. Row errors show on the row; **the dialog's errors show fully above the actions** (as PL-2.3 C10).
- **Adding a market.** One **Add market** button opens the country menu (search by name or code, as `MarketPicker` does). **Picking a country creates a one-country market in one click**: name = the country's English label, code = the country code (M1: the simple case is as quick as today). A second control, **Add a group**, creates an empty named row to fill.
- **Picking countries.** A country already used in another market of this wave is **unavailable and says where**: the menu row is disabled with the secondary text "In {market name}" (the name as text). The check uses the dialog's current rows (unsaved too); the server is authoritative (`wave.country_in_two_markets`, shown above the actions with both names).
- **Name and code suggestion.** While a market has exactly one country and its name has not been edited, the name follows the country's label and the code follows the country code. **When a second country is added to an untouched one-country market the name is cleared and focused** with the error "Name this market" (a two-country market called "Slovakia" would mislead). The code suggestion is recomputed from the name while the code has not been edited: initials of the words, ignoring "&" and "and" ("UK & Ireland" gives **UKI**); one word gives its first three letters ("Nordics" gives **NOR**); the suggestion is never shorter than 2 characters. Once a person edits the code it is never overwritten. The suggestion function is one exported helper with a Vitest table.
- **Validation (client, mirrored by the server):** at least one market; at most 20; name required, at most 60, no two the same (case-insensitive); code required, 2 to 6 of `A-Z0-9`, no two the same; each market has at least one country; at most 40 countries. Messages are 24-locale strings.
- **Edit.** Rows are prefilled from the wave; the body sends `id` for existing rows. Removing a row that has personas is accepted by the dialog and **refused by the server**; the dialog shows the refusal above the actions with the **market names and counts** ("UK & Ireland (3 personas, 2 concepts)") instead of codes. The rename-only and country-only edits are plain saves. A note under the code field when the market has personas: "Existing persona codes do not change."
- **Quick open, close and reopen** of the dialog and of each country menu at three speeds, as the PL-1 menus (`wave-dialog-menus.spec.ts`); the menu opens above the dialog (`MENU_ABOVE_DIALOG_Z`).

### 4.2 The tabs: one component for the three steps (M3)

Today the market tab exists **three times** (`PersonasStep.vue:161-178`, `MatrixStep.vue:268-292`, `ConceptsStep.vue:282-299`) with a code chip, a name and a count. The plan extracts **one `MarketTabs` component** used by all three, so the behaviour below is built and tested once.

- **The tab shows the NAME** (and the count and the "no personas yet" dot as today). **The short code is not shown** (M4).
- **Hover** (pointer): a tooltip lists the countries, one per line, "GB United Kingdom". Delay as the other tooltips.
- **Touch (no hover):** each tab carries a small **info button** (an `mdi-information-outline` icon, 24 px target at 390) that **toggles a menu with the same list**. Tapping the tab itself still selects it; tapping the info button does not.
- **Keyboard:** the info button is a normal tab stop after its tab; Enter or Space opens the list, Escape closes it and returns focus to the button; arrow keys keep moving between tabs as today.
- **Screen readers:** the tab has `aria-describedby` pointing at a visually hidden element that lists the countries, so the list is announced **without opening anything**; the info button has an `aria-label` "Countries in {name}". The tab list keeps `role="tablist"` and `aria-selected`.
- **Single-country markets show the same info button** (consistent and discoverable; Q7).
- The `?market=` query holds the **market ulid**; an old or unknown value falls back to the first market (existing behaviour, `PersonasStep.vue:45-48`).

### 4.3 Everything else that shows a market

| Place                              | Change                                                                                                                                                                                                       |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Strategy page and stepper          | The summary's markets carry name, code, countries; `StrategyStepper` counts markets as before ("2 of 3 markets" now means groups).                                                                           |
| Titles and strings with `{market}` | The market **name** instead of `labelForCountryCode`.                                                                                                                                                        |
| Persona dialog, copy dialog        | The copy dialog lists markets **by name**; `from_market`, `to_market` are ulids. Copy semantics unchanged (a copied persona gets the target's next code).                                                    |
| USP delete dialog                  | Lists `{market name} · {code} · {title}`; one `listConcepts` per market by ulid.                                                                                                                             |
| Brand waves table                  | Market **names**, three then "+n" (`BrandWavesPanel.vue:52-56`, `:133-136`); the countries in a tooltip on the cell.                                                                                         |
| Wave page                          | One chip per market name with the countries in its tooltip (`WaveDetailPage.vue:167-172`).                                                                                                                   |
| `api-client` types                 | `WaveMarket { id, name, code, countries }`; write body `WaveMarketInput { id?, name, code?, countries }`; the strategy types take `market: string` (ulid); `marketHasPersonasMeta` returns names and counts. |

### 4.4 Assumptions with no design (named; each is measured and walked)

1. The **market row** and the **Add market / Add a group** controls (frame 13:4000 draws only chips with a code badge and a dashed "Add market"). Reported as a **FUNCTIONAL difference with a screenshot** (M9), in the fidelity table at build.
2. The **info button** and its tooltip/menu on a tab.
3. The **"In {market}"** line on a disabled country.
4. The **refusal text** with market names.

### 4.5 Widths

1440 and 390. At 390 the rows stack (name, code, countries, remove), the countries chips wrap, the tab bar scrolls sideways inside itself (as today) and the page does not scroll sideways (Playwright asserts, with the nav drawer closed).

### 4.6 Frozen files

None. Edited: the files in §6. Not edited: the tracker (`WaveDetailPage` tracker part), the board, campaign files.

---

## 5. Flag (M8)

**No new flag.** Markets are part of the wave: they exist and are edited under `waves_enabled`. With `waves_enabled` OFF the wave routes answer 404 and nothing renders.

**With `waves_enabled` ON and `strategy_enabled` OFF, exactly this changes against PL-1:**

1. **New wave and Edit wave:** the Markets field is the **builder** (rows of name, code, countries), not the country chips. Same with strategy ON: the dialog does not look at `strategy_enabled`.
2. **API:** `markets` is a list of objects (§3.2), in the request and the response. The old list of strings answers 422.
3. **Brand waves table and wave page:** market **names** instead of country codes, countries in a tooltip.
4. **The removal refusal** still holds (the guard is bound regardless) and names the market.
5. Unchanged with strategy OFF: the strategy routes answer 404, there is no tab, no persona, no concept.

**With `waves_enabled` OFF: nothing changes at all** (agency walk 126 and creator walk 146 targets identical; inventory §5).

---

## 6. Tests (each gate has a break-revert)

### Backend gates (Pest, SQLite and PostgreSQL unless noted)

| Gate                                               | What it proves                                                                                                                                                                                                                                                         |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Flags                                              | `waves_enabled` OFF: every wave route 404, no market is read or written. `strategy_enabled` OFF with waves ON: wave create and edit with markets work; strategy routes 404.                                                                                            |
| Tenancy                                            | Agency B gets 404 for agency A's wave and market ulid on wave and strategy routes; lists show only the agency's markets.                                                                                                                                               |
| Roles (M7)                                         | Admin and manager create, rename, recode, add and remove countries and markets; **staff `POST` and `PATCH` with markets: 403, nothing written**; staff can still read the wave and write strategy content.                                                             |
| **One country per market per wave (M2), database** | Inserting the same `(wave_id, country)` twice raises the unique violation (SQLite and PG); the same country in two **different waves** is allowed.                                                                                                                     |
| **M2, API**                                        | Create and edit with a country in two markets: 422 `wave.country_in_two_markets` with both names; nothing saved; moving a country between two markets in one edit succeeds.                                                                                            |
| Short code                                         | Unique within a wave in the database and the API (422 `wave.market_code_taken`); same code in two waves allowed; format 2 to 6 `A-Z0-9`; derived default equals the web suggestion for a table of names (UKI, NOR, SK).                                                |
| Name                                               | Unique within a wave, case-insensitive (422 `wave.market_name_taken`).                                                                                                                                                                                                 |
| Removal refusal (M6)                               | A market with personas (and one with concepts) cannot be removed: 422 with name, code and counts; a market with none is removed with its country rows; the refusal is atomic.                                                                                          |
| Cells without personas                             | The composite key refuses a cell for a persona of another market or wave; deleting the last persona deletes its cells, so a market never holds cells alone.                                                                                                            |
| **Nothing crosses a wave**                         | Composite keys refuse a persona whose `market_id` belongs to another wave (insert and update, both engines); a country row cannot name another wave's market; a concept, cell and child likewise.                                                                      |
| Persona codes                                      | Default `<code><slot>`; a short code change leaves existing codes unchanged and new personas take the new prefix; a clash gets the `A..Z` suffix.                                                                                                                      |
| Copy between markets                               | Works by ulid; both markets must be of the wave; semantics as PL-2.1.                                                                                                                                                                                                  |
| Old shape                                          | `markets: ["SK"]` is 422; an object without `countries` is 422; 21 markets, 41 countries are 422.                                                                                                                                                                      |
| Audit                                              | `wave.created` and `wave.updated` carry the literal keys `markets`, `market_countries`, `markets_renamed`; no name appears in any payload; strategy payloads carry the market ulid; `AuditPayloadKeysGuardTest` green; the two new keys decided in `AuditPayloadKeys`. |
| Erasure                                            | `wave_markets` and `wave_market_countries` are decided (`NotApplicable`) and the registry tests pass.                                                                                                                                                                  |
| Schema                                             | Per edited table: `market_id` present, `market` absent, the composite keys and uniques exist under their names on both engines; migrate and rollback of all PL files in order.                                                                                         |
| Concurrency                                        | A market edit racing a persona create on the same wave is serialised (the existing `StrategyLockTest` pattern).                                                                                                                                                        |

### Web gates (Vitest and Playwright)

Builder: add-by-country creates a one-country market (name, code); the second country clears an untouched name and focuses it; a used country is disabled with "In {name}"; suggestion table (UKI, NOR, SK); validation messages; refusal text with names and counts; hostile market name renders as text (no `v-html`, no `img[src="x"]`); quick open/close/reopen. Tabs: name only (no code on the tab); hover tooltip lists countries; the info button opens the same list; keyboard (Enter, Escape, focus return); `aria-describedby` text present; `?market=` unknown falls back. `MarketTabs` is used by all three steps (a test per step).

### Files and tests that change

**Migrations (9 edited, 2 new):** see §2.
**Backend app:** `Wave.php`, `WaveFactory.php`, `WaveResource.php`, `WaveController.php`, `WaveService.php`, `WaveRuleViolation.php`, `WaveMarketGuard.php`, `StrategyWaveMarketGuard.php`, new `WaveMarket.php`, `WaveMarketCountry.php` and their factories, new market service inside the Waves module; `PersonaService.php`, `PersonaController.php`, `MatrixService.php`, `MatrixController.php`, `ConceptService.php`, `ConceptController.php`, `ConceptDecisionService.php`, `ConceptImageService.php`, `ConceptLinkService.php`, `AngleService.php`, `UspUsage.php`, `StrategyController.php`, `StrategyPresenter.php`, `ConceptPresenter.php`, `StrategyRuleViolation.php`, the seven strategy models and three factories, `AuditPayloadKeys.php`, `ErasureRegistry.php`, `AuditAction.php` (comments only).
**Seeders:** `Pl11` to `Pl13`, `Pl21` to `Pl23` walk seeders and their cleanup seeders (they delete the new tables, children first), new `Pl24WalkSeeder` and `Pl24WalkCleanupSeeder`.
**Pest tests that change:** `MatrixApiTest`, `PersonaApiTest`, `WaveMarketGuardTest`, `ConceptApiTest`, `StrategyLockTest`, `ConceptSchemaTest`, `WaveApiTest`, `StrategySchemaTest`, `MatrixSchemaTest`, `Pl21WalkSeedersTest`, `Pl22WalkSeedersTest`, `Pl23WalkSeedersTest`, `UspApiTest`, `WaveSchemaTest`, `ConceptImageTest`, `ConceptLinkTest`, `StrategyAccessTest`, `StrategyFlagGatesTest`, `WaveCampaignLinkTest`, `WaveAccessTest`, `Pl11WalkSeedersTest`, `Pl12WalkSeedersTest`, `Pl13WalkSeedersTest`; support `StrategyFixtures.php`, `ConceptFixtures.php`, `WaveFixtures.php`. New: `WaveMarketsApiTest`, `WaveMarketSchemaTest`.
**Web source:** `packages/api-client` (`countries.ts` untouched; `types/wave.ts`, `types/strategy.ts`, `errors.ts`, `errors.spec.ts`), `NewWaveDialog.vue`, `MarketPicker.vue` (replaced by the builder; its spec rewritten), `constants.ts`, `BrandWavesPanel.vue`, `WaveDetailPage.vue`, `StrategyPage.vue`, `StrategyStepper.vue`, `PersonasStep.vue`, `MatrixStep.vue`, `ConceptsStep.vue`, `UspsStep.vue`, `UspDeleteDialog.vue`, `PersonaDialog.vue`, `PersonaCopyDialog.vue`, `ConceptDialog.vue`, `ConceptDecisionDialog.vue`, `AngleBlock.vue`, `MatrixTable.vue`, `conceptsLogic.ts`, `strategyErrors.ts`, `strategy.api.ts`, new `MarketTabs.vue`, `MarketBuilder.vue`, `marketCode.ts`.
**Vitest that change:** `StrategyStepper.spec.ts`, `PersonasStep.spec.ts`, `NewWaveDialog.spec.ts`, `MarketPicker.spec.ts` (replaced), `strategyTestKit.ts`, `ConceptsStep.spec.ts`, `MatrixStep.spec.ts`, `waveKeys.spec.ts`, `conceptsLogic.spec.ts`, `UspsStep.spec.ts`, `ConceptsWalkFixes.spec.ts`, `strategyKeys.spec.ts`, `StrategyPage.spec.ts`, `WaveDetailPage.spec.ts`, `BrandWavesPanel.spec.ts`, `conceptsKeys.spec.ts`, `waveTestKit.ts`.
**Playwright that change:** `wave-dialog-menus.spec.ts`, `wave-tracker.spec.ts`, `strategy-workspace.spec.ts`, `strategy-matrix.spec.ts`, `strategy-matrix-manual.spec.ts`, `pl23-concepts.spec.ts`; new tests added to the strategy and wave specs for the builder and the tabs. Each test stays serial and under 15 s.

### Local gates (before any push, `WORKING-PROCESS.md` §4.1)

Pest folders **Strategy, Waves, Brands, Audit, Erasure, Tenancy, Console** on SQLite and on PostgreSQL (clean environment: no exported cache, session or Sanctum variables from a walk server). The touched Vitest set (`src/core/i18n`, `src/modules/strategy`, `src/modules/waves`, `src/modules/brands`, `src/modules/campaigns`, `packages/api-client`). The strategy, concepts and wave Playwright specs, serial, each test duration reported. `vue-tsc`, ESLint, Pint, Larastan. Agency walk 126 and creator walk 146 identical with the comment-node check.

### §4.2 real-browser checks (`WORKING-PROCESS.md`, ruling 10)

Wide and narrow, on the walk data: **hover and tap on the tabs** (hover tooltip at 1440; the info button tapped at 390 and by keyboard); the builder and **every dialog and menu** opened, closed and reopened quickly at three speeds (the New wave dialog, the country menu, the market refusal, the persona dialogs, the copy dialog, the USP delete dialog, the concept dialogs); the **fidelity table for the dialog against frame 13:4000** (elements: dialog width and header, name field, go-live field, Markets label, the market row, chips, Add market, the campaign field, the actions), the builder reported as FUNCTIONAL with a screenshot beside the frame.

### Flag-off check

Agency (126) and creator (146) walk targets identical, with the comment-node check. **Waves ON, strategy OFF:** the New wave dialog and the edit **do change** (§5): the builder replaces the chips, the table and the wave page show names. That is listed here exactly; the PL-1 Playwright specs that cover it are updated, not skipped.

---

## 7. Strings

New keys, 24 locales, no English copy outside an allow-list, placeholders identical, no plural pairs (the existing gates `waveKeys.spec.ts`, `strategyKeys.spec.ts`, `conceptsKeys.spec.ts`): builder labels (Name, Short code, Countries, Add market, Add a group, Add country, Remove market), "In {market}", "Name this market", the four validation messages, the three new 422 messages (`country_in_two_markets`, `market_code_taken`, `market_name_taken`) and `market_conflict`, the info button label "Countries in {name}", the tab tooltip, "Existing persona codes do not change.", the refusal with names. Strings with `{market}` keep their keys; the value passed is the name. Market names are never translated.

## 9. Left out of this chunk

- The wave status roll-up, "Mark as complete", "n of m markets" in the tracker, the workspace opening on the first incomplete step, internal notes: **PL-2.5** (K1 to K4, recorded in `production-line-sprints.md`).
- The creator picker by market countries and script languages: **PL-3** (§1.8 names the seam).
- Translating a market name; a server-side country list or country names; a market-level currency or language.
- Reordering markets by drag (position follows the list order in the dialog; a move up/down is a builder row control, not drag).
- A separate markets screen or endpoints (`/waves/{wave}/markets`): markets are edited through the wave, one transaction, one audit row.
- Conversion of existing databases (option (b) only).

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A seeder pair **`Pl24WalkSeeder`** and **`Pl24WalkCleanupSeeder`** (local and testing only, refuse production, idempotent, additive, **never touches a flag**). Throwaway database **`pl24_walk`**, created with an explicit connection and `select current_database()` printed before the seeder runs; Pedram's `catalyst` is never touched. Accounts: `admin@`, `manager@`, `staff@pl24-walk.local` and `admin@pl24-walk-b.local` (agency B); password printed by the seeder (the PL-2.1 convention). Data, brand **Bolt Food**:

- **Markets Wave 1:** markets **Slovakia** (SK: SK), **UK & Ireland** (UKI: GB, IE), **Nordics** (NOR: SE, NO, DK, FI). UKI has two personas (**UKI1**, **UKI2**) with matrix cells and two concepts (one approved); Slovakia has one persona (SK1); Nordics has none.
- **Markets Wave 2:** empty (no wave strategy data), for the creation steps.
- **Markets Wave 3:** nine one-country markets (a long tab bar at 390).
- Agency B "PL-2.4 Walk Agency B": brand Glovo, one wave with one market "Slovakia" (isolation).

**Steps.**

1. **Flags.** Turn on both flags in `pl24_walk` by hand (the seeder does not). With `strategy_enabled` OFF and `waves_enabled` ON: the New wave dialog shows the builder; the Brand page table and the wave page show market names; no Strategy entry.
2. **Create a wave with a one-country market:** New wave, Add market, pick Slovakia: one row, name "Slovakia", code "SK". Save: the table shows "Slovakia".
3. **Create a wave with a multi-country market:** Add a group, name "UK & Ireland": the code suggests **UKI**; add GB and IE; save. Then a second market "Nordics" (**NOR**).
4. **Same country twice, refused:** add GB to Nordics: it is unavailable and says "In UK & Ireland"; force it by removing GB from the first row, adding it to Nordics, then adding it back to the first row: the error above the actions names both markets; with an API call the server answers 422 `wave.country_in_two_markets`.
5. **Tabs.** Open Markets Wave 1 > Strategy & Scripts > Personas: tabs read **Slovakia, UK & Ireland, Nordics** (no codes). **Hover** a tab: the countries, "GB United Kingdom", "IE Ireland". At 390, **tap the info button**: the same list; tapping the tab selects it. Tab with the keyboard to the info button, Enter opens, Escape closes. A screen reader (or the accessibility tree) announces the countries on the tab.
6. **Personas, cells, a concept in the multi-country market:** in UK & Ireland add a persona: its code is **UKI3**; fill a matrix cell; add a concept; the three steps show the same tab behaviour.
7. **Rename a market:** change "UK & Ireland" to "UK and Ireland" and its code to UKX in the wave edit: the tabs show the new name; **UKI1 and UKI2 keep their codes**; a new persona is **UKX3**.
8. **Add and remove a country:** add FR to Nordics (hover shows it), remove it; remove IE from UK & Ireland (the tab list updates; nothing is refused).
9. **Remove a market with personas, refused:** remove "UK and Ireland": the edit is refused with the name and "3 personas, 2 concepts"; nothing changed. Remove Nordics (no personas): accepted, its countries are free again.
10. **The waves table, wave page and tracker still right:** names in the table (three, then "+n"), chips on the wave page with the countries in the tooltip, the tracker unchanged.
11. **Staff:** signed in as staff, the wave edit has no market controls (view only) and an API `PATCH` with markets answers 403; staff can still write personas and concepts.
12. **Second agency:** as agency B, open a market ulid of agency A: not found; lists show only Glovo's market.
13. **Narrow screen (390):** the builder rows stack, the tab bar scrolls inside itself, no sideways scroll, dialogs fit.
14. **Cleanup:** `Pl24WalkCleanupSeeder`, then drop the throwaway database. The flag state is reported, not changed.

---

## 11. Rulings on the plan (Pedram, 2026-11-21) and the gaps closed

All twelve open questions are ruled **as recommended**. The table below is the ruling; the original questions follow it unchanged.

| #       | Ruling (2026-11-21)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | **(a)** edit the unmerged PL migrations in place. **Standing rule** added to `WORKING-PROCESS.md` §5 and `production-line-sprints.md`: a migration may be edited in place only while no environment other than local and CI has run it; from the first merge of `pl` to `main` PL migrations are **frozen** and every change is a new migration. Added to the "must confirm before `pl` is merged" list (sprints, "What still stands before a merge", item 7): the production migration status shows no PL migration (the colleague's read-back). |
| **Q2**  | The old country-list shape is **not accepted**. One contract.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Q3**  | The wire identifier of a market is its **ulid**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Q4**  | Market names are unique within a wave, **case-insensitive**. (Built as a unique expression index on `(wave_id, lower(name))`, which runs on SQLite and PostgreSQL, plus the service check for the message.)                                                                                                                                                                                                                                                                                                                                       |
| **Q5**  | Short code: 2 to 6 characters of `A-Z0-9`, derived by the server when omitted, unique within the wave.                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| **Q6**  | At most 20 markets per wave and 40 countries per market.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| **Q7**  | The info affordance is shown on every market tab.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Q8**  | Country validation stays as it is (`size:2`, capital letters). Tech-debt row **50** added (shared server-side country list).                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Q9**  | **Pedram's `catalyst` database is not touched**: no rollback, no migrate, no read of its contents. It is stale after this chunk. The review file (`pl-2-4-review.md`, "Rebuilding a stale local database") records the exact commands to rebuild only the PL tables later. All work and the walk use throwaway databases, named and printed first. (This replaces the plan's request to be told whether `catalyst` holds a wave.)                                                                                                                 |
| **Q10** | Changing a market's countries neither warns nor refuses now. The second `WaveMarketGuard` method is the place for PL-3.                                                                                                                                                                                                                                                                                                                                                                                                                           |
| **Q11** | Audit payloads carry ulids and country codes, never market names.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Q12** | Markets are edited through the wave `PATCH` with a full replacement list keyed by ulid. A market missing from the list is a removal and goes through the refusal rule. **Stale lists: see G5 below.**                                                                                                                                                                                                                                                                                                                                             |

### G1. Sources re-read for this round (not carried over from earlier work)

- **`WORKING-PROCESS.md` §4.1 and §4.2** were re-read in full today (§4.1: clean slate, one suite at a time, Vitest capped at 4 workers, Pest serial, Playwright `--workers=2`; the Erasure folder before a push; PostgreSQL before a push for unique keys, locks and transactions; Audit, Erasure and Tenancy on both databases before every push; flags and throwaway databases: name the connection, print the database name first. §4.2: new dialogs and menus opened in a real browser, and the design-fidelity table).
- **Frame 13:4000** was read again with `get_design_context` (file `lYy90qzKk029vjoVCPExs2`, session opened first). It shows: a 640 px dialog (radius 14, border `#2e2e33`, fill `#151516`), title "New wave" and the brand name, Wave name and Go-live date fields, a **Markets** label with chips "SK Slovakia ×" and "RO Romania ×" (each a green badge with the code, the name, a close icon; fill `rgba(62,207,158,0.1)`, border `rgba(62,207,158,0.5)`, radius 8) and a dashed "+ Add market" chip, then a "Start from" block (**Copy from a previous wave** with a wave picker, USPs / Personas "SK: 2 · RO: 2" / Messaging matrix / Creators checkboxes, and **Start fresh**) and the Cancel and Create wave buttons. Two facts that matter here: the frame's chip is a **code badge plus a country name**, so the frame's market is still one country (consistent with the M9 FUNCTIONAL difference), and the persona summary counts by `SK: 2 · RO: 2`, i.e. by market code (PL-3/PL-5 territory; by name with the new model). "Copy from a previous wave" is not part of PL-1/PL-2 and is not touched. No web UI is built in Stage 1, so no fidelity table is due yet; it is written with the builder in Stage 2.

### G2. The tables and every unique key and foreign key after the change

`PK` = primary key on `id`. `ulid` is unique on every table. Every foreign key is RESTRICT unless it says SET NULL.

| Table                         | Unique keys (name)                                                                                                                                                                                                                                                                                                   | Composite foreign keys                                                                                                           | Single foreign keys                                                 |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `wave_markets` (new)          | `ulid`; `(wave_id, id)` `unique_wave_markets_wave_id_id` (the parent key); `(wave_id, code)` `unique_wave_markets_wave_code`; `(wave_id, lower(name))` `unique_wave_markets_wave_lower_name` (expression index)                                                                                                      | none                                                                                                                             | `agency_id` to `agencies`; `wave_id` to `waves`                     |
| `wave_market_countries` (new) | `(wave_id, country)` `unique_wave_market_countries_wave_country` (**M2**)                                                                                                                                                                                                                                            | `(wave_id, market_id)` to `wave_markets (wave_id, id)` `fk_wave_market_countries_market`                                         | `agency_id` to `agencies`; `wave_id` to `waves`                     |
| `wave_personas`               | `(wave_id, market_id, slot)` `unique_wave_personas_wave_market_slot`; `(wave_id, market_id, code)` `unique_wave_personas_wave_market_code`; `(wave_id, id)` `unique_wave_personas_wave_id_id`; `(wave_id, market_id, id)` `unique_wave_personas_wave_market_id` (parent key, from the `add_market_unique` migration) | `(wave_id, market_id)` to `wave_markets (wave_id, id)` `fk_wave_personas_market` (**new**)                                       | `agency_id`, `wave_id`                                              |
| `wave_matrix_cells`           | `(persona_id, usp_id)`                                                                                                                                                                                                                                                                                               | `(wave_id, market_id, persona_id)` to `wave_personas (wave_id, market_id, id)`; `(wave_id, usp_id)` to `wave_usps (wave_id, id)` | `agency_id`, `wave_id`                                              |
| `wave_concepts`               | `(wave_id, market_id, id)` `unique_wave_concepts_wave_market_id` (parent key)                                                                                                                                                                                                                                        | `(wave_id, market_id, persona_id)` to `wave_personas (wave_id, market_id, id)`                                                   | `agency_id`, `wave_id`, `created_by_user_id` (as today)             |
| `wave_concept_usps`           | `(concept_id, usp_id)`                                                                                                                                                                                                                                                                                               | `(wave_id, market_id, concept_id)` to `wave_concepts (wave_id, market_id, id)`; `(wave_id, usp_id)` to `wave_usps (wave_id, id)` | `agency_id`, `wave_id`                                              |
| `wave_concept_images`         | `storage_key`; `thumb_key`                                                                                                                                                                                                                                                                                           | `(wave_id, market_id, concept_id)` to `wave_concepts (wave_id, market_id, id)`                                                   | `agency_id`, `wave_id`, `uploaded_by_user_id` to `users` (SET NULL) |
| `wave_concept_links`          | none                                                                                                                                                                                                                                                                                                                 | `(wave_id, market_id, concept_id)` to `wave_concepts (wave_id, market_id, id)`                                                   | `agency_id`, `wave_id`                                              |
| `wave_concept_decisions`      | none                                                                                                                                                                                                                                                                                                                 | `(wave_id, market_id, concept_id)` to `wave_concepts (wave_id, market_id, id)`                                                   | `agency_id`, `wave_id`, `recorded_by_user_id` to `users` (SET NULL) |

The seven strategy tables are the last seven rows. The two new tables are the first two. `wave_persona_angles` and `wave_usps` carry no market and do not change. The `market_id` column is `unsignedBigInteger` on each of the seven. Where a table's existing index is on `(wave_id, market)` it becomes `(wave_id, market_id)` under the same name.

### G3. The stale-schema test: what it prints and what to run

A Pest file `tests/Feature/Modules/Waves/PlSchemaFreshnessTest.php` checks, for each of the seven tables, that `market_id` exists and `market` does not, that `waves.markets` is gone, and that `wave_markets` and `wave_market_countries` exist. On failure it prints (one line per problem, then the instruction):

```
PL schema is stale: wave_personas still has the column "market" (char) and no "market_id".
The PL migrations were edited in place in PL-2.4 (WORKING-PROCESS.md section 5), so a database that ran the old files is not updated by `php artisan migrate`.
Rebuild ONLY the PL tables of a throwaway or local development database: see docs/reviews/pl-2-4-review.md, "Rebuilding a stale local database".
```

**Where it can fire.** Pest and CI build the schema from nothing every run (`RefreshDatabase`, `migrate:fresh`), so the test is green there by construction. It goes red on a long-lived test database that is migrated but not refreshed (a developer who reuses one with `migrate`, not `migrate:fresh`). It **cannot** see Pedram's `catalyst` database, which the app uses and tests never touch. For that database the guard is the instruction in the review file and in the build report; the first request to a wave route would otherwise fail with a column error. The test is break-reverted by putting the old column back in a migration.

**What the developer runs.** The commands are in `pl-2-4-review.md` and were rehearsed on a throwaway database that had run the old files (`pl24_stale`): `php artisan migrate:reset --path=...` for the **14** PL migrations that create or alter the wave tables (not the two that add a column to `campaigns` and `board_columns`, so no campaign data is lost), then `php artisan migrate`. This wipes the wave data in that database (waves, steps, USPs, personas, cells, concepts); nothing else.

### G4. How migration locks are measured

Through `php artisan migrate`, not by running `up()` by hand. A throwaway PostgreSQL database (named, with `select current_database()` printed first) is built in two steps that mirror production: (1) the migrations that are on `origin/main` are run from a copy of that directory, which gives the production schema; (2) `php artisan migrate` then runs **all** PL migrations together (PL-1, PL-2.1 to PL-2.4, 19 files). A script registers a `DB::listen` hook and, after every DDL statement inside the migration transaction, reads `pg_locks` for the connection, and the `MigrationStarted` and `MigrationEnded` events give the time. Result per migration: duration, and the strongest lock on each table that exists in production (`agencies`, `users`, `brands`, `campaigns`, `board_columns`). Written to `pl-2-4-review.md` with the method and the script's name. Note for the reader: the PL set as a whole **does** touch `users` (a foreign key in `waves.created_by_user_id`, `wave_steps`, `wave_concept_images` and `wave_concept_decisions`), which the PL-2.4 changes do not add to; the measurement shows the lock each takes.

### G5. A stale list from a second tab (Q12)

Two people (or two tabs) edit the same wave. Tab B still holds the old list. If B saved it as the "full replacement list", a market tab A had just added would look like a removal and, having no personas yet, would be deleted silently. So the replacement carries a **version**:

- The wave resource has `markets_version`: a hash of the ordered markets (ulid, name, code, sorted countries).
- A `PATCH` that carries `markets` **must** also carry `markets_version`. The server compares it, under the wave lock, with the current value. **If they differ the whole request is refused: 409 `wave.markets_changed`, nothing saved**, and the dialog reloads and shows the new list ("Someone else changed this wave's markets; review and save again"). The version is not asked for when `markets` is absent (a rename of the wave alone).
- When the version matches, a market missing from the list is a removal and goes through the refusal rule (422 `wave.market_has_personas` with the counts), so a stale list can never remove a market that has personas, and (with the version) can never remove a market someone else just added.
- A `PATCH` with `markets` and no `markets_version` is 422. `POST` (create) has no version.

This is a change to the plan: §3.2 and §3.6 of this file read "the edit decides under the lock" and now also "after the version check".

### Changes to the plan from these rulings

1. §2: Q9 is no longer a question to Pedram; the rebuild commands are recorded, not run (G3).
2. §1.1 and G2: the name key is an expression index on `lower(name)`.
3. §3.2: `markets_version` in the wave resource, required on a `PATCH` that carries `markets` (G5); new error `wave.markets_changed` (409) and a 24-locale string.
4. §3.2: the old shape answers 422, one contract (Q2).
5. §11 Q-list below stays as the record of what was asked.

### Questions as asked (2026-11-21, before the rulings)

| #       | Question                                                                                                          | Recommendation                                                                                                                                                                                                                                                                    |
| ------- | ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | Migrations: (a) edit the unmerged PL migrations in place plus two new create files, or (b) new migrations on top? | **(a).** Nothing is in production; production gets the final shape in one step; (b) adds about ten alter-heavy files for empty tables and permanent clutter. Cost of (a): developer databases must be rebuilt (Q9).                                                               |
| **Q2**  | Is the old country-list shape (`markets: ["SK"]`) still accepted?                                                 | **No.** One contract; the response shape changes anyway; no deployed client. The factory helper keeps test churn mechanical.                                                                                                                                                      |
| **Q3**  | What identifies a market on the wire and in URLs: its ulid or its short code?                                     | **ulid.** The short code is editable (M4) and "used only as the persona code prefix"; an identifier must not change on rename. Cost: URLs read `?market=01H...`; old `?market=SK` falls back to the first market.                                                                 |
| **Q4**  | Must market names be unique within a wave?                                                                        | **Yes** (unique key plus a case-insensitive service check): two tabs with the same name cannot be told apart.                                                                                                                                                                     |
| **Q5**  | Short code format and length, and may the API omit it?                                                            | **2 to 6 characters of `A-Z0-9`**; persona codes stay within `string(8)`. The API may omit it; the server derives the same suggestion as the web.                                                                                                                                 |
| **Q6**  | Limits?                                                                                                           | **20 markets per wave** (as today), **40 countries per market**, name 60 characters.                                                                                                                                                                                              |
| **Q7**  | Does a one-country market show the info button on its tab?                                                        | **Yes** (consistent, discoverable, trivial); hover works the same.                                                                                                                                                                                                                |
| **Q8**  | Validate country codes against a server-side list?                                                                | **No, keep the convention** (`size:2`, capital letters; no server list exists, and creators' `country_code` is validated the same way). The client's `COUNTRY_OPTIONS` is the guard. A server list is a separate, later change.                                                   |
| **Q9**  | Pedram's local `catalyst` database already ran the old PL migrations. How is it brought to the new shape?         | Not by me. **Rehearse on a clone** (named, printed first) and give Pedram the exact command: `migrate:rollback` of the PL batches if they are the newest batches only, else a rebuild. Please tell me whether `catalyst` holds any wave you want to keep (not read in this pass). |
| **Q10** | A market's countries change while concepts exist: warn or refuse?                                                 | **Neither today** (nothing depends on countries); PL-3 adds the seam described in §1.8.                                                                                                                                                                                           |
| **Q11** | The audit payload for a wave edit: names never, ulids and country codes yes. Agreed?                              | **Yes.** `markets` (ulids), `market_countries`, `markets_renamed`; decided in the same commit.                                                                                                                                                                                    |
| **Q12** | Markets are edited through the wave PATCH (full replacement list) rather than their own endpoints. Agreed?        | **Yes:** one transaction, one lock, one audit row, the same refusal path the PL-2.1 guard already has.                                                                                                                                                                            |

---

## 12. Size estimate and build order

**Size: M to L** (about the size of PL-2.2): one schema change across eleven migration files, a service rewrite for the market shape in two modules, a wave dialog builder, one shared tabs component, and a mechanical but wide test and seeder conversion (about 40 test files). Build order, one commit per step, no push until the close-out:

1. Migrations (2 new, 9 edited), models, factories, `WaveFixtures` helper; schema tests on both engines; rehearsal on a throwaway PostgreSQL database.
2. Wave API: validation, `WaveMarketService` (create, update, diff, refusal), resource, audit keys with their Erasure decisions, registry rules; `WaveMarketsApiTest`.
3. Strategy API: the seven tables and services move to `market_id`; presenters, controllers, violations; the existing Pest files converted.
4. Seeders: `Pl11` to `Pl23` converted, cleanup seeders updated, `Pl24` pair added.
5. `api-client` types and errors; `marketCode.ts` (the suggestion) with its table test.
6. `MarketBuilder` and the dialog; `MarketTabs` and the three steps; the remaining screens (§4.3).
7. 24-locale strings.
8. Vitest and Playwright conversions and additions; break-reverts for every gate in §6.
9. Browser walk wide and narrow, fidelity table, local gates, flag-off walks, the review records, the clean-up.

---

## 13. Corrections and rulings log

- **2026-11-21, Pedram:** rulings M1 to M9 and the sprint shape (PL-2 has five chunks; PL-2.4 is this chunk; "Connecting it up" is PL-2.5; K1 to K4 recorded in `production-line-sprints.md`).
- **2026-11-21, correction to `pl-2-3-plan.md` section 8:** it lists `waves` (PL-1.2) among the tables that exist in production. By the repository and the deploy log (inventory §3) **no PL table is in production**. The conclusion of that section (no existing production row is touched) stands.
- **2026-11-21, count:** the brief names four strategy tables that carry the market; the code has **seven** (personas, matrix cells, concepts and four concept children). All seven move.
- **Not read in this pass:** the production server (`migrate:status`); the Figma frame 13:4000 itself (earlier measurements in `pl-1-1-inventory.md` used); the contents of Pedram's `catalyst` database; the 24 locale files line by line; `WORKING-PROCESS.md` was not re-read (its §4.1 and §4.2 are quoted from the PL-2.3 plan).
