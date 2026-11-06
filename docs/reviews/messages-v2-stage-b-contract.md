# AH-119 Stage B: API wire contract (edit, delete, search)

> For the web agent. Source of truth is the API code in `apps/api/app/Modules/Messaging`; this file is written
> first and kept in step. Flag: `messages_v2_edit_search_enabled` (OFF by default). While OFF **every route
> below answers 404** `not_found` (the standard error envelope). Base path `/api/v1`. Errors use the standard
> envelope `{"errors":[{"status","code","title"}],"meta":{"request_id"}}`; branch on `errors[0].code`.

## 1. Flag on `/me`

`data.attributes.features.messages_v2_edit_search` (bool). `true` for agency users **and creators** (the edit
and delete routes are used from both sides); `false` for platform admins. UI gate only: the API enforces the flag.

## 2. Resource fields (both message resources, always present, additive)

`MessageResource` (`type: message`) and `RelationshipMessageResource` (`type: relationship_message`) gain, inside
`attributes`:

| Field        | Type          | Meaning                                                                            |
| ------------ | ------------- | ---------------------------------------------------------------------------------- |
| `edited_at`  | ISO 8601/null | Set on every edit; `null` if never edited. Never set on a deleted-then-masked row. |
| `is_deleted` | bool          | `true` once the sender deleted it.                                                 |

**A deleted message is masked:** `body: null`, `attachments: []`, `is_deleted: true`, `edited_at: null`, no
signed URL is minted. `kind`, `sender_role`, `is_own`, `sender`, `created_at` stay (so the bubble keeps its place
and side). `original_body` and `deleted_body` are never on any resource, response, audit row or admin surface.
The thread feed (`GET …/messages`) now **includes** deleted messages (masked), in place, so a poll shows the
placeholder; unread counts, search and the digest never see them.

## 3. The eight routes

All send/accept JSON. The `{message}` is the message **ulid** (the resource `id`), resolved inside the thread
in the URL: a ulid from another thread, another agency, another creator or a system line is **404**.

| Surface           | Method       | Path                                                                                  |
| ----------------- | ------------ | ------------------------------------------------------------------------------------- |
| agency, campaign  | PATCH/DELETE | `/agencies/{agency}/campaigns/{campaign}/assignments/{assignment}/messages/{message}` |
| agency, direct    | PATCH/DELETE | `/agencies/{agency}/creators/{creator}/relationship-messages/{message}`               |
| creator, campaign | PATCH/DELETE | `/creators/me/assignments/{assignment}/messages/{message}`                            |
| creator, direct   | PATCH/DELETE | `/creators/me/relationship-threads/{agency}/messages/{message}`                       |

**PATCH** body: `{"body": "string"}` (1 to 5000 characters, trimmed). Success **200** `{"data": <message resource>}`
(the updated resource, `type` `message` or `relationship_message`). Sending a body identical to the current one
is a 200 no-op (`edited_at` unchanged). Validation failure (empty, over 5000) is 422 with the standard validation
envelope (`errors[].source.pointer = "/body"`).

**DELETE**: no body. Success **200** `{"data": <masked message resource>}`.

### Rules and error codes (all **422** unless stated)

| Code                         | When                                                                                                                                   |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `message.edit_window_closed` | Edit more than 15 minutes after `created_at` (server clock; an edit never extends it).                                                 |
| `message.edit_thread_closed` | Edit while the thread is closed (assignment declined/rejected/cancelled; relationship ended, blacklisted, creator no longer approved). |
| `message.not_editable`       | Edit of an attachment-only message (no text body), or any message with no body. (A system line has no sender, so it is a 404, below.)  |
| `message.already_deleted`    | Edit or delete of a message that is already deleted (no undo).                                                                         |
| 404 `not_found`              | Not your message, not in this thread/agency, unknown ulid, system line on EDIT and on DELETE (it has no sender). Never 403.            |

Delete is **allowed in a closed thread** and after the window. Only the sender can edit or delete. Order of
checks on PATCH: 404 (ownership/lookup) then `already_deleted`, `not_editable`, `edit_window_closed`,
`edit_thread_closed`. No notification, push or mail is emitted by an edit or a delete. `last_message_at` does not
move. A delete leaves attachments stored (hidden) until the account is erased.

