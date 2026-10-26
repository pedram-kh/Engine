// @vitest-environment node

/**
 * `setFeatureFlag` (Playwright fixture) flips a Pennant flag by running
 * `php artisan tinker` against the E2E database. That is a write to a database
 * from a test helper, and this repo has wiped a developer's real database twice
 * from E2E tooling (see `e2e-db-isolation.spec.ts`). So the helper must refuse,
 * before it spawns anything, to run unless the database name says it is an E2E
 * one, and must not let anything but a flag name reach the PHP string.
 *
 * Behavioural, not source-inspection: the refusals are exercised by calling the
 * function with a hostile environment. `execFileSync` is mocked so that a test
 * which wrongly gets past a guard fails by name instead of touching a database.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const exec = vi.hoisted(() => vi.fn())
vi.mock('node:child_process', async (importOriginal) => {
  const actual = await importOriginal<typeof import('node:child_process')>()
  return { ...actual, default: { ...actual, execFileSync: exec }, execFileSync: exec }
})

import { setFeatureFlag } from '../../../playwright/fixtures/test-helpers'

describe('setFeatureFlag — E2E database guard', () => {
  const original = process.env.DB_DATABASE

  beforeEach(() => {
    exec.mockReset()
  })

  afterEach(() => {
    if (original === undefined) {
      delete process.env.DB_DATABASE
    } else {
      process.env.DB_DATABASE = original
    }
  })

  it.each(['catalyst', 'catalyst_dev', 'postgres', 'production'])(
    'refuses DB_DATABASE=%s and spawns nothing',
    (database) => {
      process.env.DB_DATABASE = database

      expect(() => setFeatureFlag('account_deletion_enabled', true)).toThrow(/e2e/i)
      expect(exec).not.toHaveBeenCalled()
    },
  )

  it.each(['', 'x; DROP TABLE users', "a');die();('", 'Account', '1flag', 'a-b', 'a b'])(
    'refuses the flag name %j and spawns nothing',
    (flag) => {
      process.env.DB_DATABASE = 'catalyst_e2e'

      expect(() => setFeatureFlag(flag, true)).toThrow(/not a flag name/)
      expect(exec).not.toHaveBeenCalled()
    },
  )

  it('with the E2E database, runs artisan once, pinned to that database', () => {
    process.env.DB_DATABASE = 'catalyst_e2e'

    setFeatureFlag('account_deletion_enabled', true)

    expect(exec).toHaveBeenCalledTimes(1)
    const [command, args, options] = exec.mock.calls[0] as [
      string,
      string[],
      { cwd: string; env: Record<string, string> },
    ]
    expect(command).toBe('php')
    expect(args.slice(0, 2)).toEqual(['artisan', 'tinker'])
    expect(args[2]).toBe(
      "--execute=\\Laravel\\Pennant\\Feature::activate('account_deletion_enabled');",
    )
    expect(options.cwd).toMatch(/apps\/api$/)
    expect(options.env.DB_DATABASE).toBe('catalyst_e2e')
    expect(options.env.APP_ENV).toBe('local')
  })

  it('switches off with deactivate, never with a different call', () => {
    process.env.DB_DATABASE = 'catalyst_e2e'

    setFeatureFlag('account_deletion_enabled', false)

    const args = exec.mock.calls[0]?.[1] as string[]
    expect(args[2]).toContain('Feature::deactivate(')
    expect(args[2]).not.toContain('Feature::activate(')
  })

  it('with no DB_DATABASE set, falls back to catalyst_e2e (the config default), not the .env database', () => {
    delete process.env.DB_DATABASE

    setFeatureFlag('account_deletion_enabled', true)

    const options = exec.mock.calls[0]?.[2] as { env: Record<string, string> }
    expect(options.env.DB_DATABASE).toBe('catalyst_e2e')
  })
})
