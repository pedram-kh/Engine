/**
 * boardTokens (Sprint 12 Chunk 2, D-11). Pins the Q2 token map: the stored
 * PREFIXED `status-*` token resolves to the UNprefixed `boardStatus` hex, with a
 * neutral fallback for an unknown / missing token.
 */

import { boardStatus } from '@catalyst/design-tokens'
import { describe, expect, it } from 'vitest'

import { boardColorHex, boardColorOptions } from './boardTokens'

describe('boardColorHex', () => {
  it('strips the status- prefix and resolves the palette hex', () => {
    expect(boardColorHex('status-paid')).toBe(boardStatus.paid)
    expect(boardColorHex('status-todefine')).toBe(boardStatus.todefine)
    expect(boardColorHex('status-blocked')).toBe(boardStatus.blocked)
  })

  it('falls back to the neutral grey for an unknown token', () => {
    expect(boardColorHex('status-nonsense')).toBe(boardStatus.todefine)
  })

  it('falls back for null / undefined / empty', () => {
    expect(boardColorHex(null)).toBe(boardStatus.todefine)
    expect(boardColorHex(undefined)).toBe(boardStatus.todefine)
    expect(boardColorHex('')).toBe(boardStatus.todefine)
  })
})

describe('boardColorOptions', () => {
  it('exposes every palette key as a PREFIXED token + hex (the picker source)', () => {
    const options = boardColorOptions()
    expect(options).toContainEqual({ token: 'status-paid', hex: boardStatus.paid })
    expect(options.every((o) => o.token.startsWith('status-'))).toBe(true)
    expect(options).toHaveLength(Object.keys(boardStatus).length)
  })
})
