// @vitest-environment node

/**
 * `setFeatureFlag` (Playwright fixture) switches a Pennant flag through the API
 * under test: `POST` turns it on, `DELETE` turns it off, at
 * `/api/v1/_test/feature-flag/{name}`, (the helper token rides on the Playwright `request` fixture's `extraHTTPHeaders`, set once in `playwright.config.ts`).
 *
 * The first version shelled out to `php artisan tinker` and refused unless
 * `DB_DATABASE` contained "e2e"; CI's throwaway database is named `catalyst`, so
 * that guard refused there. The route removes the shell-out. The allow-list of
 * flag names is the SERVER's (pinned by `SetFeatureFlagTest` in the API suite).
 *
 * Behavioural: a fake `APIRequestContext` records the call, so a wrapper that
 * sends the wrong verb or URL, or swallows a failure, fails by name.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it, vi } from 'vitest'

import { setFeatureFlag } from '../../../playwright/fixtures/test-helpers'

interface FakeResponse {
  status: () => number
  text: () => Promise<string>
}

function fakeRequest(status: number, body = '{}') {
  const response: FakeResponse = { status: () => status, text: async () => body }
  const post = vi.fn(async () => response)
  const del = vi.fn(async () => response)
  return {
    post,
    delete: del,
    asContext: { post, delete: del } as unknown as Parameters<typeof setFeatureFlag>[0],
  }
}

describe('setFeatureFlag', () => {
  it('turns a flag ON with a POST to the flag route', async () => {
    const fake = fakeRequest(200)

    await setFeatureFlag(fake.asContext, 'account_deletion_enabled', true)

    expect(fake.delete).not.toHaveBeenCalled()
    expect(fake.post).toHaveBeenCalledTimes(1)
    const [url] = fake.post.mock.calls[0] as unknown as [string]
    expect(url).toBe('http://127.0.0.1:8000/api/v1/_test/feature-flag/account_deletion_enabled')
  })

  it('turns a flag OFF with a DELETE to the same route', async () => {
    const fake = fakeRequest(200)

    await setFeatureFlag(fake.asContext, 'account_deletion_enabled', false)

    expect(fake.post).not.toHaveBeenCalled()
    expect(fake.delete).toHaveBeenCalledTimes(1)
    const [url] = fake.delete.mock.calls[0] as unknown as [string]
    expect(url).toBe('http://127.0.0.1:8000/api/v1/_test/feature-flag/account_deletion_enabled')
  })

  it('encodes the flag name into the path (nothing but a path segment reaches the URL)', async () => {
    const fake = fakeRequest(200)

    await setFeatureFlag(fake.asContext, 'a/b?c=d', true)

    const [url] = fake.post.mock.calls[0] as unknown as [string]
    expect(url).toBe('http://127.0.0.1:8000/api/v1/_test/feature-flag/a%2Fb%3Fc%3Dd')
  })

  it.each([404, 422, 500])(
    'throws, naming the flag and the status, on a %i answer',
    async (status) => {
      const fake = fakeRequest(status, 'nope')

      await expect(
        setFeatureFlag(fake.asContext, 'account_deletion_enabled', true),
      ).rejects.toThrow(new RegExp(`account_deletion_enabled.*${status}.*nope`))
    },
  )

  it('does not shell out: the fixture spawns no process', () => {
    const source = readFileSync(
      resolve(__dirname, '../../../playwright/fixtures/test-helpers.ts'),
      'utf8',
    )

    expect(source).not.toMatch(/execFileSync|execSync|spawnSync|from 'node:child_process'/)
  })
})
