/**
 * AH-104c C2-4: admin screens read a creator's name through ONE helper.
 *
 * An erased creator keeps its row, but its name is gone and its email is a placeholder. Every
 * admin payload that carries a creator also carries `is_erased`, and `core/creators/creatorLabel.ts`
 * turns that into "Deleted creator". If a module reads `.display_name` straight off a payload it
 * bypasses the label (and the placeholder-email refusal), so this spec fails when any admin
 * module does. The helper itself is the only allowed reader.
 *
 * Allowed non-reads: a table column key (`'attributes.display_name'`, `#item.attributes.display_name`)
 * and an i18n key (`...fields.display_name`). They name a column or a string, not a value.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')

const HELPER = path.join(SRC, 'core/creators/creatorLabel.ts')

const READ = /\.display_name\b/u

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry)
    if (statSync(full).isDirectory()) walk(full, out)
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

/** Remove the two forms that name `display_name` without reading a value. */
export function stripNonReads(source: string): string {
  return source
    .replace(/(['"]|#item\.)attributes\.display_name/gu, '$1COLUMN')
    .replace(/\bfields\.display_name\b/gu, 'fields.I18N_KEY')
}

describe('an admin creator name has one reader', () => {
  const files = walk(path.join(SRC, 'modules'))

  it('walks a non-trivial set of files (non-vacuity)', () => {
    expect(files.length).toBeGreaterThan(40)
  })

  it('strips comments and non-reads but keeps real reads (the stripper itself is pinned)', () => {
    const scan = (s: string) => READ.test(stripNonReads(stripComments(s)))
    expect(scan('// creator.display_name\nconst a = 1')).toBe(false)
    expect(scan('/* c.display_name */ const a = 1')).toBe(false)
    expect(scan('<!-- c.display_name --><p/>')).toBe(false)
    expect(scan("{ key: 'attributes.display_name' }")).toBe(false)
    expect(scan('<template #item.attributes.display_name="{ item }">')).toBe(false)
    expect(scan("labelKey: 'admin.creators.detail.fields.display_name'")).toBe(false)
    expect(scan('const a = creator.display_name')).toBe(true)
    expect(scan('const a = item.attributes.display_name')).toBe(true)
    expect(scan('const a = creator?.display_name')).toBe(true)
    expect(scan('{{ row.attributes.display_name }}')).toBe(true)
  })

  it('no admin module reads display_name in code', () => {
    const offenders = files
      .filter((f) => READ.test(stripNonReads(stripComments(readFileSync(f, 'utf8')))))
      .map((f) => path.relative(SRC, f))
    expect(offenders).toEqual([])
  })

  it('the helper still reads the field (the allow-list is not stale)', () => {
    expect(stripComments(readFileSync(HELPER, 'utf8'))).toMatch(/display_name/u)
  })
})
