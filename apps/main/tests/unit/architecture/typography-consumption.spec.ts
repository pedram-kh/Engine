/**
 * Typography-consumption architecture test (Sprint 3.5 Chunk 2 — § 1.3,
 * Decision D-fork-b).
 *
 * The Catalyst Engine v2 type scale was exposed as `--catalyst-typography-*` CSS
 * variables in Chunk 1 but had ZERO consumers (cargo-cult risk). Chunk 2
 * wires the shared `@catalyst/ui` components to consume those variables
 * instead of hardcoded `font-size: <n>rem` literals. This test pins both
 * halves of that contract:
 *
 *   1. NO hardcoded `font-size: <number>rem` literal in any
 *      `packages/ui/src` component `<style>` block — except a small,
 *      explicitly-justified allowlist (a glyph sized for visual weight,
 *      not a typographic role).
 *
 *   2. The shared package ACTUALLY consumes the typography vars (at least
 *      one `var(--catalyst-typography-*)` reference exists) — so the
 *      invariant can't be satisfied by simply deleting all sizing.
 *
 * Cross-package scan (mirrors `color-system-parity.spec.ts`): this spec
 * lives in the SPA test suite but inspects the SHARED `@catalyst/ui`
 * package via `fs`. `packages/ui` has no Vitest harness of its own — see
 * the "packages/ui has no test harness" tech-debt entry.
 *
 * Mirror discipline (chunk 7.2 D2): byte-identical to
 * `apps/admin/tests/unit/architecture/typography-consumption.spec.ts`
 * (both inspect the same shared package).
 *
 * Break-revert (standing standard #40): re-introduce a
 * `font-size: 0.875rem` literal in any shared component → this test
 * fails → revert.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const UI_SRC_ROOT = path.resolve(__dirname, '../../../../../packages/ui/src')

const FONT_SIZE_REM = /font-size:\s*([\d.]+rem)/g
const TYPOGRAPHY_VAR = /var\(--catalyst-typography-/

/**
 * Allowlisted rem `font-size` literals, keyed by the component's path
 * relative to `packages/ui/src`. Each entry is a deliberate one-off that
 * does not map to a step in the type scale. Adding an entry requires a
 * code review + an explanatory comment at the declaration site.
 */
const ALLOWLISTED_REM_LITERALS: ReadonlyMap<string, ReadonlySet<string>> = new Map([
  // Flag glyph (CountryDisplay): sized for visual weight, not a
  // typographic role. The explanatory comment lives at the declaration.
  ['components/CountryDisplay.vue', new Set(['1.125rem'])],
])

async function walkVue(directory: string): Promise<string[]> {
  const out: string[] = []
  const entries = await fs.readdir(directory, { withFileTypes: true })
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      out.push(...(await walkVue(absolute)))
    } else if (entry.isFile() && entry.name.endsWith('.vue')) {
      out.push(absolute)
    }
  }
  return out
}

describe('typography consumption — @catalyst/ui consumes the type-scale CSS vars', () => {
  it('contains no un-allowlisted hardcoded font-size rem literal', async () => {
    const files = await walkVue(UI_SRC_ROOT)
    expect(files.length).toBeGreaterThan(0)

    const violations: string[] = []
    for (const file of files) {
      const relative = path.relative(UI_SRC_ROOT, file).split(path.sep).join('/')
      const contents = await fs.readFile(file, 'utf8')
      const allowed = ALLOWLISTED_REM_LITERALS.get(relative) ?? new Set<string>()

      for (const match of contents.matchAll(FONT_SIZE_REM)) {
        const literal = match[1]
        if (literal !== undefined && !allowed.has(literal)) {
          violations.push(`${relative} — hardcoded font-size: ${literal}`)
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found hardcoded font-size rem literals in packages/ui/src:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Consume the type scale via var(--catalyst-typography-<step>-size)',
          '(see @catalyst/design-tokens/tokens.css). If a literal is a',
          'deliberate non-typographic one-off (e.g. a glyph), add it to the',
          'ALLOWLISTED_REM_LITERALS map with an explanatory declaration',
          'comment. See Sprint 3.5 Chunk 2 review for rationale.',
        ].join('\n'),
      )
    }
  })

  it('actually references the typography CSS vars (consumption is real)', async () => {
    const files = await walkVue(UI_SRC_ROOT)
    let consumers = 0
    for (const file of files) {
      const contents = await fs.readFile(file, 'utf8')
      if (TYPOGRAPHY_VAR.test(contents)) consumers += 1
    }
    expect(consumers).toBeGreaterThan(0)
  })
})
