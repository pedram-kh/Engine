/**
 * AH-100c — field text in the Capacitor app is never below 16px.
 *
 * iOS WKWebView auto-zooms the page when a form control smaller than 16px
 * takes focus. The zoom does not reset on blur or on route change, and fixed
 * elements are laid out against the unzoomed box, so the tab bar slid out of
 * the visual viewport (measured: visualViewport.scale 1.1418 = 16/14, with no
 * scrollable overflow anywhere). The cause was one rule — field text at the
 * Figma 14px — so this pins the cause, not a symptom.
 *
 * It reads every native stylesheet (the design-tokens sheet plus the per-area
 * sheets), finds each rule whose selector targets text inside a field, resolves
 * the size it declares (through `var(--native-type-*)` where needed) and
 * requires >= 16px. `maximum-scale=1` was rejected as the alternative: it
 * blocks pinch-zoom, an accessibility failure.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../../../..')
const SRC = path.resolve(__dirname, '../../../src')

const SHEETS = [
  path.join(ROOT, 'packages/design-tokens/native.css'),
  path.join(SRC, 'modules/onboarding/layouts/onboardingNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorProfileFormNative.css'),
  path.join(SRC, 'modules/creators/layouts/creatorDashboardNative.css'),
]

const MIN_PX = 16

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

const ALL = SHEETS.flatMap(parse)

/** `--native-type-x: 400 16px/22px …` → the declared value, by token name. */
const TOKENS = new Map<string, string>()
for (const rule of ALL) {
  for (const [name, value] of rule.decls) {
    if (name.startsWith('--native-type-')) TOKENS.set(name, value)
  }
}

/** Selectors that target the text a user types into / picks in a field. */
const FIELD_TEXT = /(^|[\s>+~])(input|select|textarea)\b|\.v-field__input|\.v-select__selection/
/** Labels, hints and counters are not focus targets. */
const NOT_FIELD_TEXT = /v-field-label|v-label|v-messages|v-counter|v-field__outline/

function px(value: string): number | null {
  const m = /(\d+(?:\.\d+)?)(px|rem)\b/.exec(value)
  if (!m) return null
  return Number(m[1]) * (m[2] === 'rem' ? 16 : 1)
}

/** The size a rule declares for its target, or null if it declares none. */
function declaredSize(rule: Rule): { size: number | null; raw: string } | null {
  let raw = rule.decls.get('font-size') ?? rule.decls.get('font')
  if (raw === undefined) return null
  const token = /var\((--native-type-[a-z0-9-]+)\)/.exec(raw)
  if (token) raw = TOKENS.get(token[1] as string) ?? raw
  // `font: 400 16px/22px …` — the size is the token before the slash.
  const shorthand = /(\d+(?:\.\d+)?(?:px|rem))\//.exec(raw)
  return { size: px(shorthand ? (shorthand[1] as string) : raw), raw }
}

const fieldRules = ALL.filter(
  (rule) =>
    FIELD_TEXT.test(rule.selector.replace(/:deep\(([^)]*)\)/g, ' $1')) &&
    !NOT_FIELD_TEXT.test(rule.selector),
)

describe('native field text size (iOS auto-zoom)', () => {
  it('finds the field-text rules (the pin is not vacuous)', () => {
    expect(fieldRules.some((r) => r.sheet === 'native.css')).toBe(true)
    expect(fieldRules.some((r) => declaredSize(r) !== null)).toBe(true)
  })

  it(`every field-text rule that sets a size sets >= ${MIN_PX}px`, () => {
    const below = fieldRules
      .map((rule) => ({ rule, decl: declaredSize(rule) }))
      .filter(({ decl }) => decl !== null && (decl.size === null || decl.size < MIN_PX))
      .map(({ rule, decl }) => `${rule.sheet}: ${rule.selector} → ${decl?.raw}`)
    expect(below).toEqual([])
  })

  it('the type-input token itself is >= 16px', () => {
    const size = declaredSize({
      sheet: 'native.css',
      selector: '--native-type-input',
      decls: new Map([['font', TOKENS.get('--native-type-input') ?? '']]),
    })
    expect(size?.size ?? 0).toBeGreaterThanOrEqual(MIN_PX)
  })

  it('labels stay on the 14px body face (ratified: only focus targets grow)', () => {
    const label = ALL.find((r) => r.selector === '.catalyst-native .v-field .v-field-label')
    expect(label && declaredSize(label)?.size).toBe(14)
  })
})
