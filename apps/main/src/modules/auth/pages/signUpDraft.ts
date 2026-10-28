import type { HistoryState, Router } from 'vue-router'

/**
 * AH-104d: what the sign-up form hands to the pending page and takes back when the
 * person taps "Use a different address" (W4, 2026-10-27: a transposed letter created
 * a real account, and the way out should not make them retype their name).
 *
 * Only the two names travel. The email is deliberately NOT carried: the person is
 * there to change it, so the field comes back empty. The password is never carried
 * (retype it). Nothing here goes in the URL: the draft rides in the navigation's
 * `history.state`, so it is never logged, shared, bookmarked or sent anywhere, and a
 * fresh visit to `/sign-up` (a new history entry) starts blank.
 */

export const SIGN_UP_DRAFT_KEY = 'signUpDraft'

/** The longest the backend accepts for `name` and `last_name` (`SignUpRequest`). */
export const NAME_MAX_LENGTH = 120

export interface SignUpDraft {
  name: string
  last_name: string
}

/** `history.state` for a `router.push`: the two names, under one key. */
export function draftState(draft: SignUpDraft): HistoryState {
  return { [SIGN_UP_DRAFT_KEY]: { name: draft.name, last_name: draft.last_name } }
}

function clean(value: unknown): string {
  return typeof value === 'string' ? value.trim().slice(0, NAME_MAX_LENGTH) : ''
}

/**
 * The draft carried by the CURRENT history entry, or `null` when there is none.
 * Anything that is not a plain object of strings is ignored, so a forged or stale
 * state can only ever fill two editable text fields.
 */
export function readSignUpDraft(router: Router): SignUpDraft | null {
  const raw = (router.options.history.state as Record<string, unknown> | null)?.[SIGN_UP_DRAFT_KEY]
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return null
  }
  const draft = raw as Record<string, unknown>
  return { name: clean(draft.name), last_name: clean(draft.last_name) }
}
