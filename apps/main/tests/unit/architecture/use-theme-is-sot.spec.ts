/**
 * Source-inspection regression test (chunk 8.1 acceptance criterion +
 * chunk-8 kickoff review priority #3): the `useTheme` composable at
 * `src/composables/useTheme.ts` is the SINGLE source of truth for theme
 * state. Components MUST consume the theme through this wrapper and
 * MUST NOT:
 *
 *   1. Mutate `theme.global.name` (or `theme.global.name.value`)
 *      directly anywhere in `src/`.
 *
 *   2. Import `useTheme` from `'vuetify'` directly anywhere in `src/`.
 *      The composable wrapper IS the import boundary; bypassing it
 *      means future refactors can't add cross-cutting concerns
 *      (persistence, system-default detection, telemetry) by editing a
 *      single file.
 *
 * The composable file itself is allowlisted — it's the SOT and
 * legitimately needs both the import and the mutation.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

/**
 * Files that legitimately bypass the wrapper. Adding to this set
 * requires a code review and a tech-debt note.
 */
const ALLOWLISTED_RELATIVE_PATHS: ReadonlySet<string> = new Set([
  // The composable IS the SOT: it MUST mutate theme.global.name.value
  // and it MUST import useTheme from vuetify.
  'composables/useTheme.ts',
])

const FORBIDDEN_PATTERNS: ReadonlyArray<{ pattern: RegExp; description: string }> = [
  {
    pattern: /\btheme\.global\.name(\.value)?\s*=/,
    description: 'direct mutation of vuetify theme.global.name (use useTheme().setTheme instead)',
  },
  {
    pattern: /\bimport\s*\{[^}]*\buseTheme\b[^}]*\}\s*from\s*['"]vuetify['"]/,
    description: 'direct import of useTheme from "vuetify" (use @/composables/useTheme instead)',
  },
]

async function walk(directory: string): Promise<string[]> {
  const out: string[] = []
  const entries = await fs.readdir(directory, { withFileTypes: true })
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      out.push(...(await walk(absolute)))
    } else if (entry.isFile() && /\.(ts|vue)$/.test(entry.name)) {
      out.push(absolute)
    }
  }
  return out
}

describe('apps/main/src — useTheme composable is the SOT for theme state', () => {
  it('finds no direct vuetify theme mutations or useTheme imports outside the composable', async () => {
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
          violations.push(`${relative} — ${description}`)
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found direct Vuetify theme bypasses in apps/main/src/:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Components must import the SPA-local useTheme composable',
          '(`@/composables/useTheme`). Only that file may import useTheme',
          'from "vuetify" or mutate theme.global.name. See chunk 8.1',
          'review priority #3 for rationale.',
        ].join('\n'),
      )
    }
  })
})
