/**
 * Source-inspection regression test mirroring
 * `apps/main/tests/unit/architecture/auth-api-reexport-shape.spec.ts`
 * (chunk 6.2-6.4 change-request #3, extending standard 5.1):
 *
 *   `apps/admin/src/modules/auth/api/admin-auth.api.ts` is a pure
 *   re-export of the singleton `authApi` bound to the admin SPA's
 *   transport layer. The file is intentionally excluded from the
 *   auth-flow 100% coverage threshold (its contract is verified by
 *   typecheck alone), but bare exclusion has a known failure mode:
 *   someone adds a one-line interceptor "while they're in there,"
 *   coverage doesn't catch it, the architecture diverges silently.
 *
 * This test is the drift guard. It asserts:
 *   1. The file's significant lines (non-blank, non-comment) are all
 *      `import` or `export` statements.
 *   2. The total significant-line count stays at or below
 *      {@link MAX_NON_COMMENT_LINES}.
 *
 * If a future sprint legitimately needs the file to grow (e.g. to
 * re-export an additional symbol), bump the threshold with a code
 * review and a justification — exactly the discipline we want around
 * a coverage exclusion. Do NOT loosen the
 * `import|export`-only assertion.
 *
 * Pattern established here ("exclusion + guard") generalises to any
 * future coverage exclusion in the SPA tree.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const FILE = path.resolve(__dirname, '../../../src/modules/auth/api/admin-auth.api.ts')

/**
 * Current significant-line count is 2 (one `import`, one `export`).
 * The headroom (≤ 12) covers a future addition of a second re-export
 * symbol or a typed re-import, but NOT a runtime line.
 */
const MAX_NON_COMMENT_LINES = 12

describe('apps/admin/src/modules/auth/api/admin-auth.api.ts is a pure re-export', () => {
  it('contains no runtime logic beyond re-exports', async () => {
    const contents = await fs.readFile(FILE, 'utf8')
    const lines = contents.split('\n')

    const significantLines = lines.filter((line) => {
      const trimmed = line.trim()
      if (trimmed === '') return false
      if (trimmed.startsWith('//')) return false
      if (trimmed.startsWith('*')) return false
      if (trimmed.startsWith('/*')) return false
      if (trimmed.startsWith('*/')) return false
      return true
    })

    expect(significantLines.length).toBeLessThanOrEqual(MAX_NON_COMMENT_LINES)

    for (const line of significantLines) {
      const trimmed = line.trim()
      const isImportOrExport = /^(import|export)\b/.test(trimmed)
      expect(isImportOrExport, `disallowed runtime line: "${trimmed}"`).toBe(true)
    }
  })
})
