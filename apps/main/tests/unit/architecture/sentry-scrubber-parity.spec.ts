/**
 * AH-121 C2 (sentry-plan.md B2, Q7): the scrubber is mirrored per SPA and must not
 * drift. The scrubber, the client wiring, the build helper and the `.map` check are
 * pinned byte-for-byte between `apps/main` and `apps/admin`, and the rule tables
 * (the values the scrubber actually runs) are compared structurally as well, so a
 * rule added to one SPA fails here until it is added to the other.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { SCRUB_RULES as MAIN_RULES } from '@/core/observability/sentryScrubber'

const APPS = path.resolve(__dirname, '../../../..')
const read = (app: string, rel: string): string => readFileSync(path.join(APPS, app, rel), 'utf8')

const MIRRORED = [
  'src/core/observability/sentryScrubber.ts',
  'src/core/observability/sentryClient.ts',
  'tools/sentryBuild.ts',
  'scripts/check-no-sourcemaps.mjs',
  'scripts/check-no-sourcemaps.d.mts',
  'tests/unit/helpers/sentryRecorder.ts',
  'tests/unit/core/observability/sentryScrubber.spec.ts',
  'tests/unit/core/observability/sentryClient.spec.ts',
  'tests/unit/core/observability/sentryBuild.spec.ts',
]

describe('main and admin Sentry files are mirrored', () => {
  for (const rel of MIRRORED) {
    it(`${rel} is byte-identical in apps/main and apps/admin`, () => {
      expect(read('admin', rel)).toBe(read('main', rel))
    })
  }

  it('the rule tables are equal (loaded from both scrubbers)', async () => {
    const adminModule = (await import(
      /* @vite-ignore */ path.join(APPS, 'admin/src/core/observability/sentryScrubber.ts')
    )) as { SCRUB_RULES: typeof MAIN_RULES }
    expect(adminModule.SCRUB_RULES).toEqual(MAIN_RULES)
  })

  it('the rule table is not empty (a parity check over nothing proves nothing)', () => {
    expect(Object.keys(MAIN_RULES.text)).toEqual([
      'urlQuery',
      'bearer',
      'sanctum',
      'secretPair',
      'email',
      'opaque',
    ])
    expect(Object.keys(MAIN_RULES.sentryIdShapes)).toEqual([
      'event_id',
      'trace_id',
      'span_id',
      'parent_span_id',
      'debug_id',
    ])
    expect(MAIN_RULES.requestKeep).toEqual(['url', 'method'])
  })
})
