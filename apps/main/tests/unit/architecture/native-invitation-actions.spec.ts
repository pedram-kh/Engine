/**
 * AH-100d — the invitation card's action row in the creator app (Figma frame
 * 10): "Accept invitation" alone on a full-width row, "View brief" and
 * "Decline" side by side beneath it.
 *
 * Why this is pinned as CSS: the original rule gave Accept `flex: 1 0 100%`,
 * but `.creator-main--native .assignment__actions .v-btn { flex: 1 1 0 }` out-
 * ranked it on specificity, so with all three actions Accept shared one row
 * with the others and its label was clipped. jsdom lays out nothing, so the
 * component spec cannot see this. The fix is structural: the row is a two-column
 * GRID and Accept spans both columns. `flex` does nothing on a grid item, so
 * neither that rule nor the page's own mobile `flex: 1 1 0` rule can squeeze it
 * again. This pins that structure, and that no native rule takes the span back.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')
const SHEET = path.join(SRC, 'modules/creators/layouts/creatorNative.css')

interface Rule {
  selector: string
  decls: Map<string, string>
}

function parse(file: string): Rule[] {
  const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
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

const RULES = parse(SHEET)
const forActions = RULES.filter((r) => r.selector.includes('.assignment__actions'))

describe('native invitation actions (frame 10)', () => {
  it('the actions row is a two-column grid', () => {
    const row = RULES.find(
      (r) => r.selector === '.creator-main--native :deep(.assignment__actions)',
    )
    expect(row?.decls.get('display')).toBe('grid')
    expect(row?.decls.get('grid-template-columns')).toMatch(/^minmax\(0, 1fr\) minmax\(0, 1fr\)$/)
  })

  it('Accept spans both columns and leads', () => {
    const accept = forActions.find((r) => r.selector.includes('creator-assignment-accept-'))
    expect(accept).toBeDefined()
    expect(accept?.decls.get('grid-column')).toBe('1 / -1')
    expect(accept?.decls.get('order')).toBe('-1')
  })

  it('no native rule on the row’s buttons un-spans Accept or makes the row a flex line again', () => {
    for (const r of forActions) {
      if (r.selector.includes('creator-assignment-accept-')) continue
      expect(r.decls.get('grid-column') ?? '1 / -1').toBe('1 / -1')
      expect(r.decls.get('display') ?? 'grid').toBe('grid')
    }
  })

  it('the buttons may shrink below their content width (no clipped label in a half column)', () => {
    const btn = forActions.find((r) => r.selector.endsWith('.assignment__actions .v-btn)'))
    expect(btn?.decls.get('min-width')).toBe('0')
  })
})
