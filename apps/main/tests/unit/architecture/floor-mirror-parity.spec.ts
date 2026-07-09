/**
 * Architecture test — creator profile FLOOR mirror parity (backend ↔ frontend).
 *
 * The six-field "profile floor" is duplicated by design:
 *
 *   - Backend:  CompletenessScoreCalculator::isProfileComplete() — the gate
 *               boolean + the `profileEarned()` floor block.
 *   - Frontend: ProfileBasicsForm.vue `floorMet` — the wizard step-2 forward
 *               gate (D2) and the CreatorProfilePage hard floor (D3).
 *
 * D1 locked the invariant: the two sides must name the SAME set of fields. A
 * break-revert proves the mirror holds today; this source-inspection spec is
 * what catches the silent one-sided edit six months from now — e.g. someone
 * adds a seventh floor field to the backend only. Then the wizard would let a
 * creator advance past step 2 (or save on the profile page) with a field the
 * backend still considers missing, stranding them at submit with no on-screen
 * cause. This is the field-edit-config-parity precedent doing its job.
 *
 * Source-inspection only — it parses both source files as text (no eval, no
 * runtime DI). The six fields are pinned ONCE in FLOOR_FIELDS below, so a
 * legitimate floor change is a one-line edit here with a self-explanatory
 * failure message rather than two magic assertions drifting apart.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..', '..', '..', '..')

const BACKEND_CALCULATOR_PATH = join(
  REPO_ROOT,
  'apps',
  'api',
  'app',
  'Modules',
  'Creators',
  'Services',
  'CompletenessScoreCalculator.php',
)

const FRONTEND_FORM_PATH = join(
  REPO_ROOT,
  'apps',
  'main',
  'src',
  'modules',
  'onboarding',
  'components',
  'ProfileBasicsForm.vue',
)

/**
 * The six-field floor, pinned ONCE. Each entry names its backend attribute
 * (as read via `$creator->…` in isProfileComplete) and its frontend reactive
 * token (as read via `….value` in floorMet). To legitimately change the floor,
 * edit this list AND both sources — the two assertions below force all three
 * to agree.
 */
const FLOOR_FIELDS = [
  { be: 'display_name', fe: 'displayName' },
  { be: 'country_code', fe: 'countryCode' },
  { be: 'region', fe: 'region' },
  { be: 'primary_language', fe: 'primaryLanguage' },
  { be: 'categories', fe: 'categories' },
  { be: 'avatar_path', fe: 'hasAvatar' },
] as const

/** Extract the first capture group of `re` from `source`, or throw with `label`. */
function extractBlock(source: string, re: RegExp, label: string): string {
  const match = re.exec(source)
  if (match === null || match[1] === undefined) {
    throw new Error(`floor-mirror-parity: could not locate ${label} — did the source shape change?`)
  }
  return match[1]
}

/** All unique matches of the single capture group of `re` within `body`. */
function uniqueTokens(body: string, re: RegExp): string[] {
  const found = new Set<string>()
  let m: RegExpExecArray | null
  while ((m = re.exec(body)) !== null) {
    if (m[1] !== undefined) found.add(m[1])
  }
  return [...found]
}

describe('creator profile floor mirror parity (D1)', () => {
  it('backend isProfileComplete() reads EXACTLY the six floor fields', () => {
    const php = readFileSync(BACKEND_CALCULATOR_PATH, 'utf-8')
    const body = extractBlock(
      php,
      /private function isProfileComplete\(Creator \$creator\): bool\s*\{([\s\S]*?)\n {4}\}/,
      'isProfileComplete() body',
    )
    const referenced = uniqueTokens(body, /\$creator->(\w+)/g)
    const expected = FLOOR_FIELDS.map((f) => f.be)

    expect(referenced.sort()).toEqual([...expected].sort())
  })

  it('frontend floorMet reads EXACTLY the six mirror fields', () => {
    const vue = readFileSync(FRONTEND_FORM_PATH, 'utf-8')
    const body = extractBlock(
      vue,
      /const floorMet = computed\(([\s\S]*?)\n\)/,
      'floorMet computed body',
    )
    const referenced = uniqueTokens(body, /(\w+)\.value/g)
    const expected = FLOOR_FIELDS.map((f) => f.fe)

    expect(referenced.sort()).toEqual([...expected].sort())
  })
})
