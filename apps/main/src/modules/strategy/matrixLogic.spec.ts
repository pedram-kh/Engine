/**
 * PL-2.2 — the pure rules of the matrix screen: what a cell sends, the message-line rule, "Lead with", reading order.
 */
import { describe, expect, it } from 'vitest'

import type { MatrixCell } from '@catalyst/api-client'

import {
  cellKey,
  changeFor,
  fieldValue,
  initialMessageLine,
  leadWith,
  manualDiffers,
  manualNote,
  needsMessageLine,
  nextCell,
} from './matrixLogic'

const cell = (over: Partial<MatrixCell> = {}): MatrixCell => ({
  persona: 'p1',
  usp: 'u1',
  level: 'support',
  rationale: 'Why',
  message_line: 'Line',
  source: 'manual',
  has_manual_angle: false,
  manual_angle: null,
  manual_text: null,
  ...over,
})

describe('matrixLogic (PL-2.2)', () => {
  it('L1: only Priority and Support need a message line', () => {
    expect(needsMessageLine('priority')).toBe(true)
    expect(needsMessageLine('support')).toBe(true)
    expect(needsMessageLine('low')).toBe(false)
    expect(needsMessageLine(null)).toBe(false)
  })

  it('L2: a field sends trimmed text, or null when blank', () => {
    expect(fieldValue('  hi  ')).toBe('hi')
    expect(fieldValue('   ')).toBeNull()
  })

  it('L3: a new cell sends its level and the text it has; nothing chosen sends nothing', () => {
    expect(
      changeFor('p1', 'u1', null, { level: 'priority', rationale: ' r ', messageLine: 'm' }),
    ).toEqual({
      persona: 'p1',
      usp: 'u1',
      level: 'priority',
      rationale: 'r',
      message_line: 'm',
    })
    expect(changeFor('p1', 'u1', null, { level: 'low', rationale: '', messageLine: '' })).toEqual({
      persona: 'p1',
      usp: 'u1',
      level: 'low',
    })
    expect(changeFor('p1', 'u1', null, { level: null, rationale: '', messageLine: '' })).toBeNull()
  })

  it('L4: an existing cell sends only the fields that changed, and null when nothing did', () => {
    const before = cell()
    expect(
      changeFor('p1', 'u1', before, { level: 'support', rationale: 'Why', messageLine: 'Line' }),
    ).toBeNull()
    expect(
      changeFor('p1', 'u1', before, { level: 'priority', rationale: 'Why', messageLine: 'Line' }),
    ).toEqual({
      persona: 'p1',
      usp: 'u1',
      level: 'priority',
    })
    expect(
      changeFor('p1', 'u1', before, { level: 'support', rationale: '', messageLine: 'New' }),
    ).toEqual({
      persona: 'p1',
      usp: 'u1',
      rationale: null,
      message_line: 'New',
    })
  })

  it('L5: "Lead with" lists the Priority USPs in the USPs order, and is empty with none', () => {
    const usps = [1, 2, 3].map((n) => ({
      id: `u${n}`,
      title: `U${n}`,
      color_key: 'violet' as const,
      position: n,
    }))
    const cells = new Map([
      [cellKey('p1', 'u3'), cell({ usp: 'u3', level: 'priority' })],
      [cellKey('p1', 'u1'), cell({ usp: 'u1', level: 'priority' })],
      [cellKey('p1', 'u2'), cell({ usp: 'u2', level: 'support' })],
    ])
    expect(leadWith('p1', usps, cells)).toEqual(['U1', 'U3'])
    expect(leadWith('p2', usps, cells)).toEqual([])
  })

  it('L6: the next cell runs down a column, then to the next persona, then ends', () => {
    expect(nextCell(0, 0, 2, 3)).toEqual({ personaIndex: 0, uspIndex: 1 })
    expect(nextCell(0, 2, 2, 3)).toEqual({ personaIndex: 1, uspIndex: 0 })
    expect(nextCell(1, 2, 2, 3)).toBeNull()
  })

  it('R1: only an EMPTY message line is prefilled from the manual angle; an existing one is kept', () => {
    const hand = { angle: 'a1', text: 'Mine' }
    expect(initialMessageLine(null, hand)).toBe('Mine')
    expect(initialMessageLine(cell({ message_line: null }), hand)).toBe('Mine')
    expect(initialMessageLine(cell({ message_line: '' }), hand)).toBe('Mine')
    expect(initialMessageLine(cell({ message_line: 'Cell line' }), hand)).toBe('Cell line')
    expect(initialMessageLine(cell({ message_line: null }), null)).toBe('')
    expect(initialMessageLine(null, null)).toBe('')
  })

  it('R2: the conflict is Priority or Support, a line, a manual angle and DIFFERENT texts', () => {
    const hand = { angle: 'a1', text: 'Mine' }
    expect(manualDiffers(cell({ level: 'priority', message_line: 'Other' }), hand)).toBe(true)
    expect(manualDiffers(cell({ level: 'support', message_line: 'Other' }), hand)).toBe(true)
    expect(manualDiffers(cell({ level: 'priority', message_line: 'Mine' }), hand)).toBe(false)
    expect(manualDiffers(cell({ level: 'low', message_line: 'Other' }), hand)).toBe(false)
    expect(manualDiffers(cell({ level: 'priority', message_line: null }), hand)).toBe(false)
    expect(manualDiffers(cell({ level: 'priority', message_line: 'Other' }), null)).toBe(false)
    expect(manualDiffers(null, hand)).toBe(false)
  })

  it('R2: the note is conflict, kept (Low) or none (no angle, or equal texts)', () => {
    const hand = { angle: 'a1', text: 'Mine' }
    expect(manualNote(cell({ level: 'priority', message_line: 'Other' }), hand)).toBe('conflict')
    expect(manualNote(cell({ level: 'priority', message_line: 'Mine' }), hand)).toBe('none')
    expect(manualNote(cell({ level: 'low', message_line: null }), hand)).toBe('kept')
    expect(manualNote(cell({ level: 'priority', message_line: 'Other' }), null)).toBe('none')
    expect(manualNote(null, hand)).toBe('none')
  })
})
