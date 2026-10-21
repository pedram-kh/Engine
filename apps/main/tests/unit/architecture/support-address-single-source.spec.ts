/**
 * AH-104b-del P12 — the support address is typed in ONE place per runtime.
 *
 * Web: `apps/main/src/core/config/support.ts` (override: `VITE_SUPPORT_EMAIL`), plus the
 * env example. API: `config/app.php` (`SUPPORT_EMAIL`), pinned by
 * `AccountDeletionSchemaTest`. Everything else (the delete-account page, the blocked-deletion
 * copy, the mail) reads it; a second literal would be a second place to forget when the
 * address changes.
 *
 * Walks every source file of the web apps and shared packages (not node_modules / build
 * output) for the literal; the only allowed hits are the config module and the env example.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../../../..')
const LITERAL = 'support@catalyst-growth.com'

const SKIP_DIRS = new Set([
  'node_modules',
  'dist',
  'coverage',
  'build',
  '.git',
  'playwright-report',
  'test-results',
  'android',
  'ios',
])
const EXT = /\.(ts|vue|js|json|html|css)$/u

const ALLOWED = new Set([
  path.join(ROOT, 'apps/main/src/core/config/support.ts'),
  // The architecture spec itself names the literal it forbids.
  path.join(ROOT, 'apps/main/tests/unit/architecture/support-address-single-source.spec.ts'),
])

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (SKIP_DIRS.has(entry)) continue
    const full = path.join(dir, entry)
    const stat = statSync(full)
    if (stat.isDirectory()) walk(full, out)
    else if (EXT.test(entry)) out.push(full)
  }
  return out
}

describe('the support address has one home per runtime', () => {
  const roots = ['apps/main/src', 'apps/main/tests', 'apps/admin/src', 'packages'].map((r) =>
    path.join(ROOT, r),
  )
  const files = roots.flatMap((r) => walk(r))

  it('walks a non-trivial set of files (non-vacuity)', () => {
    expect(files.length).toBeGreaterThan(200)
  })

  it('the literal appears only in the config module', () => {
    const hits = files
      .filter((file) => !ALLOWED.has(file))
      .filter((file) => readFileSync(file, 'utf8').includes(LITERAL))
      .map((file) => path.relative(ROOT, file))
    expect(hits).toEqual([])
  })

  it('the config module reads VITE_SUPPORT_EMAIL, and the env example documents it', () => {
    const module_ = readFileSync(path.join(ROOT, 'apps/main/src/core/config/support.ts'), 'utf8')
    expect(module_).toContain('VITE_SUPPORT_EMAIL')
    expect(module_).toContain(LITERAL)

    const example = readFileSync(path.join(ROOT, 'apps/main/.env.example'), 'utf8')
    expect(example).toContain(`VITE_SUPPORT_EMAIL=${LITERAL}`)
  })
})
