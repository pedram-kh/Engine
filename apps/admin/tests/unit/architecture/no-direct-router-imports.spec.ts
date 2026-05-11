/**
 * Source-inspection regression test mirroring
 * `apps/main/tests/unit/architecture/no-direct-router-imports.spec.ts`
 * (chunk-6.5 acceptance criterion). Path-(b) mirror under the admin
 * tree — same rationale the chunk 7.2-7.3 review applied to
 * `i18n-auth-codes.spec.ts`: each SPA owns its own architectural
 * surface and tests stay co-located with the source they guard.
 *
 * Components MUST consume the router via the `useRouter` / `useRoute`
 * composables, NOT by importing the singleton router instance from
 * `@/core/router`.
 *
 * Components that reach for the singleton break testability — they
 * pull in the real production router and its real `beforeEach` hook
 * during component tests, which then need a fully-wired Pinia + a
 * stubbed bootstrap chain just to render a component that should be
 * trivial to mount in isolation.
 *
 * The exception is the wiring layer itself (`core/api/index.ts`,
 * `main.ts`) — both legitimately use the singleton router for
 * cross-cutting concerns. `core/router/index.ts` defines the singleton.
 * `core/api/index.ts` lazily resolves the router in its 401-policy
 * callback (dynamic import, breaks the cycle). Both are explicitly
 * allowlisted below.
 *
 * `main.ts` is NOT covered by this test because its import uses the
 * relative `./core/router` path; the forbidden regex matches only the
 * path-aliased `@/core/router` form (mirror of main's pattern — see
 * the equivalent allowlist commentary in main's test).
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

/**
 * Files that legitimately import the singleton router. Every entry
 * here requires a code review.
 */
const ALLOWLISTED_RELATIVE_PATHS: ReadonlySet<string> = new Set([
  // Wiring layer — defines the router itself.
  'core/router/index.ts',
  // 401 policy needs to push to the sign-in route from outside any
  // component (lazy-imported to break the cycle, but still imports
  // the module).
  'core/api/index.ts',
])

const FORBIDDEN_PATTERNS: ReadonlyArray<{ pattern: RegExp; description: string }> = [
  {
    // ESM static import of the router singleton (named `router`).
    pattern: /\bimport\s*\{[^}]*\brouter\b[^}]*\}\s*from\s+['"]@\/core\/router['"]/,
    description: 'static import { router } from "@/core/router"',
  },
  {
    // ESM dynamic import of `@/core/router` (regardless of binding).
    // We forbid it in components — wiring layer is allowlisted.
    pattern: /\bimport\s*\(\s*['"]@\/core\/router['"]\s*\)/,
    description: 'dynamic import("@/core/router")',
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

describe('apps/admin/src — components do not import the router singleton', () => {
  it('finds no forbidden router imports outside the allowlisted wiring files', async () => {
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
          'Found components importing the router singleton:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Components must use the useRouter() / useRoute() composables. ',
          'Only the wiring layer (core/router, core/api) may import the ',
          'singleton instance. See chunk-6.5 acceptance criterion (mirrored ',
          'path-(b) for admin in chunk 7.4).',
        ].join('\n'),
      )
    }
  })
})
