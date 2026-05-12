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
 * Chunk 8.2 extension (Group 2 of chunk 8): `useThemePreference` at
 * `src/composables/useThemePreference.ts` is the SINGLE source of
 * truth for the user-preference layer that wraps `useTheme`.
 * Components MUST consume the preference through `useThemePreference()`
 * and MUST NOT:
 *
 *   3. Call `localStorage.{get,set,remove}Item('catalyst.admin.theme', …)`
 *      (or any literal of that storage key) outside the composable.
 *      The persistence layer IS the composable; bypassing it means
 *      a future refactor that flips persistence (cookie, server-side
 *      preference, IndexedDB) cannot land by editing a single file.
 *
 *   4. Reference the literal storage key string `'catalyst.admin.theme'`
 *      anywhere outside the composable, even without a `localStorage`
 *      call. Defence-in-depth: prevents a contributor from defining
 *      `const KEY = 'catalyst.admin.theme'` and then doing storage
 *      ops through it (which the regex in (3) would miss).
 *
 *   5. Call `window.matchMedia('(prefers-color-scheme: …)')` outside
 *      the composable. The system-detection layer IS the composable;
 *      bypassing it means two listeners would race for "what does the
 *      OS prefer?" — exactly the hazard the chunk-8.2 dormant
 *      `tokens.css` `@media` removal closed.
 *
 * Audit-first per chunk 7.2 D5 standing standard: a chunk-8.2 grep
 * across `apps/admin/src/**` for `localStorage`, `matchMedia`, and the
 * literal `'catalyst.admin.theme'` returned zero non-composable
 * matches at the time the patterns landed; the allowlist starts
 * empty modulo the composable file itself.
 *
 * Both composable files are allowlisted — each IS the SOT for its
 * layer and legitimately needs the otherwise-forbidden primitives.
 *
 * Mirror discipline (chunk 7.2 D2): this spec mirrors
 * `apps/main/tests/unit/architecture/use-theme-is-sot.spec.ts`.
 * Both files MUST stay in structural lockstep.
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
  // The preference composable IS the SOT for persistence + system
  // detection: it MUST call localStorage.{get,set,remove}Item with
  // the storage key, MUST reference the literal key string, and
  // MUST register a `prefers-color-scheme` matchMedia listener.
  'composables/useThemePreference.ts',
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
  {
    pattern: /\blocalStorage\s*\.\s*(?:get|set|remove)Item\s*\(/,
    description:
      'direct localStorage.{get,set,remove}Item call (use @/composables/useThemePreference for theme persistence; for non-theme persistence open a tech-debt note + extend this allowlist)',
  },
  {
    pattern: /['"`]catalyst\.(?:main|admin)\.theme['"`]/,
    description:
      'direct reference to the theme storage key literal (use STORAGE_KEY exported by @/composables/useThemePreference)',
  },
  {
    pattern: /\bmatchMedia\s*\(\s*['"`][^'"`]*prefers-color-scheme/,
    description:
      "direct window.matchMedia('(prefers-color-scheme: …)') call (use @/composables/useThemePreference for system-default detection)",
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

describe('apps/admin/src — useTheme composable is the SOT for theme state', () => {
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
          'Found direct Vuetify theme bypasses in apps/admin/src/:',
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
