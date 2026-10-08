/**
 * AH-100 D7 pin (b) — every rule in the creator app's token layer
 * (`@catalyst/design-tokens/native.css`) is scoped under
 * `.catalyst-native`, so the file cannot restyle the web SPA even if it
 * were loaded there. At-rules are refused outright: a bare `@media` or
 * `@font-face` would be the first unscoped thing in the file.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const NATIVE_CSS = path.resolve(__dirname, '../../../../../packages/design-tokens/native.css')

function selectors(css: string): string[] {
  const withoutComments = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const out: string[] = []
  for (const match of withoutComments.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
    for (const selector of (match[1] as string).split(',')) {
      // Collapse whitespace: Prettier wraps long selectors across lines.
      out.push(selector.trim().replace(/\s+/g, ' '))
    }
  }
  return out
}

describe('native.css scoping', () => {
  const css = readFileSync(NATIVE_CSS, 'utf8')

  it('has rules to check', () => {
    expect(selectors(css).length).toBeGreaterThan(0)
  })

  it('scopes every selector under .catalyst-native', () => {
    const unscoped = selectors(css).filter(
      (selector) => selector !== '.catalyst-native' && !selector.startsWith('.catalyst-native '),
    )
    expect(unscoped).toEqual([])
  })

  it('contains no at-rules', () => {
    expect(css.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/@[a-z-]+/)
  })
})
