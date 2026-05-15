/**
 * Architecture test — admin per-field edit config ↔ backend SOT parity.
 *
 * Sprint 3 Chunk 4 sub-step 9. The frontend
 * `apps/admin/src/modules/creators/config/field-edit.ts` mirrors three
 * backend constants on `AdminUpdateCreatorRequest`:
 *
 *   - `EDITABLE_FIELDS`         (the 7 fields)
 *   - `REASON_REQUIRED_FIELDS`  (the 2 sensitive fields)
 *   - `CATEGORY_ENUM`           (the 16-category set)
 *
 * The backend is the source of truth; the frontend mirrors at the
 * UX layer so admins get feedback without a 422 round-trip. Keeping
 * the two in sync is critical to that UX promise — if the backend
 * grows a new editable field and the frontend doesn't surface a row
 * for it, the admin can't edit that field at all. If reason-required
 * grows and the frontend doesn't require it, the PATCH 422s with a
 * confusing "reason required" error the UI didn't prompt for.
 *
 * This source-inspection test parses the backend PHP file (no eval)
 * and asserts the parsed PHP-side constants match the TypeScript
 * exports verbatim. Source-inspection only — no runtime DI.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

import {
  CATEGORY_KEYS,
  EDITABLE_FIELDS,
  REASON_REQUIRED_FIELDS,
} from '@/modules/creators/config/field-edit'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..', '..', '..', '..')
const BACKEND_REQUEST_PATH = join(
  REPO_ROOT,
  'apps',
  'api',
  'app',
  'Modules',
  'Creators',
  'Http',
  'Requests',
  'AdminUpdateCreatorRequest.php',
)

function parsePhpArrayConst(
  php: string,
  visibility: 'public' | 'private',
  constName: string,
): string[] {
  const re = new RegExp(`${visibility} const array ${constName} = \\[([\\s\\S]*?)\\];`, 'm')
  const match = re.exec(php)
  if (match === null) {
    throw new Error(`Could not find PHP constant ${constName} in AdminUpdateCreatorRequest.php`)
  }
  const body = match[1] ?? ''
  const items: string[] = []
  const itemRe = /'([^']+)'/g
  let m: RegExpExecArray | null
  while ((m = itemRe.exec(body)) !== null) {
    const value = m[1]
    if (value !== undefined) {
      items.push(value)
    }
  }
  return items
}

describe('admin per-field edit config parity (Sprint 3 Chunk 4 sub-step 9)', () => {
  const php = readFileSync(BACKEND_REQUEST_PATH, 'utf-8')

  it('EDITABLE_FIELDS matches AdminUpdateCreatorRequest::EDITABLE_FIELDS', () => {
    const backend = parsePhpArrayConst(php, 'public', 'EDITABLE_FIELDS')
    expect(backend).toHaveLength(7)
    expect([...EDITABLE_FIELDS].sort()).toEqual([...backend].sort())
  })

  it('REASON_REQUIRED_FIELDS matches AdminUpdateCreatorRequest::REASON_REQUIRED_FIELDS', () => {
    const backend = parsePhpArrayConst(php, 'public', 'REASON_REQUIRED_FIELDS')
    expect([...REASON_REQUIRED_FIELDS].sort()).toEqual([...backend].sort())
  })

  it('CATEGORY_KEYS matches AdminUpdateCreatorRequest::CATEGORY_ENUM', () => {
    const backend = parsePhpArrayConst(php, 'private', 'CATEGORY_ENUM')
    expect(backend).toHaveLength(16)
    expect([...CATEGORY_KEYS].sort()).toEqual([...backend].sort())
  })
})
