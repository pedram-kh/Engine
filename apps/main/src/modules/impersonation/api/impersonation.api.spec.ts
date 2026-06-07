/**
 * readHandoffToken unit tests (Sprint 13, D-9).
 *
 * The token rides the URL fragment so it never reaches the server log /
 * Referer header. These cases pin the parse: present, absent, malformed,
 * and the leading-`#` tolerance.
 */

import { describe, expect, it } from 'vitest'

import { readHandoffToken } from './impersonation.api'

describe('readHandoffToken (Sprint 13, D-9)', () => {
  it('extracts the token from a #token=... fragment', () => {
    expect(readHandoffToken('#token=abc123')).toBe('abc123')
  })

  it('tolerates a fragment without the leading #', () => {
    expect(readHandoffToken('token=abc123')).toBe('abc123')
  })

  it('returns null when no token is present', () => {
    expect(readHandoffToken('#other=1')).toBeNull()
    expect(readHandoffToken('')).toBeNull()
  })

  it('returns null for a blank token value', () => {
    expect(readHandoffToken('#token=')).toBeNull()
  })

  it('url-decodes the token value', () => {
    expect(readHandoffToken('#token=a%2Bb')).toBe('a+b')
  })
})
