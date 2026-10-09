/**
 * AH-100d — buttons in the Capacitor app take Figma's `radius/lg` (8px).
 *
 * AH-100 put the radius in `buildVuetifyOptions(true).defaults.VBtn.style`
 * and it never rendered: the same default sets `rounded: 0`, Vuetify turns
 * that into the class `.rounded-0 { border-radius: 0 !important }`, and an
 * inline style without `!important` cannot beat an `!important` class
 * (measured in Chromium: 0px). This pins the cause, not a symptom:
 *   1. the cause is still live (native VBtn default is `rounded: 0`, and
 *      Vuetify's `.rounded-0` is `!important`), so the override is needed;
 *   2. `native.css` carries an `!important` radius rule for buttons that
 *      out-ranks `.rounded-0` on specificity, and it is the 8px token;
 *   3. no OTHER native sheet sets a radius on a `.v-btn` without
 *      `!important`: such a rule would silently lose to `.rounded-0` the
 *      same way.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { buildVuetifyOptions } from '@/plugins/vuetify'

const ROOT = path.resolve(__dirname, '../../../../..')
const SRC = path.resolve(__dirname, '../../../src')
const NATIVE_CSS = path.join(ROOT, 'packages/design-tokens/native.css')

const SHEETS = [
  NATIVE_CSS,
  path.join(SRC, 'modules/onboarding/layouts/onboardingNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorProfileFormNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorDashboardNative.css'),
]

interface Rule {
  sheet: string
  selector: string
  decls: Map<string, string>
}

function strip(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

function parse(file: string): Rule[] {
  const rules: Rule[] = []
  for (const match of strip(readFileSync(file, 'utf8')).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const decls = new Map<string, string>()
    for (const decl of (match[2] as string).split(';')) {
      const idx = decl.indexOf(':')
      if (idx > 0) decls.set(decl.slice(0, idx).trim(), decl.slice(idx + 1).trim())
    }
    for (const selector of (match[1] as string).split(',')) {
      rules.push({
        sheet: path.basename(file),
        selector: selector.trim().replace(/\s+/g, ' '),
        decls,
      })
    }
  }
  return rules
}

function vuetifyMainCss(): string {
  const pnpm = path.join(ROOT, 'node_modules/.pnpm')
  const dir = readdirSync(pnpm).find((name) => name.startsWith('vuetify@'))
  if (dir === undefined) throw new Error('vuetify is not installed')
  return readFileSync(path.join(pnpm, dir, 'node_modules/vuetify/lib/styles/main.css'), 'utf8')
}

const ALL = SHEETS.flatMap(parse)

describe('native button radius (the AH-100 token that never rendered)', () => {
  it('the cause is still live: native VBtn defaults to rounded:0 and .rounded-0 is !important', () => {
    expect(buildVuetifyOptions(true).defaults?.VBtn?.rounded).toBe(0)
    expect(vuetifyMainCss()).toMatch(/\.rounded-0\s*\{\s*border-radius:\s*0\s*!important;?\s*\}/)
  })

  it('native.css sets the 8px radius token on every non-icon button with !important', () => {
    const rule = ALL.find(
      (r) => r.sheet === 'native.css' && r.selector === '.catalyst-native .v-btn:not(.v-btn--icon)',
    )
    expect(rule?.decls.get('border-radius')).toBe('var(--native-radius-lg) !important')
    const token = ALL.find((r) => r.sheet === 'native.css' && r.selector === '.catalyst-native')
    expect(token?.decls.get('--native-radius-lg')).toBe('8px')
  })

  it('icon-only buttons get Vuetify’s circle back (rounded-0 squared them too)', () => {
    const rule = ALL.find(
      (r) => r.sheet === 'native.css' && r.selector === '.catalyst-native .v-btn.v-btn--icon',
    )
    expect(rule?.decls.get('border-radius')).toBe('var(--native-radius-full) !important')
  })

  it('no native sheet sets a button radius without !important (it would lose to .rounded-0)', () => {
    const losers = ALL.filter(
      (r) =>
        /\.v-btn\b/.test(r.selector) &&
        !/\.v-btn__/.test(r.selector) &&
        r.decls.has('border-radius') &&
        !(r.decls.get('border-radius') ?? '').includes('!important'),
    ).map((r) => `${r.sheet}: ${r.selector} → ${r.decls.get('border-radius')}`)
    expect(losers).toEqual([])
  })
})
