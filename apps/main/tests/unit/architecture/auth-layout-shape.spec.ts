/**
 * Source-inspection regression test (chunk 6.6 plan rule, applying
 * the chunk-6.4 "exclusion + guard pattern" — PROJECT-WORKFLOW.md § 5):
 *
 *   `AuthLayout.vue` is excluded from the runtime coverage gate (see
 *   `vitest.config.ts` — v8 cannot anchor function coverage on a
 *   `<script setup>` SFC that has no user-defined functions in its
 *   setup block). The exclusion is safe ONLY as long as the file
 *   stays a pure structural shell (brand mark + locale switcher +
 *   slot). Anything substantive must be extracted to a sibling
 *   `*.ts` helper so it CAN be unit-tested.
 *
 * Guards enforced here:
 *   1. The file stays under 80 source lines (size guard) — a hard
 *      ceiling the layout is unlikely to legitimately need to exceed.
 *   2. The `<script setup>` block contains no `function` / `=> {`
 *      bodies of more than two statements — anything larger has to
 *      live in a tested .ts helper instead.
 *
 * Both guards are intentionally conservative: they fail loudly on a
 * future refactor that quietly inflates the layout into something
 * that should have its own tests.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LAYOUT_PATH = path.resolve(__dirname, '../../../src/modules/auth/layouts/AuthLayout.vue')

/**
 * Chunk 8.2 raised the ceiling from 80 → 96 to absorb the
 * `<ThemeToggle />` import + the wrapper `<div>` that pairs it with
 * the existing locale switcher in the layout's header. The toggle
 * itself is a sibling component (`@/components/ThemeToggle.vue`) with
 * its own 100% Vitest spec — the layout remains a structural shell
 * (no business logic in the `<script setup>` block; mounting the
 * toggle is a single-import + single-tag-invocation change).
 *
 * The chunk-6.6 "structural shell" intent still holds: substantive
 * logic must live in sibling `*.ts` helpers OR in sibling components
 * with their own coverage. The expanded docblock comment explaining
 * the toggle integration accounts for most of the additional lines.
 *
 * Future raises require a new chunk-scoped code-review note here.
 */
const MAX_LINES = 96

describe('AuthLayout.vue stays a pure structural shell', () => {
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
    // No `function` keyword usage — every function must live in a
    // sibling .ts file for coverage.
    expect(script).not.toMatch(/\bfunction\s+\w+\s*\(/)
    // No multi-statement arrow functions (3+ statements). Single-line
    // arrows for trivial mappers / event handlers are fine because
    // they cannot meaningfully drift; anything bigger goes to a .ts
    // helper.
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
