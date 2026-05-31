/**
 * Source-inspection regression test (Sprint 3.5 Chunk 4, Workstream A —
 * standing standard 5.1). Mirror of
 * `apps/main/tests/unit/architecture/aurora-surfacing.spec.ts`.
 *
 * Aurora is the Engine C v2 brand accent. Per Decision D7 it is a UTILITY
 * value consumed via `var(--brand-aurora-gradient)`, NEVER registered in a
 * Vuetify `theme.colors` slot (pinned separately by
 * `color-system-parity.spec.ts` invariant 3). Chunk 4 applies it as a thin
 * chrome accent on the admin SPA's one persistent brand surface — the auth
 * card (the admin SPA has no onboarding wizard or creator dashboard, so its
 * aurora surface list is the auth layout alone).
 *
 * Locks per surface: (1) the aurora accent is PRESENT (references the var,
 * so the brand moment can't silently regress); (2) it consumes the var, not
 * a raw aurora hex literal.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

const AURORA_VAR = 'var(--brand-aurora-gradient)'
const RAW_AURORA_HEXES = ['#cd69ff', '#7fc3ff', '#00fff2']

const AURORA_SURFACES: ReadonlyArray<string> = ['modules/auth/layouts/AuthLayout.vue']

describe('aurora surfacing — brand accent consumes the utility var (Chunk 4)', () => {
  it.each(AURORA_SURFACES)('%s references var(--brand-aurora-gradient)', (relative) => {
    const contents = readFileSync(path.join(SRC_ROOT, relative), 'utf8')
    expect(contents).toContain(AURORA_VAR)
  })

  it.each(AURORA_SURFACES)('%s uses the var, not a raw aurora hex', (relative) => {
    const lower = readFileSync(path.join(SRC_ROOT, relative), 'utf8').toLowerCase()
    for (const hex of RAW_AURORA_HEXES) {
      expect(lower).not.toContain(hex)
    }
  })
})
