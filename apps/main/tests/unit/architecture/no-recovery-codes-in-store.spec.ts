/**
 * Source-inspection regression test (chunk 6.4 plan rule, referenced
 * from PROJECT-WORKFLOW.md § 5.1, EXTENDED in chunk 6.7):
 *
 *   Recovery codes from `enrollTotp() → confirm` and
 *   `regenerateRecoveryCodes()` are returned by the action and must
 *   NEVER live on a Pinia state field. They flow to the caller (the
 *   chunk-6.7 component), which holds them in component-local state
 *   for one-time display, and never re-enter Pinia.
 *
 * Two assertions, layered:
 *
 *   1. (chunk 6.4) Walk every Pinia store source under
 *      `apps/main/src/modules/**\/stores/*.ts` and assert that no
 *      `ref<...>` declaration has a name matching
 *      `/recovery_?codes?/i`. This catches the obvious shape
 *      (`const recoveryCodes = ref<...>()`) AND the underscore
 *      variant (`const recovery_codes = ref(...)`).
 *
 *   2. (chunk 6.7) Assert that
 *      `apps/main/src/modules/auth/components/RecoveryCodesDisplay.vue`
 *      contains NO `import { useAuthStore } from '...'` line at all
 *      — even importing the store risks a future refactor piping the
 *      codes back into Pinia state. The display component receives
 *      its codes via a prop and emits when the user has saved them.
 *
 * If a future store legitimately needs to reference recovery codes
 * for some non-display reason (audit, telemetry, etc.) — extend this
 * test with an explicit allowlist AND document the rationale here.
 * Do NOT loosen the regex to silence the test.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const MODULES_ROOT = path.resolve(__dirname, '../../../src/modules')

/**
 * Match identifier names that look like recovery-code state. The
 * pattern matches anywhere in the identifier so it catches the obvious
 * shape (`recoveryCodes`, `recovery_codes`, `recoveryCode`) and any
 * camel/snake variant.
 *
 * Examples this catches:
 *   - `recoveryCodes`
 *   - `recovery_codes`
 *   - `recoveryCode`
 *
 * Examples this deliberately does NOT catch (via the allowlist below):
 *   - `isRegeneratingRecoveryCodes` — a loading flag for the action,
 *     not the codes themselves.
 *
 * Wire-shape literals like `recovery_codes` inside response types are
 * not caught because the test only inspects `ref(...)` declarations.
 */
const FORBIDDEN_NAME_PATTERN = /recovery_?codes?/i

/**
 * Conservative allowlist of identifier names that legitimately
 * contain "recovery" but are NOT recovery-code state fields. Every
 * entry here requires a code review.
 */
const ALLOWED_REF_NAMES: ReadonlySet<string> = new Set(['isRegeneratingRecoveryCodes'])

async function walkTsFiles(directory: string): Promise<string[]> {
  const out: string[] = []
  const entries = await fs.readdir(directory, { withFileTypes: true })
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      out.push(...(await walkTsFiles(absolute)))
    } else if (entry.isFile() && entry.name.endsWith('.ts') && !entry.name.endsWith('.spec.ts')) {
      out.push(absolute)
    }
  }
  return out
}

async function findStoreFiles(): Promise<string[]> {
  const all = await walkTsFiles(MODULES_ROOT)
  return all.filter((p) => p.includes(`${path.sep}stores${path.sep}`))
}

describe('apps/main/src/modules/**/stores — no recovery codes in Pinia state', () => {
  it('finds at least the auth store (sanity check)', async () => {
    const stores = await findStoreFiles()
    expect(stores.some((s) => s.endsWith('useAuthStore.ts'))).toBe(true)
  })

  it('contains no ref<...> field whose name matches /recovery_?codes?/i (outside the allowlist)', async () => {
    const stores = await findStoreFiles()

    const violations: string[] = []
    for (const file of stores) {
      const contents = await fs.readFile(file, 'utf8')
      // Iterate over every `const X = ref(...)` and check the name.
      const refDeclPattern = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*ref\b/g
      for (const match of contents.matchAll(refDeclPattern)) {
        const name = match[1] ?? ''
        if (FORBIDDEN_NAME_PATTERN.test(name) && !ALLOWED_REF_NAMES.has(name)) {
          violations.push(`${path.relative(MODULES_ROOT, file)} — disallowed ref \`${name}\``)
        }
      }
    }

    if (violations.length > 0) {
      throw new Error(
        [
          'Found Pinia state fields that look like recovery-code storage:',
          ...violations.map((v) => `  - ${v}`),
          '',
          'Recovery codes must NEVER enter Pinia state — they flow ' +
            'through the action return value to the caller for one-time ' +
            'display only. See chunk 6.4 plan + PROJECT-WORKFLOW.md § 5.1.',
        ].join('\n'),
      )
    }
  })

  it('RecoveryCodesDisplay.vue does NOT import useAuthStore (chunk 6.7 extension)', async () => {
    const componentPath = path.resolve(
      __dirname,
      '../../../src/modules/auth/components/RecoveryCodesDisplay.vue',
    )
    const raw = await fs.readFile(componentPath, 'utf8')
    // Strip line + block comments before matching so the docblock
    // explaining the rule does not itself trip the rule.
    const stripped = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
    // Forbid any actual import or call of useAuthStore. The component
    // receives its codes via a prop; touching the store at all is a
    // bug.
    expect(stripped).not.toMatch(/\bimport\b[\s\S]*?\buseAuthStore\b/)
    expect(stripped).not.toMatch(/\buseAuthStore\s*\(/)
    expect(stripped).not.toMatch(/from\s+['"][^'"]*\/stores\/useAuthStore['"]/)
  })
})
