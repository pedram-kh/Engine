/**
 * AH-115 L0.5 — every rule in the creator THEME stylesheet is scoped to the creator
 * theme classes, so nothing in it can restyle the agency, admin or bare-app trees.
 * Mirror of `agency-theme-scoping.spec.ts` (which stays unedited). The page and layout
 * sheets under `modules/creators/` are scoped by DOM modifier classes and are covered
 * by `native-page-css-scoping.spec.ts`; `native.css` by `native-css-scoping.spec.ts`.
 *
 * Every selector in every `*.css` under `src/modules/creators/theme/` must begin with
 * `:is(.v-theme--creator-dark, .v-theme--creator-light)` (a compound such as
 * `…).v-overlay` or a descendant is fine), EXCEPT the two canvas selectors named
 * in `CANVAS_EXCEPTIONS`. That is the SINGLE exception (the AH-114 pattern): the
 * browser canvas is outside the creator layout, so it is reached with
 * `:root:has(.v-application.v-theme--creator-*)`. Each exception rule may declare
 * `background-color` only, and the value must equal the theme module's
 * `background` for that theme.
 *
 * Also refused: at-rules (they hide unscoped rules inside braces), and `html`,
 * `body` or `:root` anywhere in a non-exception selector.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  CREATOR_DARK_THEME,
  CREATOR_LIGHT_THEME,
  creatorColors,
} from '@/modules/creators/theme/creatorThemes'

const CREATOR_THEME_SRC = path.resolve(__dirname, '../../../src/modules/creators/theme')

const SCOPE = ':is(.v-theme--creator-dark, .v-theme--creator-light)'

/** The single allowed non-descendant form, by name. */
const CANVAS_EXCEPTIONS = {
  ':root:has(.v-application.v-theme--creator-dark)': CREATOR_DARK_THEME,
  ':root:has(.v-application.v-theme--creator-light)': CREATOR_LIGHT_THEME,
} as const

function cssFiles(dir: string): string[] {
  const out: string[] = []
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) out.push(...cssFiles(full))
    else if (name.endsWith('.css')) out.push(full)
  }
  return out
}

function strip(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

/** Normalise whitespace, including the padding Prettier puts inside `:is( … )`. */
function normalise(selector: string): string {
  return selector
    .trim()
    .replace(/\s+/g, ' ')
    .replace(/\(\s+/g, '(')
    .replace(/\s+\)/g, ')')
    .replace(/,\)/g, ')')
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

interface Rule {
  selector: string
  declarations: string
}

function rules(css: string): Rule[] {
  const out: Rule[] = []
  for (const match of strip(css).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    for (const selector of splitTopLevel(match[1] as string)) {
      out.push({ selector, declarations: (match[2] as string).trim() })
    }
  }
  return out
}

function isScoped(selector: string): boolean {
  if (!selector.startsWith(SCOPE)) return false
  const next = selector.charAt(SCOPE.length)
  return next === '' || /[\s.:[>+~]/.test(next)
}

const FILES = cssFiles(CREATOR_THEME_SRC)
const ALL_RULES = FILES.flatMap((file) =>
  rules(readFileSync(file, 'utf8')).map((rule) => ({
    file: path.relative(CREATOR_THEME_SRC, file),
    ...rule,
  })),
)
const EXCEPTION_RULES = ALL_RULES.filter((rule) => rule.selector in CANVAS_EXCEPTIONS)
const NON_EXCEPTION_RULES = ALL_RULES.filter((rule) => !(rule.selector in CANVAS_EXCEPTIONS))

describe('creator css scoping', () => {
  it('finds creator stylesheets with rules to check', () => {
    expect(FILES.length).toBeGreaterThan(0)
    expect(ALL_RULES.length).toBeGreaterThan(0)
  })

  it('scopes every selector under the creator theme classes, bar the named canvas exception', () => {
    const unscoped = NON_EXCEPTION_RULES.filter((rule) => !isScoped(rule.selector)).map(
      (rule) => `${rule.file}: ${rule.selector}`,
    )
    expect(unscoped).toEqual([])
  })

  it('has no bare html, body or :root in any non-exception selector', () => {
    const bare = NON_EXCEPTION_RULES.filter((rule) =>
      /(^|[\s>+~(,])(html|body|:root)(?![\w-])/.test(rule.selector),
    ).map((rule) => `${rule.file}: ${rule.selector}`)
    expect(bare).toEqual([])
  })

  it('contains no at-rules', () => {
    for (const file of FILES) {
      expect(strip(readFileSync(file, 'utf8')), file).not.toMatch(/@[a-z-]+/)
    }
  })

  describe('the canvas exception', () => {
    it('is present for both themes, exactly once each', () => {
      for (const selector of Object.keys(CANVAS_EXCEPTIONS)) {
        expect(EXCEPTION_RULES.filter((rule) => rule.selector === selector)).toHaveLength(1)
      }
    })

    it('declares background-color and nothing else', () => {
      for (const rule of EXCEPTION_RULES) {
        expect(rule.declarations, rule.selector).toMatch(/^background-color:\s*#[0-9a-f]{6};?$/i)
      }
    })

    it.each(Object.entries(CANVAS_EXCEPTIONS))('%s equals the %s background', (selector, theme) => {
      const rule = EXCEPTION_RULES.find((r) => r.selector === selector)
      const value = /#[0-9a-f]{6}/i.exec(rule?.declarations ?? '')?.[0]
      expect(value?.toLowerCase()).toBe(creatorColors()[theme].background?.toLowerCase())
    })
  })
})
