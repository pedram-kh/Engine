/**
 * PL-3.2a — the shot types and durations repeat what the backend enforces (`ShotType.php`, `StrategyLimits.php`). If either
 * side changes, this fails until the other follows.
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

import { SCRIPT_DURATIONS, SCRIPT_TEXT_LIMITS, SHOT_TYPES } from './scripts'

const API = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../../../apps/api/app/Modules/Strategy',
)

describe('scripts types parity with the backend', () => {
  it('SHOT_TYPES equals the cases of ShotType.php, in order', () => {
    const php = readFileSync(resolve(API, 'Enums/ShotType.php'), 'utf-8')
    const cases = [...php.matchAll(/case \w+ = '([a-z_]+)';/g)].map((m) => m[1])
    expect([...SHOT_TYPES]).toEqual(cases)
  })

  it('SCRIPT_DURATIONS equals StrategyLimits::SCRIPT_DURATIONS', () => {
    const php = readFileSync(resolve(API, 'StrategyLimits.php'), 'utf-8')
    const m = /SCRIPT_DURATIONS = \[([^\]]+)\]/.exec(php)
    expect(m).not.toBeNull()
    expect([...SCRIPT_DURATIONS]).toEqual((m?.[1] ?? '').split(',').map((n) => Number(n.trim())))
  })

  it('SCRIPT_TEXT_LIMITS equals the text limits of StrategyLimits', () => {
    const php = readFileSync(resolve(API, 'StrategyLimits.php'), 'utf-8')
    const read = (name: string): number =>
      Number(new RegExp(`const ${name} = (\\d+);`).exec(php)?.[1])
    expect({ ...SCRIPT_TEXT_LIMITS }).toEqual({
      angle: read('SCRIPT_ANGLE'),
      audio: read('HOOK_AUDIO'),
      overlay: read('HOOK_OVERLAY'),
      visual: read('HOOK_VISUAL'),
    })
  })
})
