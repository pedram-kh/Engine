/**
 * Source-inspection regression test (chunk 8.1 acceptance criterion):
 * Components MUST NOT contain hard-coded color literals — `#abcdef` hex
 * codes, `rgb()` / `rgba()` calls with literal arguments, or
 * `hsl()` / `hsla()` calls. Colors flow exclusively from the Vuetify
 * theme via `color="..."` props on Vuetify components or
 * `var(--v-theme-*)` (and `rgb(var(--v-theme-*))`) CSS-variable
 * references in scoped <style> blocks.
 *
 * Why: hard-coded colors break the theme-switching contract owned by
 * `useTheme` (`@/composables/useTheme`). A page with a literal
 * `#FFFFFF` background looks fine in light mode and unreadable in dark
 * mode. Only files that consume the theme through Vuetify or CSS
 * variables actually re-render their colors when the active theme
 * flips.
 *
 * Allowed exceptions (handled by the regex, not by an allowlist):
 *   - `rgb(var(--v-theme-*))`         — canonical Vuetify CSS-variable
 *                                       consumption pattern.
 *   - `rgba(var(--v-theme-*), 0.5)`   — same, with opacity wrapper.
 *   - `var(--v-theme-*)` direct refs  — CSS variable references; never
 *                                       matched by the hex / rgb / hsl
 *                                       regexes.
 * The negative lookahead `(?!var\()` immediately after the function-call
 * paren keeps these legitimate uses out of the violation set.
 *
 * Allowlist: empty by design. The chunk-8.1 audit confirmed every
 * existing `.vue` file already consumes colors through Vuetify props or
 * `var(--v-theme-*)` CSS variables — there are no legitimate
 * pre-existing exceptions to grandfather. A new entry to the allowlist
 * requires a code review with citation to the chunk-8 kickoff and a
 * follow-up tech-debt entry.
 *
 * If you need a NEW color literal, add it to the
 * `@catalyst/design-tokens` semantic palette (`packages/design-tokens/
 * src/semantic.ts`), then expose it via the Vuetify theme
 * (`packages/design-tokens/src/vuetify.ts`).
 *
 * Mirror discipline (chunk 7.2 D2): this spec mirrors
 * `apps/main/tests/unit/architecture/no-hard-coded-colors.spec.ts`.
 * Both files MUST stay in structural lockstep.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

const FORBIDDEN_PATTERNS: ReadonlyArray<{ pattern: RegExp; description: string }> = [
  {
    pattern: /#[0-9a-fA-F]{3,8}\b/,
    description: 'hex color literal',
  },
  {
    pattern: /\brgba?\(\s*(?!var\()/,
    description: 'rgb()/rgba() function call with literal arguments',
  },
  {
    pattern: /\bhsla?\(\s*(?!var\()/,
    description: 'hsl()/hsla() function call with literal arguments',
  },
]

const ALLOWLISTED_RELATIVE_PATHS: ReadonlySet<string> = new Set()

async function walk(directory: string): Promise<string[]> {
  const out: string[] = []
  const entries = await fs.readdir(directory, { withFileTypes: true })
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      out.push(...(await walk(absolute)))
    } else if (entry.isFile() && entry.name.endsWith('.vue')) {
      out.push(absolute)
    }
  }
  return out
}

describe('apps/admin/src — components contain no hard-coded color literals', () => {
  it('finds no hex/rgb/hsl literals in any .vue file', async () => {
    const files = await walk(SRC_ROOT)
    expect(files.length).toBeGreaterThan(0)

    const violations: string[] = []
    for (const file of files) {
      const relative = path.relative(SRC_ROOT, file).split(path.sep).join('/')
      if (ALLOWLISTED_RELATIVE_PATHS.has(relative)) {
        continue
      }
      const contents = await fs.readFile(file, 'utf8')
      for (const { pattern, description } of FORBIDDEN_PATTERNS) {
        if (pattern.test(contents)) {
          violations.push(`${relative} — disallowed ${description}`)
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found hard-coded color literals in apps/admin/src/ .vue files:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Use Vuetify color props (color="primary", color="error", …) or',
          '`var(--v-theme-*)` CSS variables. Add new colors to',
          '@catalyst/design-tokens. See chunk 8.1 review for rationale.',
        ].join('\n'),
      )
    }
  })
})
