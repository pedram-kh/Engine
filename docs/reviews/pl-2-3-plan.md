# PL-2.3 plan: Concepts by hand, with references and the client's decision recorded

**Date:** 2026-11-21. **Branch:** `pl`, from tip `58f308ec`. **Status:** plan only. No code, no migration file, no migration run, no push.
**Inputs:** `pl-2-3-inventory.md` (read it first; "inventory §n" points into it), `pl-2-2-plan.md` (the template), `pl-2-1-plan.md`, `WORKING-PROCESS.md` §4.1 and §4.2, Pedram's rulings C1 to C11.
**Standing rules applied:** schema-only migrations, `SET LOCAL lock_timeout` first, a break-revert on every gate, the 24-locale gate, one flag per sprint and never reused (`strategy_enabled` again, with `waves_enabled`), design fidelity (§4.2, ruling 10: VISUAL differences are fixed, FUNCTIONAL ones are reported to Pedram), no feature flag written in any database Pedram uses.

**Goal.** Step 4 of the Strategy workspace opens. For one market, a person writes concepts by hand: a title, one of the market's personas, a funnel stage with a note, USP chips (pre-filled from the persona's matrix row), nine text fields, and references (images and links). The client decides on each concept outside the platform; an admin or a manager records that decision on the client's behalf. With `strategy_enabled` OFF, nothing changes.

## Rulings for this chunk (C1 to C11) and where each lands

| #       | Ruling (short)                                                                                                                        | Where it lands         |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| **C1**  | By hand only. No "Generate concepts", no "regenerate", nothing labelled from the agent. Say what the model holds for PL-9.            | §1.1 (`origin`), §9    |
| **C2**  | Per market. Card: chip "CONCEPT n", title, persona, funnel stage with a note, USP chips, nine fields. Menu: duplicate, delete.        | §1.1, §4.1, Q1         |
| **C3**  | Chips pre-filled from the persona's Priority and Support cells, then editable. What happens on matrix change, persona change, delete. | §1.3                   |
| **C4**  | Images JPG/PNG/WebP, 10 per concept, 300 MB per concept, presigned flow, bytes-derived MIME and size, API-only reads. Links by hand.  | §1.5, §1.6, §1.7, §3.5 |
| **C5**  | Status per concept; admin and manager record the decision; red comment on the card; audited; editing an approved concept resets it.   | §1.4, §1.8, §3.2       |
| **C6**  | No hard lock. Step 4 complete per market; under three is a soft warning; steps 5 and 6 carry a warning; PL-3 note.                    | §1.9, §4.5             |
| **C7**  | Banner shows counts. "Send to client", date sent, "Send reminder" not built.                                                          | §4.1, §9               |
| **C8**  | No comments, no counts, no internal notes, no client view.                                                                            | §9                     |
| **C9**  | Admin, manager, staff manage concepts and references. Only admin and manager record a decision.                                       | §3.3                   |
| **C10** | Explicit save, per-field last write wins, errors fully visible above the actions.                                                     | §1.10                  |
| **C11** | Tracker and Brand page bar not changed.                                                                                               | §4.4                   |

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data, and no change to any existing table.** Five new tables, all created empty. No migration alters, reads, writes or backfills an existing table, and no existing unique index is touched: the parent keys the new foreign keys point at (`wave_personas (wave_id, market, id)`, `wave_usps (wave_id, id)`) already exist from PL-2.1 and PL-2.2. So no migration takes an `AccessExclusiveLock` on an existing table.

What the new foreign keys do take (PostgreSQL: adding a foreign key takes `SHARE ROW EXCLUSIVE` on **both** tables, which blocks writes to the referenced table while it is held):

| New table                | References (locks taken, `SHARE ROW EXCLUSIVE`)   |
| ------------------------ | ------------------------------------------------- |
| `wave_concepts`          | `agencies`, `waves`, `wave_personas`              |
| `wave_concept_usps`      | `agencies`, `waves`, `wave_concepts`, `wave_usps` |
| `wave_concept_images`    | `agencies`, `waves`, `wave_concepts`, **`users`** |
| `wave_concept_links`     | `agencies`, `waves`, `wave_concepts`              |
| `wave_concept_decisions` | `agencies`, `waves`, `wave_concepts`, **`users`** |

- **Tables that exist in production today:** `agencies`, `waves` (PL-1.2), `users`. `wave_usps`, `wave_personas`, `wave_matrix_cells` are not in production (PL-1 and PL-2 are built, not merged or deployed; `production-line-sprints.md` §6).
- **`users` is the hot table.** A write lock on `users` stops sign-ins and profile updates for as long as it is held. The two user columns (`uploaded_by_user_id`, `recorded_by_user_id`) are kept as real foreign keys because the Erasure verifier requires every user reference to be declared in `refs` (a column with no foreign key would be invisible to it) and because `brand_files.uploaded_by_user_id` set the precedent in PL-1.1. Held for one `CREATE TABLE` on an empty table the lock is milliseconds. The guard is `SET LOCAL lock_timeout = '5s'` first in every migration: if the lock cannot be had, the migration fails and nothing is changed. If the measurement shows more than a blink, the fallback is to leave the user FK off the images table and keep the uploader in the audit log only (a build-time note in the review, not a ruling).
- **Not measured.** This task runs no migration. The PL-2.2 plan measured on a throwaway database; the same rehearsal is a **build** step here, run through `php artisan migrate` with the real files on a synthetic database that is created for the purpose, **named in the command (`--database` and `DB_DATABASE`) and printed with `select current_database()` before any statement**, then dropped, with the lock modes read from `pg_locks` before commit. Pedram's database (`catalyst`) is never the target and no feature flag is written there. The numbers go in the review, not here.
- **No data-writing migration.** Nothing writes a row until the flag is on and a person types something. No backfill.
- **`down()` is lossy** (drop tables, in reverse order); a rollback tool only before the flag is armed.
- **A behaviour change with the flag OFF: none.** The extra checks added to `UspService::delete` and `PersonaService::delete` run inside strategy endpoints, which answer 404 with either flag off; the new tables are not read anywhere else.
- **Objects.** Image objects are written to the `media` disk only through the new endpoints (flag on).

---

## 1. Data model

All tables: Strategy module, `BelongsToAgency`, a factory each, `ulid` unique as the route key. Every table has `agency_id` (FK `agencies`, RESTRICT, indexed) and `wave_id` (FK `waves`, RESTRICT). Children also carry `market` and a composite FK to the concept, so a row cannot name another wave or market.

### 1.1 `wave_concepts` (M1)

One row per concept. Module model `WaveConcept`.

| Column                 | Type and rule                                                                                                                                                                                                                                                                                                                                            |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`, `ulid`           | bigint, ulid unique                                                                                                                                                                                                                                                                                                                                      |
| `agency_id`, `wave_id` | as above                                                                                                                                                                                                                                                                                                                                                 |
| `market`               | char(2); must be a market of the wave (checked in the service with `StrategyWaveMarketGuard`, as the persona)                                                                                                                                                                                                                                            |
| `persona_id`           | bigint, **required**; FK `(wave_id, market, persona_id)` to `wave_personas (wave_id, market, id)` RESTRICT. This is what stops a persona of another market or wave.                                                                                                                                                                                      |
| `position`             | smallint, 1..n inside (wave, market); the "n" of "CONCEPT n". Renumbered 1..n after a delete, like the USPs. Index `(wave_id, market, position)` (not unique: a renumber would collide). New and duplicated concepts go to the end. No reorder control (not drawn).                                                                                      |
| `title`                | varchar(120) required                                                                                                                                                                                                                                                                                                                                    |
| `funnel_stage`         | varchar(4) nullable: `tofu`, `mofu`, `bofu` (CHECK on PostgreSQL). Null until chosen.                                                                                                                                                                                                                                                                    |
| `funnel_note`          | varchar(40) nullable ("consideration", "hard sell", "awareness"). Pre-filled in the form when a stage is picked and the note is empty or still the previous default; always editable.                                                                                                                                                                    |
| `product`              | varchar(160) nullable                                                                                                                                                                                                                                                                                                                                    |
| `angle`                | varchar(300) nullable                                                                                                                                                                                                                                                                                                                                    |
| `primary_message`      | varchar(300) nullable                                                                                                                                                                                                                                                                                                                                    |
| `hypothesis`           | varchar(400) nullable                                                                                                                                                                                                                                                                                                                                    |
| `hook`                 | varchar(300) nullable                                                                                                                                                                                                                                                                                                                                    |
| `format_visual`        | varchar(400) nullable                                                                                                                                                                                                                                                                                                                                    |
| `key_points`           | jsonb nullable, a list of at most 8 items of at most 160 characters                                                                                                                                                                                                                                                                                      |
| `payoff`               | varchar(300) nullable                                                                                                                                                                                                                                                                                                                                    |
| `cta`                  | varchar(200) nullable                                                                                                                                                                                                                                                                                                                                    |
| `status`               | varchar(16) not null default `awaiting`: `awaiting`, `approved`, `changes_requested` (CHECK on PostgreSQL). The **current** status, stored so counts are one query; the history is in §1.4.                                                                                                                                                              |
| `origin`               | varchar(8) not null default `manual` (CHECK `manual`). **The PL-9 hook (C1):** the agent's concepts will be `origin = 'agent'`. A later migration widens the CHECK and may add nullable columns (`generated_at`, a run reference); it needs no rewrite of existing rows. Nothing else is stored for PL-9 now, and no column here is named for the agent. |
| `created_by_user_id`   | none (who created it is in the audit log; this keeps `users` out of this table's lock list, as PL-2.1 §1.5)                                                                                                                                                                                                                                              |
| `timestamps`           |                                                                                                                                                                                                                                                                                                                                                          |
| Keys                   | unique `(wave_id, market, id)` (the parent key of every child); index `(wave_id, market)`; index `agency_id`                                                                                                                                                                                                                                             |

Everything the client will see on a card is a column here, so PL-5 (inventory §2) needs no change to this table.

### 1.2 Children (M2 to M5): composite foreign keys, so nothing crosses a wave or a market

Every child has `wave_id`, `market`, `concept_id` and the foreign key `(wave_id, market, concept_id)` to `wave_concepts (wave_id, market, id)` RESTRICT. A row of another wave or market cannot be inserted: it fails at the database. (The PL-2.2 gate "Same wave and market (DB)" is the template.)

**`wave_concept_usps` (M2): the chips.**

| Column                                               | Rule                                                                                                      |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `id`, `agency_id`, `wave_id`, `market`, `concept_id` | as above                                                                                                  |
| `usp_id`                                             | FK `(wave_id, usp_id)` to `wave_usps (wave_id, id)` RESTRICT: a chip can only name a USP of the same wave |
| `position`                                           | smallint, the chip order (Priority first, then Support, each by USP position, then hand additions)        |
| Keys                                                 | unique `(concept_id, usp_id)`; index `usp_id` (the delete lookup)                                         |

At most as many chips as the wave has USPs (6). No `source` column: a chip is a chip once it exists (§1.3).

**`wave_concept_images` (M3).**

| Column                                                       | Rule                                                                                                                                                                       |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`, `ulid`, `agency_id`, `wave_id`, `market`, `concept_id` | as above                                                                                                                                                                   |
| `storage_key`                                                | varchar(255), **unique**; `agencies/{agency_ulid}/brands/{brand_ulid}/waves/{wave_ulid}/concepts/{concept_ulid}/images/{image_ulid}.{ext}`                                 |
| `thumb_key`                                                  | varchar(255) nullable, unique when set; `…/thumbs/{image_ulid}.jpg`. Null means no preview yet, or it could not be made; the card then shows the frame's placeholder tile. |
| `original_filename`                                          | varchar(255)                                                                                                                                                               |
| `mime_type`                                                  | varchar(16): `image/jpeg`, `image/png`, `image/webp` (CHECK on PostgreSQL). **Written from the stored object at `complete`, never from the client.**                       |
| `declared_bytes`                                             | bigint: what `init` reserved                                                                                                                                               |
| `size_bytes`                                                 | bigint nullable: the stored object's real size, set at `complete`                                                                                                          |
| `status`                                                     | varchar(8): `pending` (reserved, not completed) or `ready` (CHECK)                                                                                                         |
| `expires_at`                                                 | timestamp nullable: for a `pending` row, 20 minutes after `init` (the presign is valid 15)                                                                                 |
| `position`                                                   | smallint                                                                                                                                                                   |
| `uploaded_by_user_id`                                        | FK `users` nullOnDelete, nullable (Erasure `refs`)                                                                                                                         |
| Keys                                                         | unique `storage_key`; index `(concept_id, status)`; index `expires_at` where pending                                                                                       |

