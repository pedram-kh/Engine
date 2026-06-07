/**
 * Unit tests for the deploy-env resolver behind the persistent env
 * banner (Sprint 13, D-2). The resolver is a pure function so the
 * mapping matrix — including the safe `local` fallback for unknown /
 * unset values — is exercised without a Vite build.
 */

import { describe, expect, it } from 'vitest'

import { deployEnvBanner, resolveDeployEnv } from './useDeployEnv'

describe('resolveDeployEnv', () => {
  it.each(['local', 'staging', 'production'] as const)('passes through the known env %s', (env) => {
    expect(resolveDeployEnv(env)).toBe(env)
  })

  it('falls back to local for an unset value', () => {
    expect(resolveDeployEnv(undefined)).toBe('local')
  })

  it('falls back to local for an unrecognised value (never paints a prod banner by accident)', () => {
    expect(resolveDeployEnv('prod')).toBe('local')
    expect(resolveDeployEnv('')).toBe('local')
  })
})

describe('deployEnvBanner', () => {
  it('maps local → grey', () => {
    expect(deployEnvBanner('local')).toEqual({
      env: 'local',
      color: 'grey',
      labelKey: 'app.env.local',
    })
  })

  it('maps staging → blue', () => {
    expect(deployEnvBanner('staging')).toEqual({
      env: 'staging',
      color: 'blue',
      labelKey: 'app.env.staging',
    })
  })

  it('maps production → red', () => {
    expect(deployEnvBanner('production')).toEqual({
      env: 'production',
      color: 'red',
      labelKey: 'app.env.production',
    })
  })

  it('maps an unknown value to the local banner', () => {
    expect(deployEnvBanner('???').env).toBe('local')
  })
})
