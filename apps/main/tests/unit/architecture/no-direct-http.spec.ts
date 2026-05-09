/**
 * Source-inspection regression test (continuing standard 5.1):
 * `apps/main/src/` MUST NOT import `axios` or call `fetch` directly.
 * Every API call goes through `@catalyst/api-client`, which centralises
 * Sanctum SPA cookie auth, CSRF preflight, and `ApiError` normalization
 * (`docs/02-CONVENTIONS.md § 3.6`, `docs/04-API-DESIGN.md § 4`).
 *
 * If you legitimately need a different transport (e.g. a Server-Sent
 * Events stream), add it to the `@catalyst/api-client` package — do NOT
 * special-case the rule here.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

const FORBIDDEN_PATTERNS: ReadonlyArray<{ pattern: RegExp; description: string }> = [
  {
    pattern: /\bfrom\s+['"]axios['"]/,
    description: 'import from "axios"',
  },
  {
    pattern: /\brequire\s*\(\s*['"]axios['"]\s*\)/,
    description: 'require("axios")',
  },
  {
    pattern: /\bimport\s*\(\s*['"]axios['"]\s*\)/,
    description: 'dynamic import("axios")',
  },
  {
    pattern: /\bfetch\s*\(/,
    description: 'fetch( call',
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

describe('apps/main/src — no direct HTTP transport (api-client only)', () => {
  it('contains no axios imports or fetch calls anywhere under src/', async () => {
    const files = await walk(SRC_ROOT)

    expect(files.length).toBeGreaterThan(0)

    const violations: string[] = []
    for (const file of files) {
      const contents = await fs.readFile(file, 'utf8')
      for (const { pattern, description } of FORBIDDEN_PATTERNS) {
        if (pattern.test(contents)) {
          violations.push(`${path.relative(SRC_ROOT, file)} — disallowed ${description}`)
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found direct HTTP transport in apps/main/src/:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Every API call must route through @catalyst/api-client. ',
          'See docs/02-CONVENTIONS.md § 3.6 + chunk-6.2 review priority #8.',
        ].join('\n'),
      )
    }
  })
})
