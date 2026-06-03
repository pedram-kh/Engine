/**
 * Wire-contract types for the creator availability calendar surface
 * (Sprint 5 — Chunk A backend, consumed by the Chunk B calendar UI).
 *
 * These mirror the backend `AvailabilityOccurrenceResource` verbatim
 * (snake_case keys, ISO 8601 + offset timestamps, ULID identifiers) —
 * the same FE↔BE no-re-casing discipline as `creator.ts`.
 *
 * ⚠ The `id` is the SOURCE BLOCK's ULID. Every expanded occurrence of a
 * recurring block shares it, so calendar items must be keyed on
 * `id + starts_at`, never `id` alone (Chunk B D-b5). See
 * `AvailabilityOccurrenceResource` on the backend.
 *
 * The list endpoint clamps an over-wide window SILENTLY (366-day ceiling)
 * and reports the real bound in `meta.window` — render from that, not the
 * requested range (D-b6).
 */

/**
 * Mirrors `App\Modules\Creators\Enums\BlockType`. A `hard` block excludes
 * the creator entirely (drives conflict-detection); a `soft` block is a
 * warning-only preference.
 */
export type AvailabilityBlockType = 'hard' | 'soft'

/**
 * Mirrors `App\Modules\Creators\Enums\Kind` — the full set, including the
 * system-reserved `assignment_auto` which can appear on a READ but is
 * never creator-settable (see {@link CreatorSettableKind}).
 */
export type AvailabilityKind =
  | 'vacation'
  | 'personal'
  | 'exclusive_contract'
  | 'assignment_auto'
  | 'other'

/**
 * The kinds a creator may submit via manual CRUD — mirrors
 * `Kind::creatorSettable()` (excludes `assignment_auto`, reserved for the
 * deferred Sprint 8 auto-block flow, D-a2/D-b9). The create/edit dialog
 * offers ONLY these.
 */
export type CreatorSettableKind = Exclude<AvailabilityKind, 'assignment_auto'>

/**
 * The concrete instant + metadata for one expanded occurrence. For a
 * recurring block these are the per-week instance start/end; for a one-off
 * they are the block's own window. `recurrence_rule`/`is_recurring` ride
 * along so the editor knows which block to edit and with what rule.
 */
export interface AvailabilityOccurrenceAttributes {
  /** ISO 8601 with UTC offset. */
  starts_at: string
  /** ISO 8601 with UTC offset. */
  ends_at: string
  is_all_day: boolean
  block_type: AvailabilityBlockType
  kind: AvailabilityKind
  /** Creator-only note; present on the creator's own view. */
  reason: string | null
  is_recurring: boolean
  /** RRULE body (weekly ceiling), or null for a one-off. */
  recurrence_rule: string | null
}

export interface AvailabilityOccurrenceResource {
  /** SOURCE BLOCK ULID — shared across every occurrence of a recurring block. */
  id: string
  type: 'availability_blocks'
  attributes: AvailabilityOccurrenceAttributes
}

/**
 * The actual window the backend expanded, after the silent 366-day clamp.
 * Render from THIS, not the requested `to` (D-b6).
 */
export interface AvailabilityWindowMeta {
  window: {
    from: string
    to: string
  }
}

/** `GET /creators/me/availability?from=&to=` */
export interface AvailabilityListResponse {
  data: AvailabilityOccurrenceResource[]
  meta: AvailabilityWindowMeta
}

/** `POST`/`PATCH` return the stored block as its own canonical occurrence. */
export interface SingleAvailabilityResponse {
  data: AvailabilityOccurrenceResource
}

/**
 * Create payload. `kind` is narrowed to the creator-settable set so a
 * client can never even construct an `assignment_auto` request (D-b9).
 * Timestamps are ISO 8601 UTC instants (`DateTime.toUTC().toISO()`).
 */
export interface CreateAvailabilityBlockPayload {
  starts_at: string
  ends_at: string
  is_all_day: boolean
  block_type: AvailabilityBlockType
  kind: CreatorSettableKind
  reason?: string | null
  is_recurring: boolean
  /** Required iff `is_recurring`; weekly-only RRULE body. */
  recurrence_rule?: string | null
}

/**
 * Update is a FULL-RESOURCE REPLACE on the backend (every column is
 * rewritten from the payload), so the shape is identical to create.
 */
export type UpdateAvailabilityBlockPayload = CreateAvailabilityBlockPayload
