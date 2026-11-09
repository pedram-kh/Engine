/**
 * AH-100 D7 pin (b), widened by AH-115 (L1.2) — every rule in the creator app's token layer
 * (`@catalyst/design-tokens/native.css`) is scoped under
 * `:is(.catalyst-native, .v-theme--creator-dark, .v-theme--creator-light)`. The first
 * class is the Capacitor shell's `<html>` class; the other two are put on a `<v-app>` (and
 * its teleported overlays) by the creator-side layouts only, so the file cannot restyle the
 * agency, the admin or the bare app even though the web now loads it. At-rules are refused
 * outright: a bare `@media` or `@font-face` would be the first unscoped thing in the file.
 * (Polarity edit, by name: before AH-115 the scope was `.catalyst-native` alone.)
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const NATIVE_CSS = path.resolve(__dirname, '../../../../../packages/design-tokens/native.css')

/** Normalise whitespace, including the padding Prettier puts inside `:is( … )`. */
function normalise(selector: string): string {
  return selector.trim().replace(/\s+/g, ' ').replace(/\(\s+/g, '(').replace(/\s+\)/g, ')')
}

/** Split a selector list on top-level commas only (not inside `:is(...)`). */
function splitTopLevel(list: string): string[] {
  const parts: string[] = []
  let depth = 0
  let current = ''
  for (const char of list) {
    if (char === '(') depth += 1
    if (char === ')') depth -= 1
    if (char === ',' && depth === 0) {
      parts.push(current)
      current = ''
    } else {
      current += char
    }
  }
  parts.push(current)
  return parts.map(normalise).filter((part) => part.length > 0)
}

function selectors(css: string): string[] {
  const withoutComments = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const out: string[] = []
  for (const match of withoutComments.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
    out.push(...splitTopLevel(match[1] as string))
  }
  return out
}

const SCOPE = ':is(.catalyst-native, .v-theme--creator-dark, .v-theme--creator-light)'

describe('native.css scoping', () => {
  const css = readFileSync(NATIVE_CSS, 'utf8')

  it('has rules to check', () => {
    expect(selectors(css).length).toBeGreaterThan(0)
  })

  it('scopes every selector under the widened scope', () => {
    const unscoped = selectors(css).filter(
      (selector) => selector !== SCOPE && !selector.startsWith(`${SCOPE} `),
    )
    expect(unscoped).toEqual([])
  })

  it('contains no at-rules', () => {
    expect(css.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/@[a-z-]+/)
  })
})
