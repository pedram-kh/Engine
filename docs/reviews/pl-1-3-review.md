# PL-1.3 review: Wave project tracker

**Branch:** `pl`. **Plan:** `pl-1-3-plan.md` (corrections C1 to C3 in §13). **Status:** Stage 1 (backend) built; Stage 2 (web, strings, seeders, close-out) not started. This file is completed in Stage 2.

## Stage 1: migration rehearsal (local, synthetic rows only)

Throwaway PostgreSQL database `pl13_rehearsal` in the local container (dropped afterwards), migrated to the tip before PL-1.3, then **3,000 synthetic waves** (10 synthetic agencies, 300 waves each, inserted directly; nothing from production), then the two PL-1.3 migrations:

| Item                                       | Result                                                                                                   |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| Rows in `waves` before and after           | 3,000 and 3,000 (no row written)                                                                         |
| Rows with a marker after M2                | 0 (every existing wave keeps a null `steps_provisioned_at`)                                              |
| Rows in `wave_steps` after both migrations | 0 (no backfill)                                                                                          |
| Time `artisan migrate`                     | M1 `create_wave_steps_table` 18.42 ms; M2 `add_steps_provisioned_at_to_waves` 1.88 ms                    |
| `down()`                                   | run on the empty tables (`migrate:rollback --step=2`: 4.74 ms and 3.55 ms); also in `WaveStepSchemaTest` |

**Locks taken** (read from `pg_locks` inside a transaction with the same statements, then rolled back):

- **M2 (`ALTER TABLE waves ADD COLUMN ... NULL`):** `AccessExclusiveLock` on `waves`, held for the catalog update only (0.14 ms here). No table rewrite. `SET LOCAL lock_timeout = '5s'` bounds the wait.
- **M1 (`CREATE TABLE` with three foreign keys):** `AccessExclusiveLock` on the new table only, plus **`ShareRowExclusiveLock` on each referenced table: `waves`, `agencies` and `users`** (taken by adding a foreign key). That lock blocks writes to those three tables for the duration of the migration transaction (3.2 ms here) and does not block reads. **This is a finding against the plan's wording "creates a new, empty table; no existing table is altered":** it is true that no row and no column of an existing table changes, but the foreign keys do take that brief lock on `agencies` and `users`, which are live tables in production. `waves` is not in production yet (PL-1.1 is not deployed). The PL-1.1 `create_waves_table` migration did the same. `lock_timeout` makes the migration give up after 5 s rather than queue behind a long transaction. **PROD-DATA RISK for existing data stays NONE** (nothing is read, written or backfilled); the lock is stated so nobody is surprised.

## Stage 1: answer to C1 (the audit key for a user)

See `pl-1-3-plan.md` §13. The precedent fits, so nothing stopped: AH-119 A3 writes `from_user` and `to_user` (user ids, never a name); `AuditPayloadKeys` decides them in `notPersonalKeys()` under the id groups ("points at a row that is anonymised in place"); the users row is anonymised in place on erasure and `AuditRowScrub` keeps ids and ulids. The tracker uses the same two keys, carrying the **integer user id** as AH-119 does. The plan's `owner_id` (a ulid) was **not** added. Seven new keys got a decision in the same commit: `stream`, `step_key`, `owner_client`, `status_source`, `starts_on`, `due_on`, `completed_on`.

## Stage 1: what differs from the plan, and why

1. **Audit key** `from_user` / `to_user` instead of `owner_id` (C1, above).
2. **Step PATCH lock order.** A plain edit locks the step row only. A PATCH that moves a step to another stream takes the **wave row lock first** and then the step lock (the order every stream operation uses), so a move and a remove-stream cannot deadlock. The plan said "the step row" only.
3. **Archived brand.** Step and stream writes are refused on an archived brand with the wave rule `wave.brand_archived` (the plan did not say; it is the same read-only rule as editing a wave). The tracker GET still provisions on an archived brand, because it writes defaults, not a user edit.
4. **Add step, Add stream, Remove stream on a wave that was never opened** call the provisioner first (Add stream and Add step) so a custom step cannot pre-empt the marker. Remove stream on a wave with no steps is a 404.
5. **Responses.** Add and update return the one step; remove a step is 204; remove stream and add stream return the **full tracker** payload (so the page redraws from one response). The tracker payload has `absent_streams` (the streams with no step, for the Add stream control) and no "No steps" stream entry: a stream with no steps is simply absent (the §1.2 rule).
6. **Progress object on every wave response** (list, show, create, update), not only list and show; the list computes it with one grouped query, the others with one query.
7. **Audit payload shape.** Update and remove write literal `before` and `after` arrays of nine keys, and `metadata` with `from_user` and `to_user`; add writes `to_user`; stream remove and add write `stream` and `row_count`; provisioning writes `row_count`.
8. **Completed date.** `completed_on` is the actor's today (their time zone) when a step becomes done and is cleared when it leaves done, as planned; tested with a user in `Pacific/Auckland`.
9. **Route-table gates.** `WaveApiTest` B1 now expects 11 wave routes (5 + 6), and `WaveTrackerAccessTest` pins the six tracker routes by name.

## Stage 1: not read, not run

Not read: the developer brief; frame 01b parts listed in the plan. Not run: the full Pest suite, the full Vitest suite, Playwright, the walk, CI (until the push). No real two-client race on PostgreSQL was run; the lock tests assert the `for update` SQL, the unique indexes refuse duplicates, and sequential requests are tested.
