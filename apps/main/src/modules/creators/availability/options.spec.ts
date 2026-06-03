/**
 * Unit test for the availability dialog option sets (Sprint 5 Chunk B,
 * D-b9 spot-check anchor: `assignment_auto` is NOT offered).
 */

import { describe, expect, it } from 'vitest'

import { BLOCK_TYPE_VALUES, KIND_VALUES } from './options'

describe('availability options', () => {
  it('offers exactly hard + soft block types', () => {
    expect(BLOCK_TYPE_VALUES).toEqual(['hard', 'soft'])
  })

  it('offers the four creator-settable kinds and NEVER assignment_auto (D-b9)', () => {
    expect(KIND_VALUES).toEqual(['vacation', 'personal', 'exclusive_contract', 'other'])
    expect(KIND_VALUES).not.toContain('assignment_auto')
  })
})
