import { ApiError } from '@catalyst/api-client'
import { describe, expect, it } from 'vitest'

import { resolveErrorMessage, UNKNOWN_ERROR_KEY, NETWORK_ERROR_KEY } from './useErrorMessage'

/**
 * Mirror of `apps/main/src/modules/auth/composables/useErrorMessage.spec.ts`
 * — the resolver is duplicated across SPAs (per-SPA module tree) and so
 * is its test surface. Every case below pins the same behaviour main
 * pins; a regression on either side is caught at the per-SPA Vitest
 * gate.
 */
describe('resolveErrorMessage', () => {
  it('returns NETWORK_ERROR_KEY for status 0', () => {
    const err = new ApiError({ status: 0, code: 'network.error', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: NETWORK_ERROR_KEY, values: {} })
  })

  it('returns NETWORK_ERROR_KEY for code "network.error" even with non-zero status', () => {
    const err = new ApiError({ status: 503, code: 'network.error', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: NETWORK_ERROR_KEY, values: {} })
  })

  it('returns the auth.* code for an auth-namespaced ApiError', () => {
    const err = new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'auth.invalid_credentials',
      values: {},
    })
  })

  it('returns the validation.* code for a validation-namespaced ApiError', () => {
    const err = new ApiError({
      status: 422,
      code: 'validation.field_required',
      message: 'no.',
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'validation.field_required',
      values: {},
    })
  })

  it('forwards string/number values from details[0].meta as the message bag', () => {
    const err = new ApiError({
      status: 429,
      code: 'auth.login.rate_limited',
      message: 'no.',
      details: [{ code: 'auth.login.rate_limited', meta: { seconds: 30 } }],
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'auth.login.rate_limited',
      values: { seconds: 30 },
    })
  })

  it('forwards string values from details[0].meta', () => {
    const err = new ApiError({
      status: 429,
      code: 'auth.mfa.rate_limited',
      message: 'no.',
      details: [{ code: 'auth.mfa.rate_limited', meta: { minutes: '5' } }],
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'auth.mfa.rate_limited',
      values: { minutes: '5' },
    })
  })

  it('drops non-primitive meta values (defensive against weird payloads)', () => {
    const err = new ApiError({
      status: 422,
      code: 'auth.password.too_short',
      message: 'no.',
      details: [
        {
          code: 'auth.password.too_short',
          meta: {
            min: 12,
            extra: { nested: true },
            list: [1, 2, 3],
            nullish: null,
            yes: 'string-too',
          },
        },
      ],
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'auth.password.too_short',
      values: { min: 12, yes: 'string-too' },
    })
  })

  it('falls back to UNKNOWN_ERROR_KEY when the messageExists predicate rejects', () => {
    const err = new ApiError({
      status: 401,
      code: 'auth.invalid_credentials',
      message: 'no.',
    })
    const exists = (): boolean => false
    expect(resolveErrorMessage(err, exists)).toEqual({
      key: UNKNOWN_ERROR_KEY,
      values: {},
    })
  })

  it('falls back to UNKNOWN_ERROR_KEY for an http.* error code (unknown to the bundle)', () => {
    const err = new ApiError({ status: 500, code: 'http.unknown_error', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
  })

  it('returns the rate_limit.* code (chunk 7.1 prefix widening)', () => {
    const err = new ApiError({
      status: 429,
      code: 'rate_limit.exceeded',
      message: 'no.',
      details: [{ code: 'rate_limit.exceeded', meta: { seconds: 42 } }],
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'rate_limit.exceeded',
      values: { seconds: 42 },
    })
  })

  it('returns the rate_limit.* code with empty values when meta is absent', () => {
    const err = new ApiError({
      status: 429,
      code: 'rate_limit.exceeded',
      message: 'no.',
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'rate_limit.exceeded',
      values: {},
    })
  })

  it('does NOT widen to error.* codes', () => {
    const err = new ApiError({ status: 500, code: 'error.500', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
  })

  it('does NOT widen to http.* codes', () => {
    const err = new ApiError({ status: 502, code: 'http.foo', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
  })

  it('does NOT widen to bare prefixes (auth, validation, rate_limit) without a dot', () => {
    const err = new ApiError({ status: 500, code: 'authentication_failure', message: 'no.' })
    expect(resolveErrorMessage(err)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
  })

  it('falls back to UNKNOWN_ERROR_KEY for plain Error objects', () => {
    expect(resolveErrorMessage(new Error('something else'))).toEqual({
      key: UNKNOWN_ERROR_KEY,
      values: {},
    })
  })

  it('falls back to UNKNOWN_ERROR_KEY for null/undefined', () => {
    expect(resolveErrorMessage(null)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
    expect(resolveErrorMessage(undefined)).toEqual({ key: UNKNOWN_ERROR_KEY, values: {} })
  })

  it('returns empty values when ApiError has no details meta', () => {
    const err = new ApiError({
      status: 401,
      code: 'auth.invalid_credentials',
      message: 'no.',
      details: [{ code: 'auth.invalid_credentials' }],
    })
    expect(resolveErrorMessage(err)).toEqual({
      key: 'auth.invalid_credentials',
      values: {},
    })
  })

  it('uses the default messageExists predicate when none is provided', () => {
    const err = new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' })
    const result = resolveErrorMessage(err)
    expect(result.key).toBe('auth.invalid_credentials')
  })
})
