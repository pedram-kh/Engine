<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * The kind of a message (Sprint 11, D-15). Stored as varchar(16) on
 * `messages.kind`. Per docs/03-DATA-MODEL.md §11.
 *
 *   - `text`            — a human message with a body (attachments optional).
 *   - `system`          — a lifecycle event written by WriteSystemMessage
 *                         (D-4); `sender_user_id = null`, body null,
 *                         `system_event_key` set.
 *   - `attachment_only` — a human message with files and NO body (D-6); a real
 *                         path, not a degenerate text message.
 *
 * `attachment_only` is 15 chars — the longest value, fitting varchar(16)
 * exactly (pinned in MessageEnumsTest).
 *
 * Catalogue-tripwire pinned in MessageEnumsTest.
 */
enum MessageKind: string
{
    case Text = 'text';
    case System = 'system';
    case AttachmentOnly = 'attachment_only';
}
