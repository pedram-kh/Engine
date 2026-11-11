/**
 * AH-115 (local walk) — the Contact details country-code picker on the creator web.
 *
 * Its items are keyed by dial code and dozens of countries share "+1", so the autocomplete treats
 * all of them as selected and paints a flag-and-code chip for each; on the creator web they ran
 * out of the 125 px field over the number beside it. In `creator.css` only the first selection may
 * show and nothing may paint outside the field box. Both rules stay under the creator theme scope
 * (the scoping spec covers that), so the agency and the admin are untouched.
 *
 * Break-revert: delete either rule → this fails.
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

describe('creator country-code picker', () => {
  it('shows one selection, not one per country that shares the dial code', () => {
    const body = rule('.profile-basics-form__dial-code .v-autocomplete__selection:nth-child(n + 2)')
    expect(body).toMatch(/display:\s*none/)
  })

  it('clips the field input to the field box', () => {
    const body = rule('.profile-basics-form__dial-code .v-field__input')
    expect(body).toMatch(/overflow:\s*hidden/)
  })
})
