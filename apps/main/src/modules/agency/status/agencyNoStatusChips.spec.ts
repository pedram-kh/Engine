/**
 * AH-114 (L4) — the agency pages draw a status as `AgencyStatusPill`, never as a Vuetify chip.
 *
 * Reads the agency-side page and component sources and fails on a `<v-chip>` that is a tonal or
 * flat colour chip. Filter chips (`filter` + `outlined`, inside a `v-chip-group`), outlined tag
 * chips and plain text chips are not statuses and pass. The campaign board and the availability
 * calendar keep their own palette (plan: "keep the board palette") and are not read here.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

const MODULES = join(__dirname, '..', '..')
const AGENCY_DIRS = [
  'agency-users',
  'brands',
  'campaigns',
  'creator-invitations',
  'dashboard',
  'discover',
  'files',
  'pools',
  'roster',
  'settings',
]
/** Calendar event bars: an availability marker, not a status. */
const EXEMPT = ['roster/components/AgencyAvailabilityCalendar.vue']

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) return vueFiles(full)
    return name.endsWith('.vue') ? [full] : []
  })
}

describe('agency pages draw status as a pill, not a colour chip', () => {
  it('no tonal or flat v-chip in the agency-side sources', () => {
    const offenders: string[] = []
    for (const dir of AGENCY_DIRS) {
      for (const file of vueFiles(join(MODULES, dir))) {
        const rel = relative(MODULES, file)
        if (EXEMPT.includes(rel)) continue
        const source = readFileSync(file, 'utf8')
        for (const match of source.matchAll(/<v-chip(?![-\w])([^>]*)>/g)) {
          const attrs = match[1] ?? ''
          if (/variant="(tonal|flat)"/.test(attrs)) offenders.push(rel)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
