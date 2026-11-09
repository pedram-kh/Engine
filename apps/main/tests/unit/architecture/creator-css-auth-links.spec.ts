/**
 * AH-115 L3 — creator.css rules from the L3 review: auth-card links take the accent label colour
 * in both themes, outlined fields carry no fill (the floating label straddles the box edge), and
 * floating labels ride the secondary text colour in both themes.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(
  resolve(__dirname, '../../../src/modules/creators/theme/creator.css'),
  'utf8',
)
const SCOPE = ':is(.v-theme--creator-dark, .v-theme--creator-light)'

describe('creator.css L3 rules', () => {
  it('auth-card links use the accent label token, not the browser blue', () => {
    expect(css).toContain(
      `${SCOPE} .auth-layout__card a:not(.v-btn) {\n  color: var(--native-accent-primary);`,
    )
  })

  it('outlined fields have no fill on the creator web', () => {
    expect(css).toContain(`${SCOPE} .v-field--variant-outlined {\n  background: transparent;`)
  })

  it('floating labels use the secondary colour in both themes', () => {
    expect(css).toContain(
      `${SCOPE} .v-field-label--floating {\n  color: var(--native-text-secondary);`,
    )
  })
})