## 4. Inbox and thread-list previews for a deleted last message

`GET agencies/{agency}/message-inbox` row `attributes`: if the thread's newest **human** message is deleted,
`preview_kind` is **`"deleted"`** and `preview` is `null` (the three kinds are now `text | attachment | deleted`).
`last_message_at` is unchanged (not moved by a delete). A thread whose human messages are **all** deleted does not
surface in the unified inbox (Q2). The older list endpoints (`…/message-threads` rollup, `…/relationship-threads`,
`creators/me/relationship-threads`) gain an additive `last_message_is_deleted` bool beside `last_message_preview`
(`null` when deleted); a thread with only deleted messages stays listed there.

## 5. Search

`GET /agencies/{agency}/message-search?q=&cursor=` — standard agency stack, any active member (staff included),
flag-gated (404 off). `q`: trimmed, **2 to 200 characters** (422 validation error below 2); split into at most 8
terms of at most 40 characters (letters/digits only, other characters, `_` and `%` included, separate terms; a term under 2 characters is ignored, so `q=a b` is a valid request that returns `data: []`); every term matches as a
**prefix**, all terms must match (AND). Postgres: full-text (`simple`, no stemming); other drivers: substring.
Matches the **current** text of an edited message, never `original_body`; deleted messages and system lines never
match. 30 results per page, newest first within equal rank; `cursor` is the opaque `meta.next_cursor` of the
previous page (a malformed cursor reads as the first page; at most 330 results are reachable).

```json
{
  "data": [
    {
      "id": "<thread ulid>",
      "type": "relationship_thread",
      "attributes": {
        "message_ulid": "<message ulid>",
        "snippet": "…up to 160 chars of plain text around the first match, ellipsis at cut ends…",
        "sent_at": "2026-11-06T09:30:00+00:00",
        "sender_role": "creator",
        "sender_is_erased": false,
        "creator": {
          "id": "<creator ulid>",
          "display_name": "Ana",
          "is_erased": false,
          "avatar_url": null
        },
        "brand": null,
        "campaign": null,
        "assignment_id": null,
        "last_message_at": "2026-11-06T09:30:00+00:00",
        "preview": "…",
        "preview_kind": "text",
        "unread_count": 0,
        "owner": { "id": "<user ulid>", "name": "Sam" }
      }
    }
  ],
  "meta": { "next_cursor": "opaque|null", "per_page": 30 }
}
```

- `id` + `type` + every attribute other than the five search fields are **exactly the inbox row** (the web can
  reuse the row component). `type` is the thread discriminator: `relationship_thread` | `assignment_thread`.
- Thread link, built as for inbox rows: `relationship_thread` → the creator thread by `attributes.creator.id`;
  `assignment_thread` → `attributes.campaign.id` + `attributes.assignment_id` (`brand`/`campaign` are objects
  `{id,name,color?}` / `{id,name}` as in the inbox). `message_ulid` is the unique list key (several hits can share
  a thread `id`).
- `snippet` is plain text (no markup, no HTML); render as text. `sent_at` is the message `created_at`.
- `sender_is_erased` is `true` when the matching message was sent by a creator who has since been erased; label
  them with the same anonymised creator label the inbox uses (the message text stays searchable, Q5).
- Empty result: `{"data": [], "meta": {"next_cursor": null, "per_page": 30}}`.

## 6. Guarded test helper (local/testing only, `X-Test-Helpers-Token` like the rest of `_test/*`)

`POST /api/v1/_test/messages/{ulid}/age` body `{"minutes": 16}` (integer 1 to 10080) — moves that message's
`created_at` back by `minutes` (either message table; also works on a deleted row). 200
`{"data":{"id":"<ulid>","table":"messages|relationship_messages","created_at":"<ISO>"}}`; 404 unknown ulid;
422 bad `minutes`. Use it to push a message past the 15-minute window in Playwright.
Flag helper: `POST/DELETE /api/v1/_test/feature-flag/messages_v2_edit_search_enabled` (allow-listed).
