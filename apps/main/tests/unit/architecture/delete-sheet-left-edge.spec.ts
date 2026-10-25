/**
 * AH-104c C2-5: the delete sheet's title, checkbox box and fields share one left edge.
 *
 * This pins the STRUCTURE of the fix only. A jsdom spec cannot lay anything out, so it does not
 * and cannot prove a pixel: the proof is the measurement in the review (WebKit at the phone's
 * width with the native stylesheet loaded, then the phone itself). What it does stop is the
 * rules being edited back to the arithmetic that was wrong: a slack worked out on the web and
 * subtracted in the app. The checkbox's box is a font glyph drawn 3px inside a 24px icon, so
 * the only numbers that may appear are the icon's own 24px and the glyph's own 3px.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SOURCE = readFileSync(
  path.resolve(__dirname, '../../../src/modules/creators/components/DeleteAccountDialog.vue'),
  'utf8',
)
const STYLE = SOURCE.slice(SOURCE.indexOf('<style scoped>'))

/** The declarations of the rule whose selector contains `selector`, as one string. */
function rule(selector: string): string {
  const at = STYLE.indexOf(selector)
  expect(at, `no rule for ${selector}`).toBeGreaterThan(-1)
  return STYLE.slice(STYLE.indexOf('{', at) + 1, STYLE.indexOf('}', at)).replace(/\s+/gu, ' ')
}

describe('the delete sheet has one left edge', () => {
  it('the title takes the body padding', () => {
    expect(rule('.delete-account-title')).toContain('padding-inline: 24px')
  })

  it('the icon is 24px and its wrapper is exactly the icon, from the edge', () => {
    expect(rule('.v-selection-control__wrapper')).toMatch(/width: 24px/u)
    expect(rule('.v-selection-control__wrapper')).toMatch(/justify-content: flex-start/u)
    expect(rule('.v-selection-control__input)')).toMatch(/width: 24px/u)
    expect(rule('.v-selection-control__input .v-icon')).toMatch(/font-size: 24px/u)
  })

  it('the box is pulled back by the glyph inset (3px) and by nothing worked out', () => {
    expect(rule('.v-selection-control__input)')).toMatch(/margin-inline-start: -3px/u)
    expect(STYLE).not.toMatch(/calc\(/u)
    expect(STYLE).not.toContain('--v-selection-control-size')
  })

  it('the title and the checkbox carry the hooks the rules attach to', () => {
    expect(SOURCE).toContain('class="delete-account-title"')
    expect(SOURCE).toContain('class="delete-account-ack"')
  })
})
