/**
 * AH-104b-del B3 — agency screens read a creator's name and avatar through ONE helper.
 *
 * A deleted creator stays on the agency's roster, threads, applications and assignments, and
 * every payload carries `is_erased`. If a component reads `display_name` / `avatar_url`
 * straight off the payload it bypasses the "Deleted creator" label and the avatar refusal in
 * `core/creators/creatorLabel.ts`. This spec fails when any agency module (or the shared
 * `components/` folder) mentions either field in code, comments excluded; the helper itself is
 * the only allowed reader.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')

const SCANNED = [
  'modules/boards',
  'modules/campaigns',
  'modules/roster',
  'modules/messaging',
  'modules/discover',
  'modules/pools',
  'modules/agency',
  'modules/agency-users',
  'modules/brands',
  'modules/dashboard',
  'components',
]

const ALLOWED = new Set([
  // The helper reads the raw fields; that is its job.
  path.join(SRC, 'core/creators/creatorLabel.ts'),
])

const FIELD = /\b(display_name|avatar_url)\b/u

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry)
    const stat = statSync(full)
    if (stat.isDirectory()) walk(full, out)
    else if (/\.(ts|vue)$/u.test(entry) && !/\.spec\.ts$/u.test(entry)) out.push(full)
  }
  return out
}

/** Remove `<!-- -->`, block and line comments so prose that names the field is not a read. */
export function stripComments(source: string): string {
  return source
    .replace(/<!--[\s\S]*?-->/gu, '')
    .replace(/\/\*[\s\S]*?\*\//gu, '')
    .replace(/(^|[^:'"`])\/\/[^\n]*/gu, '$1')
}

describe('creator name and avatar have one reader', () => {
  const files = SCANNED.flatMap((d) => walk(path.join(SRC, d)))

  it('walks a non-trivial set of files (non-vacuity)', () => {
    expect(files.length).toBeGreaterThan(80)
  })

  it('strips comments but keeps code (the stripper itself is pinned)', () => {
    expect(FIELD.test(stripComments('// display_name\nconst a = 1'))).toBe(false)
    expect(FIELD.test(stripComments('/* avatar_url */ const a = 1'))).toBe(false)
    expect(FIELD.test(stripComments('<!-- display_name --><p/>'))).toBe(false)
    expect(FIELD.test(stripComments('const a = creator.display_name'))).toBe(true)
    expect(FIELD.test(stripComments("const a = { avatar_url: 'x' } // note"))).toBe(true)
  })

  it('no agency source reads display_name or avatar_url in code', () => {
    const offenders = files
      .filter((f) => !ALLOWED.has(f))
      .filter((f) => FIELD.test(stripComments(readFileSync(f, 'utf8'))))
      .map((f) => path.relative(SRC, f))
    expect(offenders).toEqual([])
  })

  it('the helper still reads both fields (the allow-list is not stale)', () => {
    const helper = stripComments(readFileSync([...ALLOWED][0] as string, 'utf8'))
    expect(helper).toMatch(/display_name/u)
    expect(helper).toMatch(/avatar_url/u)
  })
})
