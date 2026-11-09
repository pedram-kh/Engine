/**
 * AH-115 (L1.5, ruling Q8) — the Roboto fallback fix inside the creator scope.
 *
 * Vuetify's `.text-*` typography utilities declare `font-family: "Roboto"`, which is not
 * installed, so every element carrying one fell back to the system sans while its container
 * was Inter. The fix lives in `native.css` (the scope the creator web and the app share), so
 * the web and the app agree; it also changes the native render, which is a RECORDED
 * deviation (build 5 device walk). Pins the cause and the fix, not a symptom:
 *   1. the cause is still live: Vuetify's utilities still name Roboto;
 *   2. `native.css` sets the Inter family on all thirteen utilities, inside the shared scope;
 *   3. the teleported overlay root is covered on both sides: the app by `native.css` (a
 *      descendant of `<html>`), the web by `creator.css` (the overlay carries the theme class).
 */

import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../../../..')
const NATIVE_CSS = readFileSync(path.join(ROOT, 'packages/design-tokens/native.css'), 'utf8')
const CREATOR_CSS = readFileSync(
  path.join(ROOT, 'apps/main/src/modules/creators/theme/creator.css'),
  'utf8',
)

const UTILITIES = [
  'text-h1',
  'text-h2',
  'text-h3',
  'text-h4',
  'text-h5',
  'text-h6',
  'text-subtitle-1',
  'text-subtitle-2',
  'text-body-1',
  'text-body-2',
  'text-caption',
  'text-overline',
  'text-button',
]

function vuetifyMainCss(): string {
  const pnpm = path.join(ROOT, 'node_modules/.pnpm')
  const dir = readdirSync(pnpm).find((name) => name.startsWith('vuetify@'))
  if (dir === undefined) throw new Error('vuetify is not installed')
  return readFileSync(path.join(pnpm, dir, 'node_modules/vuetify/lib/styles/main.css'), 'utf8')
}

describe('creator Roboto fix', () => {
  it('the cause is still live: Vuetify typography utilities name Roboto', () => {
    expect(vuetifyMainCss()).toMatch(/\.text-body-2\s*\{[^}]*font-family:\s*["']?Roboto/)
  })

  it('native.css sets Inter on every typography utility, inside the shared scope', () => {
    const block = /(:is\([^)]*\)\s*:is\(\s*\.text-h1[\s\S]*?\)[\s\S]*?)\{([^}]*)\}/.exec(
      NATIVE_CSS.replace(/\/\*[\s\S]*?\*\//g, ''),
    )
    expect(block, 'the typography rule exists').not.toBeNull()
    expect(block?.[1]).toContain('.catalyst-native')
    expect(block?.[1]).toContain('.v-theme--creator-dark')
    for (const name of UTILITIES) expect(block?.[1], name).toContain(`.${name}`)
    expect(block?.[2]).toMatch(/font-family:\s*var\(--font-sans\)/)
  })

  it('covers the teleported overlay root on both sides', () => {
    expect(NATIVE_CSS).toMatch(/\.v-theme--creator-light\)\s+\.v-overlay\s*\{/)
    expect(CREATOR_CSS).toMatch(
      /\.v-theme--creator-light\)\.v-overlay\s*\{\s*font-family:\s*var\(--font-sans\)/,
    )
  })
})
