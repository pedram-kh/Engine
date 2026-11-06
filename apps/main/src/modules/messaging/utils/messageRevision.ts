/**
 * AH-119 Stage B — the client's half of the edit rules, and the code-to-key table for the
 * errors the edit, delete and reassign endpoints answer.
 *
 * The 15-minute window is a HINT for what to offer: the server's clock decides (plan §5 B2).
 */

import { ApiError } from '@catalyst/api-client'

/** A message can be edited for this long after it was sent (mirrors the server bound). */
export const EDIT_WINDOW_MS = 15 * 60 * 1000

/** True while the client clock says the edit window of a message sent at `createdAt` is open. */
export function isEditWindowOpen(createdAt: string, now: number = Date.now()): boolean {
  const sent = new Date(createdAt).getTime()
  if (Number.isNaN(sent)) {
    return false
  }
  return now - sent <= EDIT_WINDOW_MS
}

/** Backend error code → i18n key (`app.messaging.errors.*`). Each code is pinned by a spec. */
export const MESSAGE_ERROR_KEYS: Readonly<Record<string, string>> = {
  'message.edit_window_closed': 'app.messaging.errors.editWindowClosed',
  'message.edit_thread_closed': 'app.messaging.errors.editThreadClosed',
  'message.not_editable': 'app.messaging.errors.notEditable',
  'message.already_deleted': 'app.messaging.errors.alreadyDeleted',
  'owner.not_a_member': 'app.messaging.errors.ownerNotMember',
}

/**
 * The i18n key to show for a failed call: the mapped key for a known backend code, else
 * `fallbackKey` (`editFailed` / `deleteFailed` / `reassignError`).
 */
export function messageErrorKey(err: unknown, fallbackKey: string): string {
  if (err instanceof ApiError) {
    return MESSAGE_ERROR_KEYS[err.code] ?? fallbackKey
  }
  return fallbackKey
}

/**
 * The preview text of an older thread list row (rollup, relationship inbox): the "Message
 * deleted" placeholder when the newest message was deleted and Stage B is on, else the preview
 * as sent. Off, the row is exactly what it was.
 */
export function previewOrDeleted(
  preview: string | null,
  lastMessageIsDeleted: boolean | undefined,
  stageBOn: boolean,
  placeholder: string,
): string | null {
  return stageBOn && lastMessageIsDeleted === true ? placeholder : preview
}
