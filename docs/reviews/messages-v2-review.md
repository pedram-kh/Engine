# AH-119 Messages v2 — Stage A review (Checkpoint A)

Status: built locally, **not pushed** (A0 and F1 are pushed, CI green, Postgres leg 4m57s). Stage B (edit, delete,
search) is not started; it waits for Pedram's ruling.

## What shipped (Stage A)

- API: `GET /agencies/{agency}/message-inbox` (union of relationship "Direct" and assignment threads, cursor on
  `(last_at, type, id)`, facets, owner filter), behind Pennant `messages_v2_inbox_enabled` (404 when off), exposed to
  the SPA as `/me` `features.messages_v2_inbox`. Owner = `coalesce(owner_user_id, invited_by_user_id)` and crosses the
  wire as a user ULID, never the internal id. Brand `color` (palette P3) on brand and embedded campaign payloads.
- Agency web: `AgencyUnifiedInbox` (Direct + assignment rows, brand/campaign popovers with counts, Mine toggle chip,
  Unread), filters in the route query and carried on thread links, assignment thread rendered in
  `AgencyRelationshipThreadView` through a transport adapter (Q10), thread actions menu (reassign: admin and manager
  only, Q6). Flag off = the old inbox and old poll, unchanged.
- Shared: `linkify`, `MessageText`, inline images, paste/drop/paperclip attach. URL- and image-free messages render
  identical DOM (native polarity).
- i18n: 42 keys in all 24 locales, with a key-parity spec.

## Commits (local, on top of pushed A0)

`ce30376d`, `a8dab767`, `045b0771` (API) then `47172863` (types + locales), `32182314` (shared A6),
`9d14b901` (agency web) and a final spec-stability commit.

## Gates

SQLite Pest 4367 passed / 6 skipped; Postgres Pest 4373 passed (211 s); Pint, PHPStan, vue-tsc, ESLint (0 errors,
2 pre-existing warnings), Prettier clean; Vitest 4528 passed, 2 failed (`appIconAssets.spec.ts`, pre-existing and
environmental, in tech-debt). Creator walk: `COMPARE OK: 142 targets identical to baseline.json`.
Frozen-component hashes re-pinned with reasons for `ChatPanel` and `RelationshipThreadView`.

## Break-reverts

API (offset/cursor, owner coalesce, facet counts, flag 404), A6 (linkify scheme guard, `noLinks`, image cap) and the
spec agents' mutants were each reverted to confirm a spec fails; one equivalent mutant is noted in the plan.

## Deviations and honest limits

- Mine is a toggle chip beside the popovers (ruled), not the Figma placement.
- Unified inbox subtitle omitted. Derived brand colour (from the ULID, nothing written) only on inbox surfaces.
- Flat attachment i18n keys; creator-accept owner default; extra `reassignCancel` key; no client 10-file cap;
  oversize uses `attachmentUploadError`; `noLinks` prop (Vue Boolean absent means false, handled).
- Playwright E2E `agency-messages-unified.spec.ts` was **not written**. Screenshots come from the mock-API walk
  harness, not a live backend.
- Native external-link opening and WKWebView image paste are **not device-verified**.
- Routes sit on the standard tenancy stack, so no `tenancy.md` allowlist row.
- Production behaviour and query cost at production size are unproven.

Screenshots: `docs/reviews/messages-v2-assets/checkpoint-a/` (dark and light).
