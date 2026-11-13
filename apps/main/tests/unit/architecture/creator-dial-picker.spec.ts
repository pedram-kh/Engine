/**
 * The Contact details country-code picker on the creator web: its field input must be clipped to
 * the field box so nothing paints outside it over the number beside it. The rule stays under the
 * creator theme scope (the scoping spec covers that), so the agency and the admin are untouched.
 *
 * AH-122: the picker now selects by ISO country code, so only one chip ever renders and the old
 * rule hiding every chip after the first (and its assertion here) was removed.
 *
 * Break-revert: delete the rule → this fails.
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
  it('clips the field input to the field box', () => {
    const body = rule('.profile-basics-form__dial-code .v-field__input')
    expect(body).toMatch(/overflow:\s*hidden/)
  })
})
