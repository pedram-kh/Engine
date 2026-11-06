# AH-119 Messages v2 — Stage A review (Checkpoint A)

Status: Stage A is **pushed** (tip `4be4cb85`, CI run 42181503407 green: Pest SQLite 2m48s, Pest on Postgres 6m12s,
Playwright main and admin). Stage B is built locally and **not pushed** (see the Stage B section below).

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

---

# Stage B (edit, delete, search) — Checkpoint B

Local commits on top of the pushed Stage A: `28a5ad29` (API), `deb860a1` (web), then this docs commit. Not pushed.

## What shipped

- API: two schema-only migrations (columns on both message tables, pg-guarded GIN indexes), `MessageRevisionService`,
  eight edit and delete routes and `GET /agencies/{agency}/message-search`, all behind `messages_v2_edit_search_enabled`
  (404 when off; on `/me` as `features.messages_v2_edit_search`). 15-minute window by server clock, sender only, edit
  needs an open thread, delete works in a closed one, row lock. Audit `message.edited` and `message.deleted` (ids only),
  no notification, push or mail. Erasure: `original_body` and `deleted_body` scrubbed for subject-sent rows, bodies and
  other parties untouched, attachments of deleted messages still purged. Wire contract:
  `messages-v2-stage-b-contract.md`. Guarded test helper `POST _test/messages/{ulid}/age`.
- Web: `MessageActions`, `MessageEditField`, `MessageGate` in `ChatPanel`, `RelationshipThreadView` and the agency fork;
  optimistic edit and delete with rollback; "edited" mark; "Message deleted" placeholder and inbox preview; agency
  inbox search (2 characters, 300 ms, "Messages" group); brand colour on the campaigns table avatar; 67 keys in 24
  locales; iOS `CURRENT_PROJECT_VERSION` 3 to 4 (no plugin added).
- Flag off: DOM byte-identical to before (pinned in `stageBFlagOffPins.json`); creator walk unchanged.

## Gates (on tip `deb860a1`)

Pest SQLite 4595 passed / 36 skipped (pg-only specs); Pest Postgres 4631 passed, 247 s; Pint, PHPStan (no errors),
vue-tsc, Prettier clean; ESLint 0 errors (2 old `v-html` warnings); Vitest main 4691 passed, 2 failed (the
`appIconAssets.spec.ts` environmental pair); Vitest admin 690 passed; Playwright main 54 passed, admin 2 passed;
creator walk `COMPARE OK: 142 targets identical to baseline.json`; dev DB counts unchanged (users 3, audit_logs 3,
notifications 0, messages 0).

## Findings during the E2E run

- `message-edit-delete` failed first because the delete is optimistic: the test reloaded before the CSRF preflight
  and the DELETE left the browser. Product behaviour is right; the spec now waits for the DELETE response.
- `message-search` expected a creator display name; a creator with no finished profile reads "Participant", the same as
  the inbox row. The spec now says so.

## Break-reverts

API (19 mutations: window bound, sender check, closed-thread rules, thread-scoped lookup at both layers, row lock on
Postgres, `withTrashed` on unread, resource mask, original captured once, flag checks, search tenancy per table,
deleted exclusion, min length, snippet cap) and web (17 mutations: min chars, debounce, stale response, verb, window,
code mapping, closed-thread Edit, rollback, poll merge, deleted body, flag-off DOM, preview, brand colour, locale key).
Each was reverted byte-identical; the tables are in the agents' reports and the spec names in the plan §8.

## Deviations and open items

- Edit or delete of a system line is 404 (no sender), not `not_editable`.
- Search terms under 2 characters are ignored (`q=a b` returns an empty list). On Postgres matching is by word prefix;
  on SQLite by substring.
- No true two-writer concurrent race spec; covered by the lock probe, a holder timeout, and a committed-delete-then-edit
  case.
- Search hits carry no per-viewer `is_own`; the web shows no "You:" on agency-sent hits unless the API adds it.
- Search is wired in the unified inbox only, so it also needs `messages_v2_inbox_enabled`.
- The in-app "What stays" deletion text does not yet mention kept originals and deleted text (touches every locale);
  flagged in `docs/store/deletion-texts-en-es.md` for a ruling. Privacy text is a notice, not legally reviewed.
- Migrations not run against a production-sized table; the GIN index build is the only slow step.
- Simulator bundle build not run (`pnpm build` is OK; `CURRENT_PROJECT_VERSION` is 4 in both build configurations).
- Stage B stays OFF until build 4 is live (Q9). Device walk: `messages-v2-device-walk.md` (W1 to W9, including the
  native external-link check and the WKWebView paste/drop check).

Screenshots: `docs/reviews/messages-v2-assets/checkpoint-b/` (edit menu, edit field, edited mark, past-window menu,
delete confirm, deleted placeholder, search min-chars, results, empty; dark and light).
