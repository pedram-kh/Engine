/**
 * AH-119 Stage B — the edit window hint, the backend-code → i18n-key table, and the deleted
 * preview helper.
 *
 * Every backend code the Stage B routes answer is pinned to its key, and each key resolves to a
 * string in ALL 24 locales (the table is the only place a code becomes a message).
 *
 * Break-revert: move the window bound to 14 minutes → the 15:00 case fails; drop a code from the
 * table → its pin fails; ignore the Stage B switch in `previewOrDeleted` → the flag-off case fails.
 */

import { ApiError, UI_LOCALES } from '@catalyst/api-client'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

import {
  EDIT_WINDOW_MS,
  MESSAGE_ERROR_KEYS,
  isEditWindowOpen,
  messageErrorKey,
  previewOrDeleted,
} from './messageRevision'

const SENT = '2026-11-06T09:00:00+00:00'
const SENT_MS = new Date(SENT).getTime()

function apiError(code: string): ApiError {
  return new ApiError({ status: 422, code, message: 'server text' })
}

describe('isEditWindowOpen', () => {
  it('is 15 minutes', () => {
    expect(EDIT_WINDOW_MS).toBe(900_000)
  })

  it('is open at 14:59 and at exactly 15:00, closed at 15:01', () => {
    expect(isEditWindowOpen(SENT, SENT_MS + 14 * 60_000 + 59_000)).toBe(true)
    expect(isEditWindowOpen(SENT, SENT_MS + 15 * 60_000)).toBe(true)
    expect(isEditWindowOpen(SENT, SENT_MS + 15 * 60_000 + 1_000)).toBe(false)
  })

  it('is open for a message sent just now and for a clock a little behind the server', () => {
    expect(isEditWindowOpen(SENT, SENT_MS)).toBe(true)
    expect(isEditWindowOpen(SENT, SENT_MS - 5_000)).toBe(true)
  })

  it('is closed for an unparseable time', () => {
    expect(isEditWindowOpen('nope', SENT_MS)).toBe(false)
  })
})

describe('messageErrorKey', () => {
  const PINS: Array<[string, string]> = [
    ['message.edit_window_closed', 'app.messaging.errors.editWindowClosed'],
    ['message.edit_thread_closed', 'app.messaging.errors.editThreadClosed'],
    ['message.not_editable', 'app.messaging.errors.notEditable'],
    ['message.already_deleted', 'app.messaging.errors.alreadyDeleted'],
    ['owner.not_a_member', 'app.messaging.errors.ownerNotMember'],
  ]

  it.each(PINS)('maps %s to %s', (code, key) => {
    expect(messageErrorKey(apiError(code), 'app.messaging.errors.editFailed')).toBe(key)
  })

  it('pins exactly these codes (a new code needs a key and a pin)', () => {
    expect(Object.keys(MESSAGE_ERROR_KEYS).sort()).toEqual(PINS.map(([code]) => code).sort())
  })

  it('falls back for an unknown code and for a non-API error', () => {
    expect(messageErrorKey(apiError('something.else'), 'app.messaging.errors.deleteFailed')).toBe(
      'app.messaging.errors.deleteFailed',
    )
    expect(messageErrorKey(new Error('x'), 'app.messaging.errors.editFailed')).toBe(
      'app.messaging.errors.editFailed',
    )
  })

  it('resolves every mapped key and both fallbacks in all 24 locales', () => {
    const keys = [
      ...Object.values(MESSAGE_ERROR_KEYS),
      'app.messaging.errors.editFailed',
      'app.messaging.errors.deleteFailed',
    ]
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = JSON.parse(
        readFileSync(
          path.resolve(__dirname, '../../../core/i18n/locales', locale, 'app.json'),
          'utf8',
        ),
      ) as unknown
      for (const key of keys) {
        let node: unknown = bundle
        for (const part of key.split('.')) {
          node = typeof node === 'object' && node !== null ? (node as never)[part] : undefined
        }
        if (typeof node !== 'string' || node.trim() === '') {
          missing.push(`${locale}:${key}`)
        }
      }
    }
    expect(missing).toEqual([])
  })
})

describe('previewOrDeleted', () => {
  it('shows the placeholder for a deleted newest message when Stage B is on', () => {
    expect(previewOrDeleted(null, true, true, 'Message deleted')).toBe('Message deleted')
  })

  it('leaves the preview as sent when Stage B is off, even for a deleted flag', () => {
    expect(previewOrDeleted(null, true, false, 'Message deleted')).toBeNull()
    expect(previewOrDeleted('hello', true, false, 'Message deleted')).toBe('hello')
  })

  it('leaves the preview alone when the newest message is not deleted or the field is absent', () => {
    expect(previewOrDeleted('hello', false, true, 'Message deleted')).toBe('hello')
    expect(previewOrDeleted('hello', undefined, true, 'Message deleted')).toBe('hello')
  })
})
