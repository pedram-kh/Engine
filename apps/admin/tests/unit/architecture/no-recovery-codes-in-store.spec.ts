/**
 * Source-inspection regression test mirroring
 * `apps/main/tests/unit/architecture/no-recovery-codes-in-store.spec.ts`
 * (chunk 6.4 plan rule, referenced from PROJECT-WORKFLOW.md § 5.1).
 *
 * Invariant:
 *
 *   Recovery codes from `enrollTotp() → confirm` and
 *   `regenerateRecoveryCodes()` are returned by the action and must
 *   NEVER live on a Pinia state field. They flow to the caller (the
 *   future sub-chunk 7.5 component), which will hold them in
 *   component-local state for one-time display, and never re-enter
 *   Pinia.
 *
 * Assertion:
 *
 *   Walk every Pinia store source under
 *   `apps/admin/src/modules/**\/stores/*.ts` and assert that no
 *   `ref<...>` declaration has a name matching
 *   `/recovery_?codes?/i`. The matching `isRegeneratingRecoveryCodes`
 *   loading flag is explicitly allowlisted — it is a boolean toggle
 *   for the action, not the codes themselves.
 *
 * The second-layer assertion (chunk 6.7) is now active for admin too:
 * `apps/admin/src/modules/auth/components/RecoveryCodesDisplay.vue`
 * (added in sub-chunk 7.5 as a structural mirror of main's component
 * rather than a shared `@catalyst/ui` extract — Group 3 deviation #D2,
 * documented in the Group 3 review) must NOT import the admin auth
 * store, even at the import statement level. The component receives
 * its codes through a prop and emits when the admin has saved them.
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

describe('apps/admin/src/modules/**/stores — no recovery codes in Pinia state', () => {
  it('finds at least the admin auth store (sanity check)', async () => {
    const stores = await findStoreFiles()
    expect(stores.some((s) => s.endsWith('useAdminAuthStore.ts'))).toBe(true)
  })

  it('contains no ref<...> field whose name matches /recovery_?codes?/i (outside the allowlist)', async () => {
    const stores = await findStoreFiles()

    const violations: string[] = []
    for (const file of stores) {
      const contents = await fs.readFile(file, 'utf8')
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

  it('RecoveryCodesDisplay.vue does NOT import useAdminAuthStore (chunk 7.5 extension)', async () => {
    const componentPath = path.resolve(
      __dirname,
      '../../../src/modules/auth/components/RecoveryCodesDisplay.vue',
    )
    const raw = await fs.readFile(componentPath, 'utf8')
    // Strip line + block comments before matching so the docblock
    // explaining the rule does not itself trip the rule.
    const stripped = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
    expect(stripped).not.toMatch(/\bimport\b[\s\S]*?\buseAdminAuthStore\b/)
    expect(stripped).not.toMatch(/\buseAdminAuthStore\s*\(/)
    expect(stripped).not.toMatch(/from\s+['"][^'"]*\/stores\/useAdminAuthStore['"]/)
  })
})
