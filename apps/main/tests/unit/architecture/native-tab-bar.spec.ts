/**
 * AH-100d — the creator app's bottom tab bar (Figma frames 07 / 09 / 10, node
 * "Bottom nav").
 *
 * What the frames specify (get_design_context on 20:296): five equal cells; a
 * cell is a 2px accent line slot, a 6px spacer, a 22px icon, a 12/16 label, gap
 * 4. SELECTED: icon and label in accent/primary, the label Medium (Caption/
 * Strong), and the 28 x 2 accent line (radius/full) visible; NO fill, NO block.
 * UNSELECTED: text/tertiary, Regular, the line slot empty.
 *
 * It read as "a filled rounded block" because of two things stacked: the AH-100d
 * button radius (`.catalyst-native .v-btn { border-radius: 8px !important }`)
 * reached the bar's buttons, and Vuetify draws an overlay on the active one.
 * This pins the cell as square and the overlay as not drawn, the line as the
 * only selected mark, and that the long-label failure cannot come back:
 *   - cells share the row equally and may shrink (`flex: 1 1 0; min-width: 0`),
 *     the label ellipsizes rather than overflowing;
 *   - every locale has a short app label (`availability.creatorNav.tab.*`) of at
 *     most MAX_TAB_LABEL characters, for all five tabs.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')
const LAYOUT = path.join(SRC, 'modules/creators/layouts/CreatorDashboardLayout.vue')
const LOCALES = path.join(SRC, 'core/i18n/locales')

/** 13 characters is "Uitnodigingen", the longest natural word any locale has for a tab. */
const MAX_TAB_LABEL = 13
const TABS = ['dashboard', 'jobs', 'assignments', 'messages', 'more'] as const

interface Rule {
  selector: string
  decls: Map<string, string>
}

function scopedRules(): Rule[] {
  const sfc = readFileSync(LAYOUT, 'utf8')
  const css = (/<style scoped>([\s\S]*?)<\/style>/.exec(sfc)?.[1] ?? '').replace(
    /\/\*[\s\S]*?\*\//g,
    '',
  )
  const rules: Rule[] = []
  for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const decls = new Map<string, string>()
    for (const decl of (match[2] as string).split(';')) {
      const idx = decl.indexOf(':')
      if (idx > 0) decls.set(decl.slice(0, idx).trim(), decl.slice(idx + 1).trim())
    }
    for (const selector of (match[1] as string).split(',')) {
      rules.push({ selector: selector.trim().replace(/\s+/g, ' '), decls })
    }
  }
  return rules
}

const RULES = scopedRules()
const find = (selector: string) => RULES.find((r) => r.selector === selector)
const BTN = '.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn)'

describe('native tab bar: the selected state is frame 07’s (no fill, a top line)', () => {
  it('the cell is square and unfilled (the 8px button radius must not reach it)', () => {
    const cell = find(BTN)
    expect(cell?.decls.get('border-radius')).toBe('0 !important')
    expect(cell?.decls.get('background')).toBe('transparent')
  })

  it('the active overlay and underlay are not drawn', () => {
    const hidden = RULES.filter(
      (r) =>
        /\.v-btn__(overlay|underlay)\)?$/.test(r.selector) && r.decls.get('display') === 'none',
    )
    expect(hidden.map((r) => r.selector.match(/v-btn__(overlay|underlay)/)?.[0]).sort()).toEqual([
      'v-btn__overlay',
      'v-btn__underlay',
    ])
  })

  it('selected = accent colour + a 28x2 accent line (a real element) at the top of the cell', () => {
    const active = find(
      '.creator-bottom-nav--native :deep(.v-bottom-navigation__content > .v-btn--active)',
    )
    expect(active?.decls.get('color')).toBe('var(--native-accent-primary)')
    const line = find('.creator-bottom-nav__line')
    expect(line?.decls.get('position')).toBe('absolute')
    expect(line?.decls.get('top')).toBe('0')
    expect(line?.decls.get('width')).toBe('28px')
    expect(line?.decls.get('height')).toBe('2px')
    expect(line?.decls.get('background')).toBe('var(--native-accent-primary)')
    expect(line?.decls.get('border-radius')).toBe('var(--native-radius-full)')
    // Hidden until the cell is active, shown by the active selector.
    expect(line?.decls.get('display')).toBe('none')
    const shown = RULES.find((r) =>
      r.selector.startsWith(
        '.creator-bottom-nav--native :deep(.v-btn--active) .creator-bottom-nav__line',
      ),
    )
    expect(shown?.decls.get('display')).toBe('block')
  })

  it('the line is not drawn on a Vuetify pseudo-element (its ::after is opacity 0)', () => {
    expect(RULES.filter((r) => /\.v-btn[^ ]*\)?::(after|before)/.test(r.selector))).toEqual([])
  })

  it('unselected is text/tertiary; the selected label is the Medium caption', () => {
    expect(find(BTN)?.decls.get('color')).toBe('var(--native-text-tertiary)')
    expect(find('.creator-bottom-nav--native .creator-bottom-nav__label')?.decls.get('font')).toBe(
      'var(--native-type-caption)',
    )
    const strong = find(
      '.creator-bottom-nav--native :deep(.v-btn--active) .creator-bottom-nav__label',
    )
    expect(strong?.decls.get('font')).toBe('var(--native-type-caption-strong)')
  })

  it('icon 22px and gap 4, as the frame', () => {
    const icon = RULES.find((r) => r.selector.endsWith('.v-btn .v-icon)'))
    expect(icon?.decls.get('font-size')).toBe('22px')
    expect(find(`${BTN.slice(0, -1)} .v-btn__content)`)?.decls.get('gap')).toBe('4px')
  })
})

describe('native tab bar: long labels degrade, they do not collide', () => {
  it('cells share the row equally and may shrink below their content', () => {
    const cell = find(BTN)
    expect(cell?.decls.get('flex')).toBe('1 1 0')
    expect(cell?.decls.get('min-width')).toBe('0')
  })

  it('the label ellipsizes on one line', () => {
    const label = find('.creator-bottom-nav__label')
    expect(label?.decls.get('overflow')).toBe('hidden')
    expect(label?.decls.get('text-overflow')).toBe('ellipsis')
    expect(label?.decls.get('white-space')).toBe('nowrap')
  })
})

describe('native tab bar: every locale has a short label for each of the five tabs', () => {
  const locales = readdirSync(LOCALES).filter((d) => !d.startsWith('.'))

  it('covers all 24 locales', () => {
    expect(locales).toHaveLength(24)
  })

  for (const locale of locales) {
    it(`${locale}: five non-empty labels, none longer than ${MAX_TAB_LABEL} characters`, () => {
      const file = path.join(LOCALES, locale, 'availability.json')
      const tab = JSON.parse(readFileSync(file, 'utf8')).availability.creatorNav.tab as Record<
        string,
        string
      >
      for (const key of TABS) {
        const label = tab[key] ?? ''
        expect(label.trim(), `${locale}.${key}`).not.toBe('')
        expect(Array.from(label).length, `${locale}.${key} = "${label}"`).toBeLessThanOrEqual(
          MAX_TAB_LABEL,
        )
      }
    })
  }
})
