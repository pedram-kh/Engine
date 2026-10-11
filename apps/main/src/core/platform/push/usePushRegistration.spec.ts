import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import { PUSH_CHANNEL_ID, usePushRegistration } from './usePushRegistration'

describe('usePushRegistration() — the real wiring (under jsdom: no Capacitor shell)', () => {
  it('the Android channel id is the one the SERVER sets on every message', () => {
    // Read from source: a client channel the server never targets would make
    // every push land on a channel the creator cannot find in system settings.
    const php = readFileSync(
      resolve(
        __dirname,
        '../../../../../../apps/api/app/Modules/Notifications/Push/PushMessage.php',
      ),
      'utf8',
    )
    const serverId = /const string DEFAULT_CHANNEL = '([a-z_]+)'/.exec(php)?.[1]

    expect(serverId).toBe('catalyst_default')
    expect(PUSH_CHANNEL_ID).toBe(serverId)
  })

  it('is one shared instance, and reads "unsupported" off-native', () => {
    const first = usePushRegistration()

    expect(usePushRegistration()).toBe(first)
    expect(first.permission.value).toBe('unsupported')
  })
})
