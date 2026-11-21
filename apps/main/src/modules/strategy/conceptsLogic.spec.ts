/** PL-2.3 — the pure rules the concepts page leans on: uploads, links, banner, warning, funnel note. */
import { describe, expect, it } from 'vitest'

import {
  bannerState,
  FUNNEL_DEFAULT_NOTES,
  linkProblem,
  nextFunnelNote,
  planUploads,
  safeHref,
  showUnderMinimum,
  stepFourLine,
  stepWarning,
} from './conceptsLogic'

const MB = 1024 * 1024
const LIMITS = { images: 10, bytes: 300 * MB }
const png = (size: number) => ({ name: 'a.png', type: 'image/png', size })

describe('planUploads', () => {
  it('accepts JPG, PNG, WebP; refuses other types and empty files', () => {
    const plan = planUploads(
      [
        { name: 'f', type: 'image/jpeg', size: 1 },
        png(1),
        { name: 'f', type: 'image/webp', size: 1 },
        { name: 'f', type: 'image/gif', size: 1 },
        { name: 'f', type: 'application/pdf', size: 1 },
        png(0),
      ],
      { count: 0, bytes: 0 },
      LIMITS,
    )
    expect(plan.accepted).toEqual([0, 1, 2])
    expect(plan.refused).toEqual([
      { index: 3, reason: 'type' },
      { index: 4, reason: 'type' },
      { index: 5, reason: 'empty' },
    ])
  })

  it('count: the 11th image is refused, counting what is already there', () => {
    const plan = planUploads([png(1), png(1), png(1)], { count: 8, bytes: 0 }, LIMITS)
    expect(plan.accepted).toEqual([0, 1])
    expect(plan.refused).toEqual([{ index: 2, reason: 'count' }])
  })

  it('bytes: over 300 MB in total is refused; exactly 300 MB is accepted', () => {
    expect(planUploads([png(300 * MB)], { count: 0, bytes: 0 }, LIMITS).accepted).toEqual([0])
    const over = planUploads([png(200 * MB), png(100 * MB + 1)], { count: 0, bytes: 0 }, LIMITS)
    expect(over.accepted).toEqual([0])
    expect(over.refused).toEqual([{ index: 1, reason: 'bytes' }])
  })
})

describe('links', () => {
  it('https only: http, javascript:, data:, ftp are refused; so are credentials, localhost, IPs and a bare word', () => {
    expect(linkProblem('https://example.com/a?b=1')).toBeNull()
    expect(linkProblem('  https://example.com  ')).toBeNull()
    expect(linkProblem('')).toBe('empty')
    for (const bad of [
      'http://example.com',
      'javascript:alert(1)',
      'data:text/html,x',
      'ftp://example.com',
      'JAVASCRIPT:alert(1)',
    ]) {
      expect(linkProblem(bad)).toBe('scheme')
    }
    for (const bad of [
      'example.com',
      'https://user:pw@example.com',
      'https://localhost/x',
      'https://127.0.0.1/x',
      'https://intranet/x',
      'https://exa mple.com',
    ]) {
      expect(linkProblem(bad)).toBe('invalid')
    }
  })

  it('safeHref only ever returns an https address', () => {
    expect(safeHref('https://example.com')).toBe('https://example.com')
    expect(safeHref('javascript:alert(1)')).toBeNull()
    expect(safeHref('http://example.com')).toBeNull()
    expect(safeHref('https://a.com/x y')).toBeNull()
  })
})

describe('banner, warning, stepper line', () => {
  const m = (total: number, approved: number) => ({
    market: 'SK',
    total,
    approved,
    awaiting: total - approved,
    changes_requested: 0,
    complete: total > 0 && approved === total,
    under_minimum: total > 0 && total < 3,
  })
  it('bannerState', () => {
    expect(bannerState(null)).toBe('none')
    expect(bannerState(m(0, 0))).toBe('none')
    expect(bannerState(m(3, 2))).toBe('amber')
    expect(bannerState(m(3, 3))).toBe('green')
  })
  it('under-minimum is a warning only when there is at least one concept and fewer than the minimum', () => {
    expect(showUnderMinimum(m(0, 0), 3)).toBe(false)
    expect(showUnderMinimum(m(2, 2), 3)).toBe(true)
    expect(showUnderMinimum(m(3, 0), 3)).toBe(false)
  })
  it('stepWarning and stepFourLine', () => {
    const c = (total: number, approved: number, complete: boolean) => ({
      total,
      approved,
      awaiting: 0,
      changes_requested: 0,
      markets_complete: 0,
      by_market: [{ ...m(total, approved), complete }],
    })
    expect(stepWarning(undefined)).toEqual({ kind: 'none' })
    expect(stepWarning(c(3, 3, true))).toEqual({ kind: 'none' })
    expect(stepWarning(c(3, 1, false))).toEqual({ kind: 'counts', approved: 1, total: 3 })
    expect(stepWarning(c(0, 0, false))).toEqual({ kind: 'empty' })
    // D1: every concept approved, whatever the other (empty) markets hold: no warning.
    const withEmptyMarket = {
      ...c(2, 2, true),
      by_market: [...c(2, 2, true).by_market, { ...m(0, 0), market: 'RO' }],
    }
    expect(stepWarning(withEmptyMarket)).toEqual({ kind: 'none' })
    expect(stepWarning({ ...c(3, 2, false), changes_requested: 1 })).toEqual({
      kind: 'counts',
      approved: 2,
      total: 3,
    })
    expect(stepFourLine(c(0, 0, false))).toEqual({ kind: 'empty' })
    expect(stepFourLine(c(4, 1, false))).toEqual({ kind: 'counts', approved: 1, total: 4 })
  })
})

describe('nextFunnelNote', () => {
  it('replaces an untouched default; keeps a written note', () => {
    expect(nextFunnelNote(null, null, 'tofu')).toBe(FUNNEL_DEFAULT_NOTES.tofu)
    expect(nextFunnelNote('tofu', FUNNEL_DEFAULT_NOTES.tofu, 'bofu')).toBe(
      FUNNEL_DEFAULT_NOTES.bofu,
    )
    expect(nextFunnelNote('tofu', 'My own note', 'bofu')).toBe('My own note')
    expect(nextFunnelNote('tofu', FUNNEL_DEFAULT_NOTES.tofu, null)).toBeNull()
  })
})
