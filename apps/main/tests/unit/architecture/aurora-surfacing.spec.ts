/**
 * Source-inspection regression test (Sprint 3.5 Chunk 4, Workstream A —
 * standing standard 5.1).
 *
 * Aurora is the Catalyst Engine v2 brand accent. Per Decision D7 it is a UTILITY
 * value consumed via `var(--brand-aurora-gradient)`, NEVER registered in a
 * Vuetify `theme.colors` slot (that invariant is pinned separately by
 * `color-system-parity.spec.ts` invariant 3). Chunk 4 applies it as THIN
 * CHROME ACCENTS on the persistent brand surfaces below.
 *
 * This test locks two things per surface:
 *   1. The aurora accent is PRESENT — each surface references
 *      `var(--brand-aurora-gradient)`. Removing the accent (or fat-fingering
 *      the var name) fails CI, so the brand moment can't silently regress.
 *   2. The accent consumes the VAR, not a raw aurora hex literal
 *      (`#cd69ff` / `#7fc3ff` / `#00fff2`). `no-hard-coded-colors.spec.ts`
 *      already forbids hex in any .vue file; this is a targeted, explicit
 *      double-check that the brand accent specifically uses the token path.
 *
 * Break-revert: delete the `::before` / `::after` aurora block from any
 * listed surface → assertion (1) fails for that file.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

const AURORA_VAR = 'var(--brand-aurora-gradient)'
const RAW_AURORA_HEXES = ['#cd69ff', '#7fc3ff', '#00fff2']

/**
 * The persistent brand surfaces that carry an aurora thin-accent in this
 * SPA (main). Auth card + onboarding app-bar + creator dashboard header +
 * the agency workspace-home welcome bar (Sprint 4 Chunk 1, D-c1-9).
 */
const AURORA_SURFACES: ReadonlyArray<string> = [
  'modules/auth/layouts/AuthLayout.vue',
  'modules/onboarding/layouts/OnboardingLayout.vue',
  'modules/creators/pages/CreatorDashboardPage.vue',
  'modules/dashboard/components/WelcomeBar.vue',
]

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
