/**
 * AH-100b — the creator app's per-area stylesheets are bound to a class that
 * only renders inside the Capacitor app, so they cannot restyle the web.
 *
 * Each entry names a stylesheet and the root class every one of its
 * selectors must start with (the stylesheet is `scoped`, so the compiled
 * selector is `.root[data-v-…] <descendant>`). Colours must come from the
 * `--native-*` tokens, never a literal.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')

const SHEETS: { file: string; root: string }[] = [
  { file: 'modules/onboarding/layouts/onboardingNative.css', root: '.onboarding-native__body' },
  { file: 'modules/creators/layouts/creatorNative.css', root: '.creator-main--native' },
  // AH-100c — the Edit profile form body: a parallel sheet under the same root,
  // so the verified wizard sheet above stays untouched.
  { file: 'modules/creators/layouts/creatorProfileFormNative.css', root: '.creator-main--native' },
  // AH-100c — the approved dashboard (frame 07).
  { file: 'modules/creators/layouts/creatorDashboardNative.css', root: '.creator-main--native' },
  // AH-101 U2 — the sign-in sheet: the layout's frame and the page's contents.
  { file: 'modules/auth/layouts/authNative.css', root: '.auth-layout--native' },
  { file: 'modules/auth/pages/signInNative.css', root: '.sign-in-native' },
  // AH-104d S4 — the sign-up sheet's contents (same look, its own sheet).
  { file: 'modules/auth/pages/signUpNative.css', root: '.sign-up-native' },
]

function selectors(css: string): string[] {
  const withoutComments = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const out: string[] = []
  for (const match of withoutComments.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
    for (const selector of (match[1] as string).split(',')) {
      out.push(selector.trim().replace(/\s+/g, ' '))
    }
  }
  return out
}

describe.each(SHEETS)('$file', ({ file, root }) => {
  const css = readFileSync(path.join(SRC, file), 'utf8')

  it('has rules to check', () => {
    expect(selectors(css).length).toBeGreaterThan(0)
  })

  it(`roots every selector at ${root}`, () => {
    // `${root}::before` / `::after` style the root's own pseudo-elements (AH-101: the
    // sign-in landing's glow band) — still rooted at the class, so still inert on web.
    const stray = selectors(css).filter(
      (selector) => !selector.startsWith(`${root} `) && !selector.startsWith(`${root}::`),
    )
    expect(stray).toEqual([])
  })

  it('contains no at-rules (a bare @media would escape the root)', () => {
    expect(css.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/@[a-z-]+/)
  })

  it('uses no literal colours', () => {
    expect(css.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i)
  })
})
