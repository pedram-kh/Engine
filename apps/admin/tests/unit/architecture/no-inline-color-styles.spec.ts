/**
 * Source-inspection regression test (chunk 8.1 acceptance criterion):
 * Components MUST NOT use inline `style="color: …"`,
 * `style="background: …"`, or `style="background-color: …"` overrides
 * in their templates. These bypass the Vuetify theme system — they
 * apply a fixed visual regardless of which theme is active.
 *
 * Use Vuetify props (`color="primary"`, `:bg-color="…"`, `color="error"`)
 * or scoped `<style>` blocks that consume `var(--v-theme-*)` instead.
 *
 * Allowed: any other inline `style=` attribute (`border-radius`,
 * `text-transform`, `min-height`, …) — only color / background
 * properties touch the theme system. The negative lookbehind `(?<!-)`
 * keeps `border-color`, `text-color`, `outline-color`, etc. out of the
 * violation set.
 *
 * Allowlist: empty by design. The chunk-8.1 audit confirmed every
 * existing `.vue` file is already compliant. A new entry to the
 * allowlist requires a code review with citation to the chunk-8
 * kickoff and a follow-up tech-debt entry.
 *
 * Mirror discipline (chunk 7.2 D2): this spec mirrors
 * `apps/main/tests/unit/architecture/no-inline-color-styles.spec.ts`.
 * Both files MUST stay in structural lockstep.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

const FORBIDDEN_PATTERN = /style\s*=\s*"[^"]*(?<!-)\b(?:background-color|background|color)\s*:/

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

describe('apps/admin/src — components contain no inline color/background style overrides', () => {
  it('finds no style="color:..." or style="background:..." in any .vue file', async () => {
    const files = await walk(SRC_ROOT)
    expect(files.length).toBeGreaterThan(0)

    const violations: string[] = []
    for (const file of files) {
      const relative = path.relative(SRC_ROOT, file).split(path.sep).join('/')
      if (ALLOWLISTED_RELATIVE_PATHS.has(relative)) {
        continue
      }
      const contents = await fs.readFile(file, 'utf8')
      if (FORBIDDEN_PATTERN.test(contents)) {
        violations.push(`${relative} — inline color/background style override`)
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found inline color/background style overrides in apps/admin/src/ .vue files:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Use Vuetify color props (color="primary", :bg-color="…") or scoped',
          '<style> blocks that reference `var(--v-theme-*)` instead. See',
          'chunk 8.1 review for rationale.',
        ].join('\n'),
      )
    }
  })
})
