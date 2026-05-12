/**
 * Source-inspection regression test — mirror of
 * `apps/main/tests/unit/architecture/auth-layout-shape.spec.ts`
 * (chunk 6.6 plan rule). Same invariant, same ceiling.
 *
 *   `AuthLayout.vue` is excluded from the runtime coverage gate (v8
 *   cannot anchor function coverage on a `<script setup>` SFC that
 *   has no user-defined functions in its setup block). The exclusion
 *   is safe ONLY as long as the file stays a pure structural shell.
 *   Anything substantive must be extracted to a sibling `*.ts` helper
 *   (see `localeOptions.ts` for the precedent — and its 100% spec).
 *
 * Guards enforced here:
 *   1. The file stays under 80 source lines (size guard).
 *   2. The `<script setup>` block contains no `function` declarations
 *      and no multi-statement arrow functions.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LAYOUT_PATH = path.resolve(__dirname, '../../../src/modules/auth/layouts/AuthLayout.vue')

const MAX_LINES = 80

describe('AuthLayout.vue (admin) stays a pure structural shell', () => {
  it('is at most MAX_LINES lines (size guard)', async () => {
    const contents = await fs.readFile(LAYOUT_PATH, 'utf8')
    const lineCount = contents.split('\n').length
    if (lineCount > MAX_LINES) {
      throw new Error(
        [
          `AuthLayout.vue has grown to ${lineCount} lines (> ${MAX_LINES}).`,
          'Move the new logic into a sibling .ts helper that can be unit-tested',
          'in isolation — see localeOptions.ts for the precedent.',
          'Then update MAX_LINES in this file with a code-review',
          'note explaining why the higher ceiling is justified.',
        ].join(' '),
      )
    }
    expect(lineCount).toBeLessThanOrEqual(MAX_LINES)
  })

  it('contains no function declarations in the <script setup> block', async () => {
    const contents = await fs.readFile(LAYOUT_PATH, 'utf8')
    const scriptMatch = contents.match(/<script setup[^>]*>([\s\S]*?)<\/script>/)
    expect(scriptMatch).not.toBeNull()
    const script = scriptMatch?.[1] ?? ''
    expect(script).not.toMatch(/\bfunction\s+\w+\s*\(/)
    const arrowBlocks = script.matchAll(/=>\s*\{([\s\S]*?)\}/g)
    for (const m of arrowBlocks) {
      const body = m[1] ?? ''
      const semicolonCount = (body.match(/;/g) ?? []).length
      if (semicolonCount > 2) {
        throw new Error(
          'AuthLayout.vue contains a multi-statement arrow function. ' +
            'Move it to a sibling .ts helper to make coverage visible.',
        )
      }
    }
  })
})
