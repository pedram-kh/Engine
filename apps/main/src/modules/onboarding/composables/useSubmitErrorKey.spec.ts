import { describe, expect, it } from 'vitest'

import { ApiError } from '@catalyst/api-client'

import { resolveSubmitErrorKey } from './useSubmitErrorKey'

function envelope(code: string): ApiError {
  return ApiError.fromEnvelope(422, {
    errors: [{ id: 'x', status: '422', code, title: 't', detail: 'd' }],
    meta: { request_id: 'r' },
  })
}

describe('resolveSubmitErrorKey', () => {
  it('returns the fallback for non-ApiError throwables', () => {
    expect(resolveSubmitErrorKey(new Error('boom'), 'creator.ui.errors.upload_failed')).toBe(
      'creator.ui.errors.upload_failed',
    )
    expect(resolveSubmitErrorKey('string-throw', 'fb')).toBe('fb')
    expect(resolveSubmitErrorKey(null, 'fb')).toBe('fb')
  })

  // The actual bug from Step 6: ValidationExceptionRenderer emits
  // `code: 'validation.failed'`; the SPA's i18n bundle intentionally
  // has no top-level entry for that code (per-rule disambiguation
  // lives in details[]). The naive shortcut rendered it as a literal.
  it('NEVER lets `validation.failed` reach the caller — falls back instead', () => {
    expect(resolveSubmitErrorKey(envelope('validation.failed'), 'fb')).toBe('fb')
  })

  it('passes through business-namespaced codes (creator.*, vendor.*, wizard.*)', () => {
    expect(resolveSubmitErrorKey(envelope('creator.wizard.incomplete'), 'fb')).toBe(
      'creator.wizard.incomplete',
    )
    expect(resolveSubmitErrorKey(envelope('creator.wizard.feature_enabled'), 'fb')).toBe(
      'creator.wizard.feature_enabled',
    )
    expect(resolveSubmitErrorKey(envelope('vendor.stripe.account_locked'), 'fb')).toBe(
      'vendor.stripe.account_locked',
    )
    expect(resolveSubmitErrorKey(envelope('wizard.timeout'), 'fb')).toBe('wizard.timeout')
  })

  // Defence in depth against future top-level renames the SPA's i18n
  // bundle hasn't caught up with yet (e.g. `auth.session.expired` →
  // `session.expired`). Unknown codes go to the fallback rather than
  // becoming visible English-or-bust literals.
  it('falls back for unknown top-level codes that lack a known namespace prefix', () => {
    expect(resolveSubmitErrorKey(envelope('server.error'), 'fb')).toBe('fb')
    expect(resolveSubmitErrorKey(envelope('auth.session.expired'), 'fb')).toBe('fb')
    expect(resolveSubmitErrorKey(envelope('rate_limit.exceeded'), 'fb')).toBe('fb')
  })
})
