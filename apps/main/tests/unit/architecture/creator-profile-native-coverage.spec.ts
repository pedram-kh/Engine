/**
 * AH-100c — every class the Edit profile page renders is styled natively.
 *
 * AH-100b styled the page's section cards but left the form body bare: the
 * form components are shared with the onboarding wizard, whose native rules
 * hang off `.onboarding-native__body` and never reach this page. 61 project
 * classes rendered here had no native rule behind them. Nothing failed — the
 * page just looked half-finished — so this walks the page's component tree and
 * fails when a class it renders is in neither creator sheet.
 *
 * A class that is deliberately unstyled goes in NO_VISUAL below with the
 * reason; the spec also fails on an allow-list entry that is no longer needed,
 * so the list cannot rot into a hiding place.
 */

import { readFileSync, existsSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')

const PAGE = 'modules/creators/pages/CreatorProfilePage.vue'
const SHEETS = [
  'modules/creators/layouts/creatorNative.css',
  'modules/creators/layouts/creatorProfileFormNative.css',
]

/**
 * Classes rendered by `@catalyst/ui`'s PortfolioGallery. The walk follows
 * `.vue` files in this app only, so the package component is listed by hand.
 */
const PACKAGE_CLASSES = [
  'portfolio-gallery',
  'portfolio-gallery__thumb-wrap',
  'portfolio-gallery__remove',
  'portfolio-gallery__title',
  // AH-100d (frame 04): the kind pill, the hidden full-tile badge, the title row
  // and the relocated download glyph.
  'portfolio-gallery__kind',
  'portfolio-gallery__badge',
  'portfolio-gallery__footer',
  'portfolio-gallery__download',
]

/** Rendered, but with no visual of its own to style. Reason per entry. */
const NO_VISUAL: Record<string, string> = {
  'avatar-upload-drop__input': 'visually-hidden file input, hidden by the component itself',
  'portfolio-upload-grid__input': 'visually-hidden file input, hidden by the component itself',
  'portfolio-upload-grid__sr-status': 'screen-reader live region, hidden by the component itself',
  'portfolio-step__sr-status': 'screen-reader live region, hidden by the component itself',
  'portfolio-step__upload': 'structural wrapper; the grid inside it carries the layout',
  'master-agreement-terms': 'structural wrapper; its three children are styled',
}

function resolveVue(from: string, spec: string): string | null {
  const base = spec.startsWith('@/')
    ? path.join(SRC, spec.slice(2))
    : spec.startsWith('.')
      ? path.resolve(path.dirname(from), spec)
      : null
  if (base === null) return null
  return existsSync(base) && base.endsWith('.vue') ? base : null
}

function walk(file: string, seen = new Map<string, string>()): Map<string, string> {
  if (seen.has(file)) return seen
  const source = readFileSync(file, 'utf8')
  const template = /<template>([\s\S]*)<\/template>/.exec(source)?.[1] ?? ''
  seen.set(file, template)
  for (const m of source.matchAll(/import\s+\w+\s+from\s+'([^']+\.vue)'/g)) {
    const dep = resolveVue(file, m[1] as string)
    if (dep !== null) walk(dep, seen)
  }
  return seen
}

/** Vuetify / utility / icon classes are not ours to style. */
const FOREIGN = /^(v-|mdi-|d-|text-|bg-|ma?[trblxy]?-|pa?[trblxy]?-|ga?p?-|flex-|align-|justify-)/

function renderedClasses(template: string): Set<string> {
  const out = new Set<string>()
  for (const m of template.matchAll(/(?<![:@\w-])class="([^"]*)"/g)) {
    for (const c of (m[1] as string).split(/\s+/)) if (c) out.add(c)
  }
  for (const m of template.matchAll(/:class="([^"]*)"/g)) {
    for (const q of (m[1] as string).matchAll(/'([a-z][a-z0-9_-]*)'/g)) out.add(q[1] as string)
  }
  return new Set([...out].filter((c) => !FOREIGN.test(c)))
}

const css = SHEETS.map((f) => readFileSync(path.join(SRC, f), 'utf8'))
  .join('\n')
  .replace(/\/\*[\s\S]*?\*\//g, '')
const styled = new Set([...css.matchAll(/\.([a-z][a-z0-9_-]*)/g)].map((m) => m[1] as string))

const rendered = new Set<string>()
for (const template of walk(path.join(SRC, PAGE)).values()) {
  for (const c of renderedClasses(template)) rendered.add(c)
}
for (const c of PACKAGE_CLASSES) rendered.add(c)

describe('Edit profile native coverage', () => {
  it('walks a real component tree (the pin is not vacuous)', () => {
    expect(walk(path.join(SRC, PAGE)).size).toBeGreaterThanOrEqual(8)
    expect(rendered.has('profile-basics-form')).toBe(true)
    expect(rendered.has('avatar-upload-drop__zone')).toBe(true)
    expect(rendered.has('social-card')).toBe(true)
  })

  it('styles every class the page renders (or explains why not)', () => {
    const bare = [...rendered].filter((c) => !styled.has(c) && !(c in NO_VISUAL)).sort()
    expect(bare).toEqual([])
  })

  it('keeps the no-visual list honest: each entry is rendered and has no rule', () => {
    const stale = Object.keys(NO_VISUAL).filter((c) => !rendered.has(c) || styled.has(c))
    expect(stale).toEqual([])
  })
})
