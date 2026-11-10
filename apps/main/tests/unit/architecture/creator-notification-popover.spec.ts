/**
 * AH-115 (build 4 native walk) — the creator notification popover has ONE scroll container.
 *
 * Vuetify gives a menu's card `overflow: auto`; with the list also scrolling that was two nested
 * scrollers and the list never reached its end under the page scrollbar. In `creator.css` the card
 * must clip (`overflow: hidden`), the centre must be height-capped, and only the list may scroll
 * (`overflow-y: auto`). Every rule stays under the creator theme scope (the scoping spec covers that).
 *
 * Break-revert: set the card to `overflow: auto` or the list to `overflow: visible` → this fails.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(
  path.resolve(__dirname, '../../../src/modules/creators/theme/creator.css'),
  'utf8',
).replace(/\s+/g, ' ')

function rule(selectorEnd: string): string {
  const escaped = selectorEnd.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const m = css.match(new RegExp(`(?:^|\\}) ?[^{}]*${escaped} \\{([^}]*)\\}`))
  if (!m) throw new Error(`no rule ending in ${selectorEnd}`)
  return m[1]!
}

describe('creator notification popover scrolling', () => {
  it('the card clips and does not scroll', () => {
    const body = rule('.notification-dropdown-card')
    expect(body).toMatch(/overflow:\s*hidden/)
    expect(body).not.toMatch(/overflow(-y)?:\s*auto/)
  })

  it('the centre is height-capped and is not a scroller', () => {
    const body = rule('.notification-dropdown-card .notification-center--dropdown')
    expect(body).toMatch(/max-height:\s*min\(70vh,\s*560px\)/)
    expect(body).not.toMatch(/overflow/)
  })

  it('only the list scrolls', () => {
    const body = rule(".notification-dropdown-card [data-test='notification-list']")
    expect(body).toMatch(/overflow-y:\s*auto/)
  })
})
