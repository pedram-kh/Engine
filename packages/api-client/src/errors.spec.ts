import { describe, expect, it } from 'vitest'

import { ApiError } from './errors'

describe('ApiError', () => {
  it('preserves status, code, message, details, raw, requestId from a well-formed envelope', () => {
    const body = {
      errors: [
        {
          id: '01HQVKWP0M4XKMJWR5J2PXKKKQ',
          status: '422',
          code: 'validation.field_required',
          title: 'The email field is required.',
          detail: 'A valid email is needed.',
          source: { pointer: '/data/attributes/email' },
          meta: { field: 'email' },
        },
      ],
      meta: { request_id: '01HQ-REQ-001' },
    }

    const err = ApiError.fromEnvelope(422, body)

    expect(err).toBeInstanceOf(ApiError)
    expect(err).toBeInstanceOf(Error)
    expect(err.name).toBe('ApiError')
    expect(err.status).toBe(422)
    expect(err.code).toBe('validation.field_required')
    expect(err.message).toBe('The email field is required.')
    expect(err.details).toHaveLength(1)
    expect(err.details[0]?.source?.pointer).toBe('/data/attributes/email')
    expect(err.details[0]?.meta).toEqual({ field: 'email' })
    expect(err.raw).toBe(body)
    expect(err.requestId).toBe('01HQ-REQ-001')
  })

  it('preserves backend error codes verbatim — no remapping or re-expansion', () => {
    // Chunk-4 / chunk-5 standard 5.4: distinct internal failure modes
    // are deliberately collapsed into single codes for non-fingerprinting.
    // The api-client must surface those codes unchanged so frontend
    // handlers cannot accidentally re-expand them.
    const verbatimCodes = [
      'auth.email.verification_invalid',
      'auth.mfa.invalid_code',
      'auth.mfa.enrollment_required',
      'auth.invalid_credentials',
      'auth.password.invalid_token',
    ]

    for (const code of verbatimCodes) {
      const err = ApiError.fromEnvelope(401, {
        errors: [{ status: '401', code, title: 'irrelevant' }],
      })
      expect(err.code).toBe(code)
    }
  })

  it('captures every entry from a multi-error validation response', () => {
    const body = {
      errors: [
        { code: 'validation.required', title: 'name is required', source: { pointer: '/name' } },
        {
          code: 'validation.min',
          title: 'password too short',
          source: { pointer: '/password' },
          meta: { min: 12 },
        },
      ],
    }

    const err = ApiError.fromEnvelope(422, body)

    expect(err.code).toBe('validation.required')
    expect(err.details).toHaveLength(2)
    expect(err.details[1]?.source?.pointer).toBe('/password')
    expect(err.details[1]?.meta).toEqual({ min: 12 })
  })

  it('falls back to a synthetic code when the envelope has no errors array', () => {
    const err = ApiError.fromEnvelope(502, '<html>upstream gateway crash</html>')

    expect(err.status).toBe(502)
    expect(err.code).toBe('http.invalid_response_body')
    expect(err.message).toContain('502')
    expect(err.details).toEqual([])
    expect(err.raw).toBe('<html>upstream gateway crash</html>')
    expect(err.requestId).toBeUndefined()
  })

  it('falls back to a synthetic code when errors is empty', () => {
    const err = ApiError.fromEnvelope(500, { errors: [] })
    expect(err.code).toBe('http.invalid_response_body')
  })

  it('falls back to http.unknown_error when the first entry has no code', () => {
    const err = ApiError.fromEnvelope(503, { errors: [{ title: 'no code present' }] })
    expect(err.code).toBe('http.unknown_error')
    expect(err.message).toBe('no code present')
  })

  it('uses HTTP status as message when the first entry has no title', () => {
    const err = ApiError.fromEnvelope(429, { errors: [{ code: 'rate_limit.exceeded' }] })
    expect(err.code).toBe('rate_limit.exceeded')
    expect(err.message).toBe('HTTP 429')
  })

  it('captures source.parameter when the envelope reports it instead of pointer', () => {
    const err = ApiError.fromEnvelope(422, {
      errors: [
        {
          code: 'validation.required',
          title: 'page is required',
          source: { parameter: 'page' },
        },
      ],
    })
    expect(err.details[0]?.source?.parameter).toBe('page')
  })

  it('drops malformed entries inside the errors array', () => {
    const err = ApiError.fromEnvelope(422, {
      errors: ['not-an-object', null, { code: 'validation.required', title: 'real entry' }],
    })
    expect(err.details).toHaveLength(1)
    expect(err.details[0]?.code).toBe('validation.required')
  })

  it('builds a network error with status 0 and exposes the underlying cause', () => {
    const cause = new Error('ECONNREFUSED')
    const err = ApiError.fromNetworkError(cause)

    expect(err.status).toBe(0)
    expect(err.code).toBe('network.error')
    expect(err.message).toMatch(/server could not be reached/i)
    expect((err as Error & { cause?: unknown }).cause).toBe(cause)
  })

  it('is throwable and survives instanceof through async boundaries', async () => {
    const probe = async (): Promise<never> => {
      throw new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' })
    }

    await expect(probe()).rejects.toBeInstanceOf(ApiError)
    await expect(probe()).rejects.toMatchObject({ code: 'auth.invalid_credentials' })
  })
})