**`wave_concept_links` (M4).**

| Column               | Rule                                        |
| -------------------- | ------------------------------------------- |
| ids and composite FK | as above                                    |
| `url`                | varchar(2048), validated (§1.6)             |
| `title`              | varchar(120), required, typed by the person |
| `position`           | smallint                                    |
| Keys                 | index `concept_id`                          |

No domain column: the domain is **derived from the stored URL when read** (§1.6), so it can never disagree with the URL.

**`wave_concept_decisions` (M5): append-only.**

| Column                | Rule                                                                                                                                                                                    |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ids and composite FK  | as above                                                                                                                                                                                |
| `status`              | `approved` or `changes_requested` (CHECK)                                                                                                                                               |
| `client_name`         | varchar(80), required                                                                                                                                                                   |
| `note`                | varchar(500) nullable, **used only with `approved`** (the optional note)                                                                                                                |
| `comment`             | varchar(1000) nullable, **required with `changes_requested`**, null with `approved` (CHECK on PostgreSQL: `status = 'changes_requested' = (comment is not null)` written as two checks) |
| `source`              | varchar(16) default `agency`: the agency recorded it on the client's behalf. PL-5 adds `client`.                                                                                        |
| `recorded_by_user_id` | FK `users` nullOnDelete, **nullable** (a client's own decision in PL-5 has no agency user; Erasure `refs`)                                                                              |
| `recorded_at`         | timestamp; `created_at`                                                                                                                                                                 |
| Keys                  | index `(concept_id, id)`; the **latest row by id** is the current decision                                                                                                              |

Rows are never updated. A new decision is a new row, so who recorded what and when is kept even when a later decision replaces it (C5). They are deleted only with their concept.

### 1.3 USP chips: the three rules asked in C3

Chips are a **snapshot**, not a live view of the matrix.

1. **Picking the persona (create).** The server builds the chips: the persona's matrix cells with level `priority`, then `support`, each ordered by USP position. A persona with no such cell gives no chip; the form says so ("This persona has no Priority or Support USP in the matrix yet").
2. **The matrix changes later.** Nothing happens to existing chips. A concept the client has approved must not change under its feet because someone edited a cell, and the person may have edited the chips by hand. Fixed text on the chip area when the current matrix would pre-fill something different is **not built** (left out, §9, would need the cell levels read on every card).
3. **The persona is changed (edit).** The request must say what to do with the chips: `usp_mode = 'keep'` or `'refill'`. The web form asks in the dialog when the persona changes: "Replace the USP chips with this persona's Priority and Support USPs?" with the two answers; the default is Keep when the chips were edited by hand since the dialog opened, Replace otherwise (known from the form's state, no stored flag). A request that changes the persona without `usp_mode` is a 422 `concept.usp_mode_required`.
4. **A USP is deleted.** The chip goes (a database FK is RESTRICT, so the service removes the chips first, in the same transaction, as it does for angles and cells). The delete confirmation lists every concept that carries the USP (§3.4). A concept that was `approved` goes back to `awaiting` (a chip is part of what the client approved).
5. **A persona is deleted.** Its concepts are deleted with it, with their chips, links, decisions and image objects (§1.8). The confirmation lists them (§3.4).
6. **A hand-picked chip** can be any USP of the wave, whether or not the persona has a cell for it.

### 1.4 The decision and the status (C5)

- **Record:** `POST …/concepts/{c}/decision` with `status`, `client_name`, and either `note` (optional, with `approved`) or `comment` (required, with `changes_requested`). One transaction under the concept's row lock: insert the decision, set `wave_concepts.status`, audit.
- **Any status to any decision** is allowed (a client may approve after asking for changes, or change their mind). A decision recorded again with the same status adds a row (it can correct a mistyped name).
- **No way back to `awaiting` by hand** (there is no "Undo", Q13). The only ways are the edits below.
- **Which edits send an `approved` concept back to `awaiting`.** The set of things the client saw: `title`, `persona`, `funnel_stage`, `funnel_note`, the nine text fields (`product`, `angle`, `primary_message`, `hypothesis`, `hook`, `format_visual`, `key_points`, `payoff`, `cta`), the chip set, adding or removing an image, adding, editing or removing a link, and the USP-delete and persona-change cascades above. **Not** edits: a renumber (the "n" moving after another concept is deleted), a duplicate of it (the copy starts `awaiting`; the original is untouched), recording a decision. An edit that changes nothing after cleaning (the same values written again) does **not** reset it. `changes_requested` stays `changes_requested` when edited (Q6).
- The reset is in the same transaction as the edit; the response says `reset_to_awaiting: true` and the audit row carries the same flag. The edit dialog shows an inline notice while the concept is approved: "Saving changes sends this concept back to Awaiting client."
- **Staff cannot record a decision:** 403 `forbidden` (§3.3).
- **Audit:** ids, status, counts, booleans; never the name, note or comment (§3.6).

### 1.5 Images: the rules (C4)

| Rule                        | How                                                                                                                                                                                                                                                                                                                                                                                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Types                       | `image/jpeg` (jpg), `image/png` (png), `image/webp` (webp), a new three-entry map in `ConceptImageUploadService`. `AttachmentMimeTypes` is not edited.                                                                                                                                                                                                                                                        |
| Count                       | at most **10** per concept, counting `ready` rows and live `pending` rows                                                                                                                                                                                                                                                                                                                                     |
| Total                       | at most **300 MB** (`300 * 1024 * 1024` bytes) per concept, counting `size_bytes` of `ready` rows and `declared_bytes` of live `pending` rows. No separate per-image cap beyond what the total gives (one image may use the whole 300 MB; Q9).                                                                                                                                                                |
| Declared size at `init`     | integer, at least 1, at most 300 MB; the presign signs `ContentLength` and `ContentType`                                                                                                                                                                                                                                                                                                                      |
| MIME and size at `complete` | read from the stored object (`Storage::mimeType`, `Storage::size`), plus a header check of its first 64 KB with `getimagesizefromstring`: it must decode as JPEG, PNG or WebP and agree with the key's extension. Real size over the declared size, empty, wrong MIME or unreadable header: the object is deleted, the pending row is deleted, 422.                                                           |
| Reads                       | only through the API (§3.5): `GET …/images/{i}/file` and `…/thumbnail` stream the object after the policy check, with the stored MIME, `X-Content-Type-Options: nosniff`, `Cache-Control: private`, and for `/file?download=1` `Content-Disposition: attachment`. No presigned GET is ever returned. SVG and GIF are not allowed, so nothing served can script.                                               |
| Thumbnails                  | 512 px longest side (`PortfolioImageProcessor::THUMBNAIL_MAX_EDGE`), JPEG, made by a queued job after `complete` using the same pre-decode megapixel guard (50 MP). The original is **not** re-encoded (a reference is kept as uploaded; EXIF stays in the stored object and is never served to a different page). A guard or decode failure leaves `thumb_key` null; the tile shows the frame's placeholder. |
| No virus scan               | the same as every upload surface; accepted only while agency staff upload; **must be solved before PL-4** (tech-debt row 40, referenced in the review; row 40 is not closed here)                                                                                                                                                                                                                             |

### 1.6 Links (C4)

- The person pastes a URL and types a title; **the server never fetches the URL** (no request is made to it by any code).
- Validate on write, on the **server** (the web repeats it for the message only): scheme must be `https` (Q7 on `http`); a host is required and must contain a dot; no user-info (`user:pass@`); no IP literal and no `localhost`; no whitespace or control character; at most 2048 characters; the URL is stored trimmed. `javascript:`, `data:`, `file:`, `mailto:` and every other scheme fail with 422 `link.invalid_url`.
- The **domain** shown is the host of the stored URL, lowercased, with a leading `www.` removed; an internationalised host is shown as stored (the ASCII form), so a look-alike is visible. It is derived in the resource on every read.
- The link renders as an `<a>` with `target="_blank"` and `rel="noopener noreferrer"`, text interpolated (never `v-html`). The href is only ever set from a URL that passed the scheme check, and the web re-checks the scheme before binding it (so a row written by some other path can still not render a `javascript:` link).
- At most **10 links** per concept (Q8). A link's title is required and at most 120 characters.
- **Tech-debt row 46 (added at build):** automatic link titles need a fetcher that makes a server request to a user-supplied URL; that needs a locked-down fetcher (an allow-list of schemes and ports, DNS resolved once and pinned, private, loopback and link-local ranges refused, redirects re-checked, a small size and time limit, no cookies). Not built.

### 1.7 The total cap under parallel uploads, and orphaned objects

**Parallel uploads (C4).** The browser may start several uploads at once (the brand upload dialog already takes `multiple`), so several `init` calls can arrive together. The cap is a **reservation made at `init`**, under a lock:

1. `init` runs in a transaction. First statement after the flag and policy checks: `SELECT … FROM wave_concepts WHERE id = ? FOR UPDATE` (the **concept** row, not the wave: image traffic must not queue behind every strategy edit of the wave).
2. Under that lock, count the concept's `ready` rows and its `pending` rows whose `expires_at` is in the future, and sum `size_bytes` for ready plus `declared_bytes` for live pending. If the count would pass 10 or the sum would pass 300 MB: 422 `concept_image.limit_count` or `concept_image.limit_bytes` with the numbers in `meta` ("3 of 10", "250 of 300 MB used"), and **no presign is issued**.
3. Otherwise insert a `pending` row (`declared_bytes`, `expires_at`, the new `storage_key`) and presign. The lock is released at commit, so the next `init` sees this reservation.
4. `complete` takes the same lock, checks the pending row exists and has not expired, verifies the object (§1.5), sets `size_bytes`, `mime_type`, `status = ready`, `expires_at = null`, then re-checks the total with the real size (a smaller real size only frees room). It inserts nothing: the row exists already, so a second `complete` of the same upload finds a `ready` row and answers 422 `concept_image.already_completed`, as brand files do.
5. A deleted image frees its bytes at once (the row goes in the same transaction as the object delete).

**Orphans.** The `pending` row is the handle brand files lack. An upload that is never completed leaves a `pending` row with a past `expires_at` and perhaps an object.

- Expired `pending` rows stop counting for the cap immediately (step 2), so an abandoned upload never blocks a person for more than 20 minutes.
- A **sweeper** (a scheduled artisan command `strategy:sweep-pending-concept-images`, every 15 minutes; where the scheduler is registered was not read, §5.5 of the inventory, the build confirms and follows the erasure scheduler's pattern) deletes the object (a missing object is a success) and then the row, for every `pending` row more than 1 hour past `expires_at`. Object first, as everywhere: if the delete fails the row stays and the next run retries.
- Objects with **no row at all** (a crash between an object write and its row) cannot be found without listing the bucket. **Tech-debt row 47 (added at build):** no sweep of unreferenced objects under `…/concepts/`; the duplicate flow (§1.8) and the thumbnail job are the only writers that could produce one, and both write a row first.

### 1.8 Delete and duplicate: what happens to the image objects

**Delete a concept (and a persona that has concepts).** Object-first, as brand files:

1. Read the concept's image rows (storage keys and thumbnail keys).
2. Delete every object (a missing object counts as deleted). If any delete is not confirmed: 500 `concept.delete_failed`, **nothing is removed from the database**, and a retry is safe.
3. In one transaction under the wave lock and the concept lock: re-read the image rows; if the set changed since step 1 (an upload finished meanwhile) the transaction rolls back and steps 1 to 3 run once more, then 409 `strategy.conflict`. Delete decisions, links, chips, image rows, then the concept; renumber the rest of the market; audit with counts.

**Duplicate a concept.** The copy **never shares an object** with the original: `storage_key` is unique in the database, and every copied image is a new object at the copy's own path.

1. Transaction 1 (under the concept lock): create the new concept (`awaiting`, no decisions, `position` last, title = the original's with " (copy)" appended, cut to 120 characters, same persona, stage, note, nine fields and chips), copy the links, and insert one **`pending`** image row per original image (new keys, `declared_bytes` = the original's `size_bytes`). Counts are not re-checked: the copy is no bigger than the original.
2. Outside the transaction: server-side `Storage::copy` of each object (and its thumbnail if any) to the new key.
3. Transaction 2: set each copied image `ready` with the copied object's real size and the same `mime_type`, `thumb_key`, `original_filename`.
4. If a copy fails: delete the copies already made (a failed delete leaves a `pending` row for the sweeper), delete the new concept with its rows, 500 `concept.duplicate_failed`. A crash between steps leaves `pending` rows, which the sweeper clears. Deleting the copy later deletes only the copy's objects; the original's are untouched (a gate proves it).
5. A duplicate of a concept with images can take seconds (server-side copies); the web shows the card's busy state.

### 1.9 The completion rule and the warnings (C6)

Nothing is stored; everything is derived from the concept rows.

- **A market is complete for step 4** when it has at least one concept **and** every concept is `approved`.
- **Soft warning:** fewer than 3 concepts in a market ("3 minimum, no maximum", the frame's sub-line, becomes a warning, never an error). It never blocks adding, approving or leaving.
- **No hard lock:** steps 5 and 6 are never locked by concepts. They are still not built (PL-3), so what the stepper shows now is in §4.5.
- **The warning for steps 5 and 6** appears whenever any market of the wave is not complete: "{approved} of {total} concepts approved", or "No concepts yet" when there are none. It is a message, not a block.
- **Record for PL-3 (to go in the PL-3 inventory and the sprint file):** the Creators screen and the Scripts screen show this same warning (the same string keys `app.strategy.concepts.warning*`, the same counts from the summary endpoint) and **never block**. PL-3 must not read "locked".

### 1.10 Free text, limits and the save model (C10)

All text goes through `StrategyText::clean` (as typed, control characters removed, CRLF to LF, trimmed, empty is null) and renders escaped (no `v-html`; the `noVHtml.spec` already scans this folder). Lengths are characters (`mb_strlen`), one place: `StrategyLimits`.

| Field            | Limit              | Why this number                                                    |
| ---------------- | ------------------ | ------------------------------------------------------------------ |
| Concept title    | 120                | the frame's titles are 20 to 25 characters at 18 px; two lines fit |
| Funnel note      | 40                 | the frame's notes are 9 to 13 characters, shown in a chip          |
| Product          | 160                | one line of the frame                                              |
| Angle            | 300                | the frame's longest is 90                                          |
| Primary message  | 300                | the frame's longest is 80                                          |
| Hypothesis       | 400                | the frame's longest is 117                                         |
| Hook, Payoff     | 300 each           | quoted lines of 40 to 80                                           |
| Format / visual  | 400                | the frame's longest is 105                                         |
| Key points       | 8 items, 160 each  | the frame has 2 to 3                                               |
| CTA              | 200                | the frame's longest is 45                                          |
| Client name      | 80                 | a name and a company                                               |
| Decision note    | 500                | optional, only with Approved                                       |
| Client's comment | 1000               | the frame's is 112; a paragraph of feedback                        |
| Link title       | 120; link URL 2048 |                                                                    |
| Image file name  | 255                | stored for display; never used in a path (the key is a ulid)       |

**Save model, as PL-2.1 and PL-2.2.** Concept text, persona, stage, note and chips are edited in a dialog with an explicit **Save**. A save sends only the fields the person changed (a PATCH with those keys), so two people editing different fields both win; the same field is last write wins; no version column, no conflict dialog. Errors are shown **fully**, above the dialog's actions, with the field's name (no truncation, wraps; the PL-2.1 `revealError` pattern). Images, links and decisions are **their own actions**, each one request and one result, not part of the dialog's Save (they are not text to type and then confirm, and an upload already has its own progress). The two ways to save are named in the review so nobody expects an image to wait for Save.

Concurrency: PATCH, duplicate, delete, decision and image init/complete all take the concept row lock; delete of a persona or USP and every wave-level edit take the wave lock (`StrategyGuard::lockWave`) first. Order is always wave, then concept.

---

## 2. Migrations

Five files, dated after `2026_11_21_100001`, one per table, in dependency order (`wave_concepts`, then the four children). Each: `SET LOCAL lock_timeout = '5s'` first on PostgreSQL, `Schema::create`, the indexes, the CHECK constraints on PostgreSQL as raw statements after (as `wave_matrix_cells`), `down()` drops in reverse. **Schema only.** No `DB::table`, no seed, no backfill. No existing migration is edited.

Run order in the build: SQLite for the test suite, PostgreSQL for the second suite, and the throwaway-database rehearsal of §8. The review records each statement's lock list from `pg_locks`.

---

## 3. API

### 3.1 The first lines of every action

1. `StrategyEnabled::active()` (both flags): 404 `not_found`, before the policy and before the body is read.
2. Agency, brand, wave resolved by ulid and scoped to the agency (404 for another agency's); `{concept}`, `{image}`, `{link}` looked up by ulid scoped to agency, wave and market (404).
3. `Gate::authorize` (§3.3).
4. Writes: `StrategyGuard::assertBrandWritable` (422 `wave.brand_archived`), then the transaction and locks of §1.10. Reads work on an archived brand.

### 3.2 Endpoints (under `agencies/{agency}/brands/{brand}/waves/{wave}/strategy`)

| Method and path                                                      | Purpose                                                                                                                                                                                                                                                                                                              |
| -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET concepts?market=SK`                                             | the market's concepts in order, each with chips (USP ulid, title, colour), images (no storage key; `thumbnail_url`, `file_url` as API paths), links (with derived domain), the **latest decision** (status, client name, comment, `recorded_at`, who recorded), and the market's counts, `complete`, `under_minimum` |
| `POST concepts`                                                      | create: `market`, `persona` (ulid), `title`; optional stage and note; chips pre-filled by the server (§1.3); 201                                                                                                                                                                                                     |
| `PATCH concepts/{c}`                                                 | change any of title, persona (+ `usp_mode`), stage, note, the nine fields, the chip set (`usps` = ordered list of USP ulids, replaces the set); returns `reset_to_awaiting`                                                                                                                                          |
| `POST concepts/{c}/duplicate`                                        | §1.8; 201                                                                                                                                                                                                                                                                                                            |
| `DELETE concepts/{c}`                                                | §1.8; 204                                                                                                                                                                                                                                                                                                            |
| `POST concepts/{c}/decision`                                         | admin and manager; §1.4                                                                                                                                                                                                                                                                                              |
| `POST concepts/{c}/images/uploads/init`                              | reservation and presign (§1.7); body `mime_type`, `declared_bytes`, `original_filename`                                                                                                                                                                                                                              |
| `POST concepts/{c}/images/uploads/complete`                          | `upload_id`; verification (§1.5); returns the image                                                                                                                                                                                                                                                                  |
| `DELETE concepts/{c}/images/{i}`                                     | object-first, then the row; counts as an edit                                                                                                                                                                                                                                                                        |
| `GET concepts/{c}/images/{i}/file`, `…/thumbnail`                    | streamed, authenticated (§1.5); `file?download=1` for attachment                                                                                                                                                                                                                                                     |
| `POST concepts/{c}/links`, `PATCH …/links/{l}`, `DELETE …/links/{l}` | §1.6; each counts as an edit                                                                                                                                                                                                                                                                                         |
| `GET strategy` (existing summary)                                    | gains `concepts`: `{ total, approved, awaiting, changes_requested, by_market: [{ market, total, approved, complete }], markets_complete }`                                                                                                                                                                           |

All concept endpoints answer one concept resource shape so the web replaces one card from any response. List ordering is `position, id`. One list request per market, with eager loading of chips, images, links and the latest decision (no N+1); the `concepts` payload is bounded by Q5.

### 3.3 Roles (C9)

A new `WaveConceptPolicy` (the strategy policy is not edited). The role is read from the agency of the current request (`CurrentAgencyRole`).

| Ability                                                          | Admin | Manager | Staff               | Creator or unrelated user |
| ---------------------------------------------------------------- | ----- | ------- | ------------------- | ------------------------- |
| view, create, update, delete, duplicate, manage images and links | yes   | yes     | yes                 | 403                       |
| `recordDecision`                                                 | yes   | yes     | **403** `forbidden` | 403                       |

Deleting a persona or USP that concepts use is §3.4.

### 3.4 Deleting a USP or a persona that concepts use

- **USP.** `UspUsage::affected` gains items of kind `concept` (one per concept that carries the chip; fields `persona`, `market`, `code` = "Concept n", `title`, `level` null, `explanation` = `''`). The `confirm_count` rule is unchanged: the number the person confirms is `count(affected)`, now including concepts. The dialog lists the concepts after the angles and cells and, for an approved concept, says "goes back to Awaiting client". The audit metadata gains `chips_removed` and `concepts_reset` (counts). `deleteInUse` (admin and manager) still governs.
- **Persona.** The server gains a counted confirmation **only when the persona has concepts**: the first request answers 422 `persona.in_use_by_concepts` with `{count, affected: [{concept, number, title, status}], images, links}`; the same request with `confirm_count` equal to the count deletes. With no concepts the endpoint behaves exactly as today (no confirmation, any role), so PL-2.1 and PL-2.2 behaviour is unchanged. Who may confirm: Q4.
- Both dialogs show the concepts' number, title and status, and the images and links that go with them ("3 images and 2 links are deleted").

### 3.5 Image reads

`ConceptImageController@file` and `@thumbnail`: flag, tenancy, policy `view`, then `Storage::disk('media')->readStream` into a streamed response. A missing object is 404, not 500. The thumbnail endpoint answers 404 when `thumb_key` is null (the web already shows the placeholder). The web fetches with its own authentication and turns the response into a blob URL (works for cookie and bearer alike); blob URLs are revoked when a card unmounts.

### 3.6 Audit

Eleven new `AuditAction` cases, each in the `AuditActionEnumTest` catalogue in the same commit as the code that writes it, with **literal** payload keys (no `dynamicPayloadFiles` entry), and each key decided in `AuditPayloadKeys` in the same commit.

| Action                                            | Metadata (keys)                                                                                                      |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `wave_concept.created`                            | `wave`, `concept`, `market`, `persona`, `chips` (count)                                                              |
| `wave_concept.updated`                            | `wave`, `concept`, `market`, `fields_changed` (list of field **names**), `chips` (count), `reset_to_awaiting` (bool) |
| `wave_concept.duplicated`                         | `wave`, `concept` (new), `from_concept`, `market`, `images` (count), `links` (count)                                 |
| `wave_concept.deleted`                            | `wave`, `concept`, `market`, `chips_removed`, `images_removed`, `links_removed`, `decisions_removed`                 |
| `wave_concept.decided`                            | `wave`, `concept`, `market`, `status`, `has_note` (bool), `has_comment` (bool). **No client name, note or comment.** |
| `wave_concept_image.added`                        | `wave`, `concept`, `image`, `mime_type`, `size_bytes`, `reset_to_awaiting`                                           |
| `wave_concept_image.removed`                      | `wave`, `concept`, `image`, `size_bytes`, `reset_to_awaiting`                                                        |
| `wave_concept_link.added`, `.updated`, `.removed` | `wave`, `concept`, `link`, `reset_to_awaiting` (no URL, no title)                                                    |

New keys to decide: `concept`, `from_concept`, `image`, `link`, `fields_changed`, `chips`, `chips_removed`, `concepts_reset`, `images`, `images_removed`, `links`, `links_removed`, `decisions_removed`, `reset_to_awaiting`, `has_comment` (all "an id, count, flag or field name, not personal"). Existing and reused: `wave`, `persona`, `market`, `status`, `has_note`, `mime_type`, `size_bytes`. **Never used as a key:** `name` (a scrub key), `note` (a kept key), `comment`, `title`, `url`, `client_name`. The decision rows themselves hold who and when; the audit row says the decision was made and by whom (actor), not what was typed.

### 3.7 Erasure and tenancy

- **Registry** (`ErasureRegistry.php`, next to `wave_matrix_cells`): `wave_concepts`, `wave_concept_usps`, `wave_concept_images`, `wave_concept_links`, `wave_concept_decisions` as `NotApplicable`, "Agency-authored (§3.7)". `refs`: `wave_concept_images` `['uploaded_by_user_id']`, `wave_concept_decisions` `['recorded_by_user_id']`, the others empty. `classified`: every free-text column with a reason. **`wave_concept_decisions.client_name`, `note` and `comment`: "typed by agency staff about a client contact (a third party, not a creator or user); kept; removed with the concept"** (Q10). `wave_concepts.title` and the nine fields: "agency-authored free text"; `wave_concept_links.title` and `url`: "agency-authored; the URL is a reference the agency pasted"; `original_filename`: "a file name typed by the uploader's computer, agency-authored".
- **Tenancy:** `BelongsToAgency`; the schema test asserts `agency_id` on all five; the access test asserts a row of another agency is a 404 on every endpoint, and the composite FKs refuse cross-wave and cross-market rows at the database.

---

## 4. Web

### 4.1 What is built (from the frame, inventory §1)

Route `strategy.concepts` (the same market handling as the matrix). New files, all under `apps/main/src/modules/strategy`: `ConceptsStep.vue` (heading, banner, grid, empty slot), `ConceptCard.vue`, `ConceptDialog.vue` (create and edit), `ConceptDecisionDialog.vue`, `ConceptReferences.vue` (the references block), `ConceptImageTile.vue`, `ConceptLinkDialog.vue`, `conceptsLogic.ts` (counts and warnings, the same file the Vitest specs use), `useConceptImageUpload.ts` (progress, retry, up to 3 parallel uploads, each with its own `init`). The API module and `@catalyst/api-client` types gain the concept calls and the `concepts` summary block.

| Frame part                                                | Built as                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Heading "Concepts · {market}", button "Add concept"       | built; sub-line text changes (C6): "Built from the matrix · 3 minimum, no maximum" (Q5 changes the second half if a ceiling is ruled)                                                                                                                                                                                                                                                                                                                                               |
| "Generate concepts" button, "Generate from matrix" button | **not built (C1)**; the empty slot has only "Add concept" and the count line                                                                                                                                                                                                                                                                                                                                                                                                        |
| Banner                                                    | amber when not all approved: title and counts "{n} approved · {n} awaiting · {n} changes requested" (the frame's exact tail); **title text replaced** (C6): "Not every concept is approved yet. Creators and scripts are not blocked." The lock icon is kept (the frame draws it) only in the amber state; a green state ("All concepts approved") when `complete`; no banner when the market has no concept. "Send reminder" **not built (C7)**. "Approve on client's behalf": Q2. |
| Card                                                      | all of §1.3 of the inventory: header tint from the persona's colour, chip, status pill, title, persona chip, funnel chip, USP chips, the nine fields (an empty field is not drawn), Hook, Payoff and CTA in the yellow box, Key points as a bullet list, References, the red feedback footer on Changes requested. **Comment count not built (C8).**                                                                                                                                |
| Card menu                                                 | Duplicate, Delete (C2), plus the two items Q1 asks to add: Edit and Record client's decision (admin and manager only). No "regenerate" (C1).                                                                                                                                                                                                                                                                                                                                        |
| Funnel chip                                               | a menu of TOFU, MOFU, BOFU (the notes are typed in the edit dialog; the chip shows "STAGE · note")                                                                                                                                                                                                                                                                                                                                                                                  |
| References                                                | buttons "Image" and "Link"; image tiles 96 × 64 (the real thumbnail when there is one, else the frame's placeholder tile with the icon), caption, remove x; link rows; the empty drop zone with the frame's text. A drop and a click both start an upload. Up to 10 tiles, wrapping.                                                                                                                                                                                                |
| Empty slot                                                | the frame's dashed tile with "Add concept" and "{n} concepts" (the frame's "· no maximum" only if Q5 is answered "no ceiling")                                                                                                                                                                                                                                                                                                                                                      |
| Right column                                              | **not built** (Notes PL-2.4, Comments PL-5); the grid takes the 912 px width until then (§4.2)                                                                                                                                                                                                                                                                                                                                                                                      |

### 4.2 Assumptions with no design (named; each is measured and walked)

1. The create and edit dialog: fields in the card's order, labels as the card's, counters as PL-2.1, the persona as a select of the market's personas (code, title, colour dot), the funnel as a select with the note beside it.
2. The decision dialog: two choices (Approved, Changes requested), client name, note or comment, errors above the actions, the same dialog frame as `StrategyConfirmDialog`.
3. The Approved card gets one quiet footer line of the same geometry as the red footer (green at the same percentages: fill `rgba(52,199,123,0.07)`, top border `rgba(52,199,123,0.3)`): "Approved by {client name} · {date}" (Q3).
4. Banner green state and the under-three line: an amber text line under the sub-line, "Only {n} concept(s) in {market}; three or more are recommended."
5. Narrow screens: one column of cards under 1100 px; the references tiles wrap; the banner's buttons wrap under the text.
6. The upload progress per tile (a thin bar), a failed upload (the error above the references, with a Retry), the limit messages from the server with their numbers.
7. Image alt text is the file name; the tile is a button that opens the image in a dialog (a larger blob view, close with Esc).

### 4.3 Widths

Wide (1440) and narrow (390) are both walked (§6). A 1300 px layout with the right column absent uses the card grid at its full width, two columns of up to 444; the frame's 912 width is kept so the cards stay at the frame's size, the empty space is left (the column returns with PL-2.4 and PL-5).

### 4.4 Frozen files

Not edited: `AttachmentMimeTypes`, every `BrandFile*` class and screen, `PortfolioImageProcessor` (it is only called, with its constants), the PL-1 and PL-2.1 tables' migrations, the matrix and persona services' logic (only the two delete paths in §3.4 are extended), the tracker and the Brand page bar (C11: no change to either, not even a count).

### 4.5 Changes to the PL-2.1 and PL-2.2 screens

- **Stepper.** `constants.ts`: step 4 `live`; steps 5 and 6 `not_built_warn` (a new state): a plain element as before (not a link, not in the tab order, `aria-disabled`), **no lock icon**, its circle shows the number, and the status line is "Not available yet" plus the C6 warning when any market is incomplete ("{approved} of {total} concepts approved" or "No concepts yet", with an amber warning icon before the text). **"Locked until all concepts are approved" is deleted** (`app.strategy.stepper.locked`, 24 locales) and replaced by `app.strategy.stepper.concepts*` keys. Step 4's own line: "{approved} of {total} approved" (the frame's "Client review · 1 of 3" is a PL-4 word), "No concepts yet" when none. `StrategyPage.spec.ts:97–105` is rewritten.
- **USP delete and persona delete dialogs** list concepts (§3.4).
- **The summary endpoint** gains the `concepts` block; nothing else in it changes.
- **Persona and USP cards** do not show a concept count (not drawn; left out).

---

## 5. Flag

`strategy_enabled`, the existing flag, needs `waves_enabled`; OFF by default; **no new flag**. With either flag off every concept endpoint is 404 `not_found` and `features.strategy` is false, so the Strategy entry, the route and the stepper step are absent. A gate proves "flag off: nothing changes" on the API (404 on every new route, no row in any new table, the existing strategy responses byte-identical) and on the web (the two walk baselines, §6).

---

## 6. Tests (each gate has a break-revert)

### Backend gates (Pest, SQLite and PostgreSQL unless noted)

| Gate                               | What it proves                                                                                                                                                                                                                                                                                                                             | Break-revert                                      |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------- |
| Both flags                         | every new route: strategy off, waves off, both off: 404 `not_found` before the policy and body                                                                                                                                                                                                                                             | remove the flag check from the concept controller |
| Tenancy                            | another agency's wave, concept, image, link, persona or USP ulid: 404 on every endpoint; nothing written; the schema test finds `agency_id` on all five tables                                                                                                                                                                             | look up a concept without `agency_id`             |
| Composite FKs (DB)                 | a chip of another wave's USP, a child with another wave or another market than its concept, a concept whose persona is of another market or wave: all refused by the database                                                                                                                                                              | drop one composite FK in the test schema          |
| Roles, C9                          | admin, manager, staff create, edit, duplicate, delete concepts and manage images and links; a creator and an unrelated user get 403                                                                                                                                                                                                        | make `update` require manage                      |
| Decision gate                      | admin and manager record; **staff get 403**; creator 403; no row, no status change, no audit on a refusal                                                                                                                                                                                                                                  | allow `recordDecision` for every agency role      |
| Decision rules, C5                 | Approved needs a client name (note optional); Changes requested needs name and comment; a comment with Approved or none with Changes requested: 422; any status to any decision; the decision row keeps the user and time; the status column follows the latest row                                                                        | drop the comment requirement                      |
| Decision audit                     | the audit row has ids, status, `has_note`, `has_comment`; **the name, note and comment strings appear in no audit row** (a test scans the row's JSON)                                                                                                                                                                                      | add `client_name` to the metadata                 |
| Edit resets approval               | each edit in the §1.4 list sends `approved` to `awaiting` (a case per field group, per chip change, per image add and remove, per link add, edit and remove); a no-op save, a renumber, a duplicate and a decision do not; `changes_requested` stays                                                                                       | skip the reset in the image path                  |
| Image rules                        | not JPG, PNG or WebP: refused at `init`; the 11th image refused; the 301st MB refused; `init` of 300 MB then `init` of 1 byte refused (reservation counts); expired pending does not count; declared size over 300 MB refused                                                                                                              | change 10 to 11                                   |
| Image bytes at `complete`          | a file whose bytes are not an image with a `.png` key: refused, object and row deleted; real size over declared: refused; MIME and size stored are the object's, not the request's (the request lies and the row still has the truth)                                                                                                      | read the MIME from the request                    |
| Image reads                        | file and thumbnail need the policy; another agency's image: 404; the response has the stored MIME and `nosniff`; **no presigned URL appears in any response** (a scan of the resource)                                                                                                                                                     | return a signed URL in the resource               |
| Reservation lock (PostgreSQL only) | with a second connection holding `FOR UPDATE` on the concept row and `lock_timeout` set short, `init` fails with a lock error: the lock exists. Sequential `init` calls cannot show it, so this test is the proof                                                                                                                          | remove `lockForUpdate` from `init`                |
| Orphans                            | the sweeper deletes an expired pending row and its object (and keeps one that is not expired); a failed object delete keeps the row                                                                                                                                                                                                        | skip the object delete (the row goes alone)       |
| Delete concept                     | object-first: a failing delete of one object removes no row; success removes images, thumbnails, links, chips, decisions; the rest renumber; the audit counts match                                                                                                                                                                        | delete rows before objects                        |
| Duplicate                          | the copy has its own object keys (no key shared, the database unique holds); deleting the copy leaves the original's objects; the copy is `awaiting` with no decision; a failed copy leaves no concept and no stray row                                                                                                                    | copy the row but reuse the `storage_key`          |
| Link rules                         | `javascript:`, `data:`, `file:`, `http:` (per Q7), a URL with user-info, an IP literal, `localhost`, no dot, over 2048, control character: 422; a good `https` URL is stored trimmed; **no HTTP request is made by any code path** (`Http::fake()` plus `Http::assertNothingSent()` around create, update and read)                        | add a fetch of the URL                            |
| Link domain                        | `www.` removed, lower-cased, derived from the stored URL                                                                                                                                                                                                                                                                                   | store the domain in a column                      |
| C6 completion                      | no concepts: not complete; one approved: complete; two concepts one awaiting: not; under three: `under_minimum` true and nothing blocked; the summary's counts match                                                                                                                                                                       | count `awaiting` as complete                      |
| Not locked                         | steps 5 and 6 data has no lock field; an API call that PL-3 will make is not refused by concepts (there is none yet: the gate is that the summary has no `locked` key)                                                                                                                                                                     | add a `locked` flag                               |
| Delete cascades                    | USP with chips: confirmation lists concepts (count includes them), confirm deletes, chips gone, an approved concept reset, staff refused; persona with concepts: first request 422 with the list, with `confirm_count` it deletes concepts, images' objects, links, chips, decisions; persona without concepts unchanged (no confirmation) | skip the chip delete (the FK then refuses)        |
| Free text                          | each limit refused one over and accepted at the limit; control characters removed; key points: 9 items refused                                                                                                                                                                                                                             | change a limit                                    |
| Persona change                     | without `usp_mode`: 422; `keep` keeps chips; `refill` rebuilds from the new persona's cells                                                                                                                                                                                                                                                | ignore `usp_mode`                                 |
| Chips from the matrix              | Priority first then Support, by USP position; Low never; a persona with no cell gives none; a later cell edit does not touch existing chips                                                                                                                                                                                                | read Low cells too                                |
| Idempotency and clashes            | the same PATCH twice changes nothing the second time (no `updated_at`, no reset); two creates in a row give positions 1 and 2                                                                                                                                                                                                              | write always                                      |
| Schema                             | the five tables, CHECKs on PostgreSQL, the unique keys, the FK actions; `origin` accepts only `manual`                                                                                                                                                                                                                                     | drop a CHECK                                      |
| Audit catalogue                    | the eleven actions are in `AuditActionEnumTest`; `AuditPayloadKeysGuardTest` passes with the new keys decided; no `dynamicPayloadFiles` entry                                                                                                                                                                                              | remove a key's decision                           |
| Erasure                            | `RegistryVerifier` passes: five tables, `refs`, classified columns; the decision's `client_name`, `note`, `comment` classified                                                                                                                                                                                                             | remove a `refs` entry                             |
| Flag off, no change                | the existing Strategy, Waves and Brands tests pass unmodified (except the three the plan names: the stepper string and the two delete dialogs)                                                                                                                                                                                             | n/a                                               |

### Web gates (Vitest and Playwright)

| Gate                               | What it proves                                                                                                                                                                                                                                     | Break-revert                 |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- |
| `conceptsLogic.spec`               | counts, complete, under three, warning text keys                                                                                                                                                                                                   | change the rule to `>=`      |
| Stepper                            | step 4 live link with counts; steps 5 and 6 are not locked (no lock icon, no "Locked" string anywhere), show "Not available yet" and the warning when incomplete, are not links, not in the tab order                                              | restore the lock             |
| Card                               | a card per state with the exact class set for the status pill, the red border and the footer; an empty field is not drawn; Hook, Payoff, CTA in the box                                                                                            | render empty fields          |
| Roles in the UI                    | staff see no "Record client's decision" and no banner button; manager sees both                                                                                                                                                                    | show it to staff             |
| Dialogs                            | the edit dialog sends only changed fields; the persona-change question; the error is fully visible above the actions; the approved notice                                                                                                          | send every field             |
| Images                             | the 11th file is refused before any request (and the server message shown when it arrives); progress; a failed upload retries; remove                                                                                                              | skip the count check         |
| Links                              | a bad scheme gives the message and no request; the rendered `<a>` has `target="_blank"` and `rel="noopener noreferrer"`; a `javascript:` URL in the data renders as text, not a link                                                               | drop the `rel`               |
| No `v-html`                        | the existing `noVHtml.spec` covers the new files                                                                                                                                                                                                   | add a `v-html`               |
| Locales                            | the 24 locales have every key (`verify-locale.mjs`, a `conceptsKeys.spec.ts`)                                                                                                                                                                      | remove a key from one locale |
| Playwright `pl23-concepts.spec.ts` | short, serial, **its own setup** (a seeder or API setup of its own, not the walk seeder): create, fill, chips from the matrix, add an image and a link, record a decision as manager, the same refused for staff, edit an approved concept, delete | n/a                          |

### §4.2 real-browser checks (`WORKING-PROCESS.md`, ruling 10)

Real browser, **wide (1440) and narrow (390)**, before the fidelity table is offered. Every dialog and menu (create, edit, decision, link, image viewer, the persona-change question, the USP and persona delete, duplicate and delete confirmations, the card menu, the funnel menu) is **opened, closed and reopened quickly** (no stale content, no stuck overlay, the dialog card is keyed by a counter as `StrategyConfirmDialog`). The fidelity table sets each value of inventory §1 beside the rendered computed style (getComputedStyle), with every difference marked VISUAL (fixed) or FUNCTIONAL (reported). Menus opened from a dialog use `MENU_PROPS` (z-index above the dialog).

### Local gates (before any push, `WORKING-PROCESS.md` §4.1)

`vue-tsc`, ESLint, Pint, Larastan; Pest groups **Strategy, Waves, Brands, Audit, Erasure, Tenancy** on SQLite and on PostgreSQL (`catalyst_test`, never `catalyst`); Vitest; the locale gate; the Playwright spec (`PLAYWRIGHT_BROWSERS_PATH=$HOME/Library/Caches/ms-playwright`, `TEST_HELPERS_TOKEN` from the local test setting, ports 8000 and 5173 free). **Flag-off check:** `node tools/walk/walk.mjs --set agency --compare docs/reviews/ah-115-agency-baseline/baseline.json` (126 targets) and `--set creator` against its baseline (146 targets) both identical. Not run in this task.

---

## 7. Strings

All new strings in the **24 locales**, under `app.strategy.concepts`: heading and sub-line; Add concept; the three status words (pill: "Approved by client", "Awaiting client", "Changes requested"; plural-aware counts line "{n} approved · {n} awaiting · {n} changes requested"); banner texts (amber, green, under three); card labels (Product, Angle, Primary message, Hypothesis, Hook, Format / visual, Key points, Payoff, CTA); funnel stages and the default notes; the menu items; create and edit dialog (labels, hints, counters, the persona-change question and its two answers, the approved notice, "no Priority or Support USP in the matrix yet"); decision dialog (title, the two choices, client name, note, comment, errors); references (title, "images & links", Image, Link, drop-zone text, progress, retry, limits with numbers, remove, viewer); link dialog (labels, the `https` hint, errors); duplicate and delete confirmations; the USP and persona delete lists (concepts, "goes back to Awaiting client", "{n} images and {n} links are deleted"); the stepper keys that replace "Locked until all concepts are approved"; every error code of §3 (`concept.usp_mode_required`, `concept_image.limit_count`, `concept_image.limit_bytes`, `concept_image.already_completed`, `link.invalid_url`, `persona.in_use_by_concepts`, `concept.delete_failed`, `concept.duplicate_failed`); "removed by someone else"; loading and error. Roughly 170 keys, English first, the other 23 translated as in PL-1.3 §7.

---

## 9. Left out of this chunk

- "Generate concepts", "Generate from matrix", "regenerate" in the menu, anything labelled from the agent (PL-9). The model's hook is `origin`.
- "Preview as client", "Share with client", "Send to client", the sent-to line and date, "Send reminder", the wave chip "Concepts in client review" (PL-4, PL-5).
- Comments, comment counts, the Comments panel (PL-5); internal Notes (PL-2.4); the client's own page (frame `13:5062`).
- Reordering concepts; drag and drop between cards.
- Undo of a decision (Q13); a decision history screen (the rows are kept, nothing shows them).
- A live indicator that the matrix differs from a concept's chips (§1.3 rule 2).
- Automatic link titles (tech-debt row 46); a sweep of unreferenced objects (row 47); a virus scan (row 40, open, before PL-4).
- A concept count on the persona and USP cards; any change to the tracker or the Brand page bar (C11).
- A per-agency storage quota (an existing tech-debt row).
- Tech-debt rows 46 and 47 are written at build; this task writes no tech-debt row.

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A seeder pair **`Pl23WalkSeeder`** and **`Pl23WalkCleanupSeeder`** (local and testing only, refuse production, idempotent, additive, **never touches a flag**). `Pl23WalkSeeder` runs on top of `Pl22WalkSeeder`'s data (calls it if missing). Accounts: `admin@`, `manager@`, `staff@pl22-walk.local`, and `admin@pl22-walk-b.local` (agency B), password from the PL-2.1 seeder. Data:

- Brand Bolt Food, **Matrix Wave 1** (SK, RO, CZ): SK has the three concepts of the frame with their exact texts, in the three states: **"One Order, No Drama"** (SK1, MOFU · consideration, Selection and Speed & reliability, all nine fields, two image tiles, two links, **Approved by client**, recorded by the manager, client "Anna K."), **"The Fair Price Feast"** (SK2, BOFU · hard sell, Affordability and Selection, Awaiting client, one link), **"Tracker Tension"** (SK1, TOFU · awareness, Speed & reliability and Super app convenience, **Changes requested** with Anna K.'s comment, no references). RO has **one** concept (the under-three warning). CZ has no personas (an empty market).
- **Matrix Wave 2** (SK, RO): the concept-free wave the walk builds by hand.
- **Matrix Wave 3**: RO with eight personas (a long market bar) and **ten concepts** in RO (the grid and payload at volume).
- Agency B "PL-2.2 Walk Agency B": brand Glovo, one wave, one persona, one concept (isolation).
- **Image files for the walk**: the seeder writes twelve small valid PNGs and three valid image files padded to 110 MB with trailing bytes (an image decoder ignores them) into a local folder named in its output, so the limit steps need no download. A `fake` extension file (text renamed `.png`) is written too.
- **The database.** The walk runs on a throwaway database named in the walk instructions (`pl23_walk`), created with an explicit `--database` and printed with `select current_database()` before the seeder runs. Pedram's database `catalyst` is never the target. **The flags are turned on and off by hand in the admin app on that database; the seeder never writes one.**

**Steps.**

1. **Flag off, unchanged.** With `strategy_enabled` off: the wave page has no Strategy entry; opening the concepts route by URL is a not-found page; an API call to a concept route answers 404. Run the agency and creator walk comparisons (126 and 146 targets): identical.
2. Turn both flags on. Open Matrix Wave 1 → Strategy & Scripts. The stepper shows step 4 as a link "3 of … approved" style status; steps 5 and 6 show **no lock**, "Not available yet" and a warning "1 of 4 concepts approved" (count as seeded).
3. **Step 4, SK.** The three seeded cards match the frame in wide and narrow; the banner is amber with "1 approved · 1 awaiting · 1 changes requested"; the red card shows Anna K.'s comment.
4. **Create a concept** in RO as staff: pick persona RO1; the chips fill with RO1's Priority and Support USPs from the matrix (Low never). Fill every field; save; an error shows fully above the actions (try a 301-character angle).
5. **Persona change.** Edit the concept, pick RO2: the dialog asks Replace or Keep; try both on two concepts.
6. **Matrix change later.** Change a cell of RO1 in step 3; return: the earlier concept's chips did **not** change.
7. **Images.** Add three PNGs, remove one, add JPG and WebP; add the fake `.png`: refused. Add the three 110 MB files: the third is refused with "300 MB" numbers; add 11 small images: the 11th is refused. Start several uploads at once (select ten files together) and see the total hold. Open an image in the viewer; download one; confirm no link in the page points at a storage host.
8. **Links.** Add `https://www.tiktok.com/@x/video/1` with a title: the card shows "tiktok.com". Try `javascript:alert(1)`, `http://example.com`, `https://user:pw@example.com`, `https://127.0.0.1`: each refused with a message. Open a link: new tab, no opener.
9. **Decisions.** As manager, open the card menu, Record client's decision: Approved without a name (refused), with a name (the card turns green; the footer line). Changes requested without a comment (refused), with a comment (red border and comment). As **staff** the item and the banner button are absent; try the API call from the browser console: 403.
10. **Edit an approved concept.** As staff, edit "One Order, No Drama": the dialog says saving sends it back; save; it is Awaiting client; the decision is still kept (ask for it in the database or the audit log). Add an image to another approved concept: it resets too. Rename nothing and save: no reset.
11. **All approved.** Approve every SK concept: the banner turns green and Step 4 reads complete for SK; the stepper warning counts RO (not complete). Approve RO's one concept: complete, with the under-three line still shown.
12. **Steps 5 and 6** are never locked: the stepper shows the warning and no block (they are not built; nothing to open).
13. **Duplicate** a card with images: the copy has its own images; delete the copy: the original's images still open. **Delete** a concept: the rest renumber.
14. **Delete a USP a concept uses** (step 1): the dialog lists the concept chips and the approved concept's reset; confirm; the chips are gone. **Delete a persona with concepts** (step 2): the dialog lists the concepts, their images and links; confirm; all gone. Delete a persona with no concept: no confirmation, as before.
15. **Markets.** CZ shows "add personas first"; RO and SK are independent; removing a market with concepts is refused with the existing message.
16. **Second agency.** Sign in as agency B; open a concept URL of agency A: not found; the list shows only Glovo's concept.
17. **Narrow screen (390):** the card grid is one column, the stepper scrolls, the dialogs fit, the banner's buttons wrap.
18. **Cleanup:** `Pl23WalkCleanupSeeder` (deletes concept rows and image objects first, then PL-2.2's and PL-2.1's cleanups; the flag state is reported, not changed), then drop the throwaway database.

---

## 11. Rulings on the plan (Pedram, 2026-11-21)

| #       | Ruling                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Effect on the plan                                                                                                            |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | Card menu: Edit, Record client's decision (admin and manager only), Duplicate, Delete.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | As recommended; §4.1                                                                                                          |
| **Q2**  | The banner button is "Record client's decision" (admin and manager), opening the same dialog with a concept select.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | As recommended; §4.1                                                                                                          |
| **Q3**  | Approved cards carry a footer "Approved by {client name} · {date}".                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | As recommended; §4.2 item 3                                                                                                   |
| **Q4**  | **Changed from my recommendation.** A persona that concepts use **cannot be deleted**: 422 `persona.in_use_by_concepts` with the concept count, for every role. The user moves the concepts to another persona or deletes them first. **No cascade from persona to concepts.** Deleting a USP removes its chip from concepts and **keeps the concepts**; its confirmation lists the concepts affected. Deleting a concept: staff may delete one that is **Awaiting**; one that is **Approved or Changes requested** needs admin or manager. Removing a market that has concepts is refused the same way as for personas. | Supersedes §1.3 rule 5, §1.8 (persona), §3.3, §3.4 (persona bullet), the persona line of §6 "Delete cascades"; see Amendments |
| **Q5**  | At most **30** concepts per market.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | 422 `concept.market_full`; "{n} of 30" in the empty slot                                                                      |
| **Q6**  | Editing a Changes-requested concept leaves it red until the next decision. Editing an Approved concept sets it to Awaiting client.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | As recommended; §1.4                                                                                                          |
| **Q7**  | https links only.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | As recommended; §1.6                                                                                                          |
| **Q8**  | At most 10 links per concept.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | As recommended; §1.6                                                                                                          |
| **Q9**  | No per-image cap beyond the 300 MB total. Thumbnailing fails soft to the placeholder tile. **Added:** no decode above a byte and pixel threshold (below); such images get the placeholder and still download; a decompression-bomb image must not take the worker down, and a test proves it.                                                                                                                                                                                                                                                                                                                            | Thresholds in "Thumbnail thresholds" below; §1.5                                                                              |
| **Q10** | Client name, note and comment: `NotApplicable` in the erasure registry with the columns classified; a tech-debt row (48) and a line in `production-line-sprints.md`: the privacy documents need a sentence on client contact names and feedback recorded by the agency (for Jordan), and PL-4 must decide how they link to a real client user.                                                                                                                                                                                                                                                                           | Rows and line written in the docs commit; §3.7                                                                                |
| **Q11** | Chips are a snapshot. Changing the persona asks Replace or Keep.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | As recommended; §1.3                                                                                                          |
| **Q12** | Concept images: MIME and size from the stored object and a header byte check. Tech-debt row 49: bring brand files to the same standard.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | As recommended; §1.5                                                                                                          |
| **Q13** | No undo of a decision. A new decision replaces it; every decision is kept in history.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | As recommended; §1.4; the history is returned by `GET concepts/{c}/decisions` (Stage 1)                                       |

### Amendments to the plan body (these supersede the sections named)

1. **§1.3 rule 5 and §1.8 (persona).** A persona with at least one concept cannot be deleted. `PersonaService::delete` counts the persona's concepts under the wave lock first; if above zero it throws `persona.in_use_by_concepts` (422, meta `count` and the list `affected: [{concept, number, title, status}]`), for every role, with no confirmation path. A persona with no concept deletes exactly as in PL-2.1 and PL-2.2 (angles and cells go; no confirmation). `PATCH concepts/{c}` accepts a new persona of the same market (with `usp_mode`), which is how the user moves concepts. The delete-concept flow of §1.8 is unchanged (object-first) and is the other way out.
2. **§3.3 roles.** `delete` of a concept: any agency role when the concept is `awaiting`; `admin` or `manager` when it is `approved` or `changes_requested` (403 `forbidden`, decided under the concept lock so a decision recorded a moment ago is judged correctly). The policy ability is `deleteDecided`. Everything else as §3.3.
3. **§3.4 USP.** Unchanged in shape: `UspUsage::affected` gains items of kind `concept`; the concepts are **kept**, only the chip goes; an approved concept goes back to Awaiting. **§3.4 persona bullet is replaced** by amendment 1.
4. **Markets.** `WaveService` already refuses removing a market with personas (`wave.market_has_personas`). A market with concepts always has a persona (amendment 1), and the refusal is extended to count concepts too: the same error code with a `concepts` count per market in its meta, so the message names them.
5. **§1.1 / §3.1 the ceiling.** `create` and `duplicate` refuse a 31st concept in a market: 422 `concept.market_full`, meta `{market, max: 30}`; counted under the wave lock (two parallel creates cannot both pass).
6. **§1.4 history.** Decisions are append-only (§1.2). Stage 1 adds `GET concepts/{c}/decisions` (newest first: status, client name, note or comment, `source`, who recorded it, `recorded_at`), readable by every agency role. The card shows only the latest.
7. **§1.5 thumbnails.** See "Thumbnail thresholds".
8. **§9 tech-debt rows.** 46 (link-title fetcher), 47 (unreferenced objects), 48 (client contact names, Q10) and 49 (brand files to the concept-image standard, Q12) are written in the docs commit of 2026-11-21, not at build.
9. **§6 gate "Delete cascades"**, persona part: "a persona with concepts is refused for every role with the count; a persona without concepts deletes as before." New gates: concept delete by staff (Awaiting allowed, Approved and Changes requested 403), the 30 ceiling, the market-removal refusal with concepts, the decision history endpoint.

### Thumbnail thresholds (Q9)

A queued job makes the 512 px thumbnail. It **does not decode** an image when either limit is exceeded; the row keeps `thumb_key` null (the placeholder tile) and the image stays downloadable.

- **Pixels: 40 megapixels** (width x height read from the header with `getimagesizefromstring`, which reads the header only). A decoded GD bitmap is about 4 bytes a pixel, and the AH-004 plan measured 2 to 3 times that in decoder overhead: 40 MP is about 160 MB decoded, about 320 to 480 MB at the overhead. `PortfolioImageProcessor` allows 50 MP inside the same 768 MB worker (`production-queue-worker.md`: `--memory=768`, the job raises `memory_limit` to 768M at runtime). 40 leaves room for the file bytes held at the same time and for a second job's leftovers.
- **Bytes: 32 MB.** The processor needs the whole file as a string; 32 MB is read plus the decode above, about 25 MB below the pixel figure's slack. Larger files (up to the 300 MB total) are stored, counted and served, never decoded.
- The header read for the pixel check is at most 64 KB of the object. A header that cannot be read, a width or height of 0 or above 30,000, or a failed decode: placeholder, logged, job finished (not failed, not retried).
- The job sets `memory_limit` to 768M for its own run, `$tries = 1` (a decode that killed the worker once will do so again), `$timeout = 120`.
- **The test** (§6): a PNG whose header declares 30,000 x 30,000 (a few hundred bytes of file) goes through upload and the job; the job finishes, `thumb_key` stays null, the memory peak of the test process stays under a stated ceiling, and the image downloads. Break-revert: lift the pixel limit and watch the test fail.

### Gaps closed before any code (2026-11-21)

**G1. Thumbnails: library, presence, memory.** The library is `intervention/image` ^4 with the **GD driver** (`composer.json`; `PortfolioImageProcessor` is the precedent). **Present locally** (PHP 8.4.27, `gd` and `exif`, `imagewebp` available) and **in CI** (`.github/workflows/ci.yml` installs `gd` in all three PHP jobs). **Production:** the repository holds no Dockerfile or server package list, so the box's PHP modules cannot be read from here (**not read**). The evidence that GD runs there is indirect and strong: AH-004 (portfolio images, GD decode in `ProcessPortfolioImageJob`) and the avatar upload were deployed (deploy-log: the portfolio `processing_status` migration, AH-004), and the runbook documents the worker memory pair. So no new package and no new PHP module are needed; the deploy check is "the box already decodes portfolio images". Memory: see the thresholds above; the worker is `--memory=768`.

**G2. The sweeper and the scheduler.** The scheduler is registered in `apps/api/bootstrap/app.php` (`->withSchedule(...)`, five daily entries today). **Production runs it:** `docs/runbooks/production-queue-worker.md` §7.1, read back on 2026-10-12, says supervisor program `laravel-schedule` runs `php artisan schedule:work` (RUNNING, 41 days), so a new entry in `bootstrap/app.php` is picked up the next minute with no restart. That read-back is a month old; **the deploy re-reads it** (below). The sweeper is `strategy:sweep-pending-concept-images`, `everyFifteenMinutes()->withoutOverlapping()`.

**Deploy needs (for the runbook at the sprint-boundary merge).**

| Need               | Detail                                                                                                                                                                                                                                                                                                  |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --- | --------------------------------------- |
| Packages           | none new. `intervention/image` ^4 is already in `composer.lock`.                                                                                                                                                                                                                                        |
| PHP modules        | `gd` (with JPEG, PNG, WebP) and `exif`, already required by AH-004. Check: `php -r 'var_dump(function_exists("imagewebp"));'` on the box.                                                                                                                                                               |
| Migrations         | five new tables (`wave_concepts`, `wave_concept_usps`, `wave_concept_images`, `wave_concept_links`, `wave_concept_decisions`), schema only, `SET LOCAL lock_timeout = '5s'`; measured numbers in the review.                                                                                            |
| Feature flags      | `strategy_enabled` stays OFF; `waves_enabled` unchanged.                                                                                                                                                                                                                                                |
| Scheduled jobs     | `strategy:sweep-pending-concept-images` every 15 minutes. Needs `laravel-schedule` (`schedule:work`) RUNNING; re-read `supervisorctl status` before the merge.                                                                                                                                          |
| Queue              | the thumbnail job `GenerateConceptImageThumbnail` on the **default** queue (the `laravel-queue` program: `queue:work --memory=768 --tries=3 --max-time=3600`). Restart the worker after the deploy so it loads the new job class (runbook §4 "workers resolve job classes at boot"). No new queue name. |
| Storage paths      | `media` disk: `agencies/{agency_ulid}/brands/{brand_ulid}/waves/{wave_ulid}/concepts/{concept_ulid}/images/{image_ulid}.{jpg                                                                                                                                                                            | png | webp}`and`.../thumbs/{image_ulid}.jpg`. |
| Bucket permissions | the API's existing credentials need Get, Put, Delete and server-side **Copy** (same bucket, so the existing object permissions cover it); no List. CORS for the browser PUT already exists from PL-1.1. Not read from the bucket itself.                                                                |
| Env                | none new.                                                                                                                                                                                                                                                                                               |

**G3. How the SPA authenticates, and how an image is shown.** The agency SPA (`apps/main`) uses **Sanctum cookie auth**: the HTTP client sends `withCredentials: true` and an `X-XSRF-TOKEN` header on writes (`packages/api-client/src/http.ts`). **Bearer mode exists only in the native creator app.** The session cookie is `same_site` `lax` by default (`config/session.php`, `SESSION_SAME_SITE`), so an `<img src>` pointing at an API host on a different site would **not** carry the cookie in production (the deployed domains and `SESSION_SAME_SITE` value were **not read**). So the plan's rule stands: the web fetches the thumbnail and file endpoints with its own authenticated HTTP client and shows the result as a blob URL (works in cookie mode and bearer mode, revoked on unmount). The `HttpClient` interface has no response-type option today (the only blob download, `downloadPresignedBlob`, is for vendor URLs with no credentials), so **Stage 2 adds a `getBlob` method to the HTTP client**; Stage 1 (API) is not affected: the endpoints are ordinary authenticated GETs.

**G4. Migration locks.** Measured in the build through `php artisan migrate` on a throwaway database that is named in the command and printed with `select current_database()` first; the numbers are in the Stage 1 report and `pl-2-3-break-reverts.md`'s companion section. Not measured at plan time.

### The questions as asked (kept for the record; the rulings above supersede the recommendations)

**Q1. Where is "Edit", and what is in the card menu?** C2 lists the menu as duplicate and delete. The frame draws only the dots icon, and no edit surface at all (the card shows read-only text). Something has to open the edit dialog, and recording a decision (C5) needs an entry on the card. _Recommendation:_ the menu holds **Edit**, **Record client's decision** (admin and manager only), **Duplicate**, **Delete**; the references are edited directly on the card (the frame's buttons). Without this the dialog has no way in.

**Q2. What does the banner's "Approve on client's behalf" do?** The frame shows one button with that label and no choice of outcome, and it is the only decision control the frame draws. C5 needs Approved and Changes requested, per concept. _Recommendation:_ keep the button (admin and manager only), relabel it "Record client's decision", and open the same dialog with a concept select first; the card menu item opens it already on that concept. The label differs from the frame (reported as a difference). Alternative: remove the banner button and keep only the menu item.

**Q3. Show who approved, on the card?** The frame draws no approver on an Approved card (its pill only), but C5 requires "who recorded it and when" be kept, and the client's name is otherwise invisible. _Recommendation:_ a one-line footer on Approved cards, same geometry as the red one, green: "Approved by {client name} · {date}" (a visual addition, §4.2 item 3). Alternative: show it only in the decision dialog.

**Q4. Who may delete a persona (or USP) that concepts use?** Today any agency role deletes a persona (the dialog lists cells; the server asks for nothing), and `deleteInUse` (admin and manager) governs a USP with angles or cells. A persona delete would now remove concepts, possibly with client decisions, images and links. _Recommendation:_ a persona with concepts needs the counted confirmation (§3.4) and, for consistency with USPs, **admin or manager** when any of its concepts is `approved` or `changes_requested` (a client decision exists); staff may still delete a persona whose concepts are all Awaiting, with the confirmation. Alternative: admin and manager always.

**Q5. A ceiling on concepts per market.** The frame says "no maximum" and C2 does not give one. One list request returns every concept with its chips, images and links, so the payload and the page have no bound. _Recommendation:_ a technical ceiling of **30 concepts per market** (30 × up to 10 images and 10 links is bounded), stated in the "Add concept" slot ("{n} of 30 concepts") instead of "no maximum", reported as a difference. If you want no ceiling, the list needs pagination, which the frame does not draw.

**Q6. Editing a "Changes requested" concept.** C5 sends only Approved back to Awaiting. After the agency reworks the concept in answer to the client's comment, it stays red until someone records the next decision. _Recommendation:_ leave it red (the comment stays visible while the agency works, and recording the new decision is the only way to change the status); PL-4's resend is where it should return to Awaiting. Alternative: any edit moves it to Awaiting and hides the red footer, losing the comment from view.

**Q7. Accept `http` links?** C4 says https, "http if recommended". _Recommendation:_ **https only**: no common reference site is http-only now, and a mixed link costs the person one retype. If you want http, the rule is one constant and the walk adds `http://example.com` as accepted.

**Q8. Number of links per concept.** C4 caps images at 10 but gives no number for links. _Recommendation:_ **10**, the same as images, so a card cannot grow without bound.

**Q9. No per-image cap and thumbnails.** C4 allows one image to use the whole 300 MB. A PHP worker decodes the whole file into memory to make the thumbnail, so a very large file (even under the 50 MP guard) can exhaust the worker. _Recommendation:_ keep the ruling as written; thumbnail generation is best-effort in a queued job with its own memory and a time limit, and a failure leaves the placeholder tile. The images are still stored, counted and downloadable. If the walk or the first real use shows trouble, add a per-image cap (for example 50 MB) as a one-constant change.

**Q10. Erasure classification of the client's name and comment.** They are a real third party's name typed by agency staff, which the "invented persona name" precedent does not cover. _Recommendation:_ `NotApplicable` for the table (no creator or user is the subject), `client_name`, `note` and `comment` **classified** as agency-authored text about a client contact that leaves with the concept, and one sentence added to the privacy and sub-processor documents. If you want a client-contact erasure path, that is its own piece of work; nothing here blocks it.

**Q11. Chips follow the matrix?** C3 says "then editable" and asks what happens when the matrix changes. _Recommendation:_ a snapshot (§1.3): chips never change by themselves, so an approved concept cannot move under the client; changing the persona asks Replace or Keep. Alternative: chips marked "from matrix" follow later matrix edits, which needs a flag per chip and reopens approved concepts, and I advise against it.

**Q12. Hardening beyond brand files.** C4 says MIME and size come from the stored object. Brand files take the MIME from the extension the server chose from the client's declared MIME, and look at no bytes. _Recommendation:_ the concept flow reads the object's own MIME and size and checks the first bytes decode as an image (§1.5). This differs from brand files (a small, visible inconsistency) and costs one ranged read per upload; I recommend it because the image is later served from our own domain. Say if you want identical behaviour to brand files instead.

**Q13. Undo of a decision.** The client's page draws "Undo". C5 does not ask for it on the agency side. _Recommendation:_ not built; recording a new decision is the correction, and the only way back to Awaiting is an edit that counts. Add it only if you walk and miss it.

---

## 12. Size estimate and build order

**Size:** large for this sprint, the biggest PL-2 chunk. Backend about 2,200 lines (five migrations, five models and factories, a concept service, an image service with reservation, sweeper, thumbnail job, the decision service, policy, controllers, resources, the two delete extensions, 11 audit actions, registry and key decisions, summary block) and about 2,600 lines of tests; web about 2,800 lines (nine components, the logic file, the upload composable, the stepper change, API types) and about 1,500 lines of specs; 170 strings × 24 locales; two seeders.

**Build order (each step green on SQLite and PostgreSQL before the next):**

1. Migrations, models, factories, schema tests, registry, the rehearsal on the throwaway database (§8), audit enum and key decisions.
2. Concepts CRUD, chips, limits, roles, flag gates; summary block.
3. The edit-reset rule and the delete and duplicate flows without images.
4. Links.
5. Images: reservation, complete, reads, thumbnail job, sweeper, delete and duplicate with objects.
6. Decision.
7. The two delete extensions (USP list, persona confirmation).
8. Web: stepper, route, step page and cards, dialogs, references, decision, delete dialogs.
9. Locales, Vitest, Playwright, the §4.2 real-browser walk and the fidelity table, flag-off walk comparison.
10. Seeders, the walk by Pedram, then the review and push of `pl` only.

---

## 13. Corrections and rulings log

- 2026-11-21: Q1 to Q13 ruled and G1 to G4 closed (section 11). Q4 changed from the recommendation.
