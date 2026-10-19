import { createMemoryHistory } from 'vue-router'
import { beforeAll, describe, expect, it, vi } from 'vitest'

import {
  APP_LINK_DASHBOARD_ROUTE,
  APP_LINK_HOST,
  APP_LINK_PATH_PREFIX,
  parseAppLink,
  resolveAppLink,
} from './appLink'

import { createRouter } from '@/core/router'

const ULID = '01J9ZK3Q8M5T7WXYABCDEFGHJK'
const HOST = `https://${APP_LINK_HOST}`

const router = createRouter(() => createMemoryHistory())

beforeAll(() => {
  // `router.resolve` warns for a path no route matches; that is the case under test, not noise to read.
  vi.spyOn(console, 'warn').mockImplementation(() => undefined)
})

describe('the constants (R1, R2)', () => {
  it('pins the confirmed production host and the creator-only scope', () => {
    expect(APP_LINK_HOST).toBe('platform.catalyst-growth.com')
    expect(APP_LINK_PATH_PREFIX).toBe('/creator/')
    expect(APP_LINK_DASHBOARD_ROUTE).toBe('creator.dashboard')
  })
})

describe('parseAppLink — what is OUT of scope (ignored, never navigated)', () => {
  const outOfScope: Array<[string, unknown]> = [
    ['http, not https', `http://${APP_LINK_HOST}/creator/jobs/${ULID}`],
    ['the custom capacitor scheme', 'capacitor://localhost/creator/dashboard'],
    ['a javascript: URL', 'javascript:alert(1)'],
    ['another host', `https://example.com/creator/jobs/${ULID}`],
    ['the API host', 'https://platform-api.catalyst-growth.com/creator/dashboard'],
    ['the admin host', 'https://admin.catalyst-growth.com/creator/dashboard'],
    [
      'a look-alike with our host as a prefix',
      `https://${APP_LINK_HOST}.evil.test/creator/jobs/${ULID}`,
    ],
    ['a look-alike with our host as a suffix', `https://evil${APP_LINK_HOST}/creator/jobs/${ULID}`],
    ['a sub-domain of our host', `https://x.${APP_LINK_HOST}/creator/jobs/${ULID}`],
    ['our host as userinfo', `https://${APP_LINK_HOST}@evil.test/creator/jobs/${ULID}`],
    ['credentials on our host', `https://user:pw@${APP_LINK_HOST}/creator/jobs/${ULID}`],
    ['a username on our host', `https://user@${APP_LINK_HOST}/creator/jobs/${ULID}`],
    ['an explicit port', `https://${APP_LINK_HOST}:8443/creator/jobs/${ULID}`],
    ['a trailing-dot host', `https://${APP_LINK_HOST}./creator/jobs/${ULID}`],
    ['the verify-email token link', `${HOST}/auth/verify-email?token=abc`],
    ['the reset-password link', `${HOST}/auth/reset-password?token=abc&email=a%40b.c`],
    ['the accept-invite link', `${HOST}/auth/accept-invite?token=abc`],
    ['an agency campaign path', `${HOST}/campaigns/${ULID}`],
    ['an agency messages path', `${HOST}/messages/${ULID}`],
    ['the agency roster', `${HOST}/roster`],
    ['the root', `${HOST}/`],
    ['sign-in', `${HOST}/sign-in`],
    ['the bare host', HOST],
    ['/creator with no trailing slash', `${HOST}/creator`],
    ['/creatorfoo (a prefix match is not a scope match)', `${HOST}/creatorfoo/jobs/${ULID}`],
    ['a dot-segment escape', `${HOST}/creator/../auth/verify-email`],
    ['an encoded dot-segment escape', `${HOST}/creator/%2e%2e/auth/verify-email`],
    ['an upper-case encoded dot-segment escape', `${HOST}/creator/%2E%2E/%2E%2E/roster`],
    ['garbage', 'not a url'],
    ['the empty string', ''],
    ['null', null],
    ['undefined', undefined],
    ['a number', 42],
    ['an object', { url: `${HOST}/creator/dashboard` }],
    ['a URL longer than 2048 characters', `${HOST}/creator/jobs/${'a'.repeat(2100)}`],
  ]

  it.each(outOfScope)('%s', (_label, url) => {
    expect(parseAppLink(url)).toEqual({ kind: 'out-of-scope' })
  })

  it('treats the host case-insensitively (URL parsers lower-case it)', () => {
    expect(parseAppLink(`https://PLATFORM.Catalyst-Growth.COM/creator/jobs/${ULID}`)).toEqual({
      kind: 'in-scope',
      path: `/creator/jobs/${ULID}`,
      query: {},
    })
  })
})

describe('parseAppLink — IN scope', () => {
  it('returns the normalised path and no query for a plain creator link', () => {
    expect(parseAppLink(`${HOST}/creator/jobs/${ULID}`)).toEqual({
      kind: 'in-scope',
      path: `/creator/jobs/${ULID}`,
      query: {},
    })
  })

  it('accepts a trailing slash and a nested creator path', () => {
    expect(parseAppLink(`${HOST}/creator/notifications/preferences/`)).toMatchObject({
      path: '/creator/notifications/preferences/',
    })
  })

  it('drops the fragment', () => {
    expect(parseAppLink(`${HOST}/creator/jobs/${ULID}#evil`)).toMatchObject({
      path: `/creator/jobs/${ULID}`,
    })
  })

  describe('the query allow-list', () => {
    it('keeps focus=messages', () => {
      expect(parseAppLink(`${HOST}/creator/assignments/${ULID}?focus=messages`)).toMatchObject({
        query: { focus: 'messages' },
      })
    })

    it.each([
      ['an unknown value for a known key', '?focus=other'],
      ['an empty value', '?focus='],
      ['an agency-only key', '?tab=drafts'],
      ['the sign-in redirect key', '?redirect=%2Fcampaigns%2F1'],
      ['an arbitrary key', '?utm_source=email&x=1'],
      ['a script-shaped value', '?focus=%3Cscript%3E'],
    ])('drops %s', (_label, qs) => {
      expect(parseAppLink(`${HOST}/creator/assignments/${ULID}${qs}`)).toMatchObject({
        path: `/creator/assignments/${ULID}`,
        query: {},
      })
    })

    it('uses the first of a repeated key', () => {
      expect(
        parseAppLink(`${HOST}/creator/assignments/${ULID}?focus=bogus&focus=messages`),
      ).toMatchObject({ query: {} })
      expect(
        parseAppLink(`${HOST}/creator/assignments/${ULID}?focus=messages&focus=bogus`),
      ).toMatchObject({ query: { focus: 'messages' } })
    })

    it('keeps the allowed key and drops the rest in one link', () => {
      expect(
        parseAppLink(`${HOST}/creator/assignments/${ULID}?utm=x&focus=messages&tab=y`),
      ).toMatchObject({ query: { focus: 'messages' } })
    })
  })

  describe('a path that fails the allow-list is in scope with NO path (it becomes the dashboard)', () => {
    const hostile: Array<[string, string]> = [
      ['just the prefix', '/creator/'],
      ['an encoded slash', `/creator/jobs/a%2fb`],
      ['an upper-case encoded slash', `/creator/jobs/a%2Fb`],
      ['an encoded backslash', `/creator/jobs/a%5cb`],
      ['an encoded dot inside a segment', `/creator/jobs/a%2eb`],
      ['an encoded NUL', `/creator/jobs/a%00b`],
      ['an encoded control character', `/creator/jobs/a%0ab`],
      ['an encoded DEL', `/creator/jobs/a%7fb`],
      ['a double-encoded value', `/creator/jobs/%252e%252e`],
      ['a percent sign', `/creator/jobs/100%25`],
      ['an empty segment', `/creator//jobs`],
      ['a space', `/creator/jobs/a%20b`],
      ['unicode', `/creator/jobs/%E2%80%AEevil`],
      ['a semicolon', `/creator/jobs/a;b`],
      ['a path longer than 256 characters', `/creator/jobs/${'a'.repeat(300)}`],
    ]

    it.each(hostile)('%s', (_label, path) => {
      expect(parseAppLink(`${HOST}${path}?focus=messages`)).toEqual({
        kind: 'in-scope',
        path: null,
        query: {},
      })
    })
  })

  it('never throws, whatever it is given', () => {
    const inputs = [
      'https://',
      'https://[::1',
      '//platform.catalyst-growth.com/creator/x',
      '\u0000',
      `${HOST}/creator/${'%'.repeat(5)}`,
      Symbol('x'),
      () => 1,
    ]

    for (const input of inputs) {
      expect(() => parseAppLink(input)).not.toThrow()
    }
  })
})

describe('resolveAppLink — against the REAL route table', () => {
  it('a creator screen resolves to its own full path', () => {
    expect(resolveAppLink(router, `${HOST}/creator/jobs/${ULID}`)).toEqual({
      kind: 'route',
      fullPath: `/creator/jobs/${ULID}`,
    })
  })

  it('keeps ?focus=messages on the assignment detail, and nothing else', () => {
    expect(
      resolveAppLink(router, `${HOST}/creator/assignments/${ULID}?focus=messages&utm=x`),
    ).toEqual({ kind: 'route', fullPath: `/creator/assignments/${ULID}?focus=messages` })
  })

  it.each([
    ['an unknown creator route', '/creator/nonsense'],
    ['an unknown nested creator route', '/creator/jobs/x/y/z'],
    ['an unknown creator sub-path of a known one', `/creator/messages/${ULID}/extra`],
    ['just the prefix', '/creator/'],
    ['a hostile path form', '/creator/jobs/a%2fb'],
  ])('%s opens the dashboard (D2)', (_label, path) => {
    expect(resolveAppLink(router, `${HOST}${path}`)).toEqual({ kind: 'dashboard' })
  })

  it('an out-of-scope link is ignored', () => {
    expect(resolveAppLink(router, `${HOST}/auth/verify-email?token=x`)).toEqual({ kind: 'ignore' })
    expect(resolveAppLink(router, 'not a url')).toEqual({ kind: 'ignore' })
  })

  it('a resolver that throws is an unusable link: the dashboard, not a crash', () => {
    const throwing = {
      resolve: () => {
        throw new Error('boom')
      },
    }

    expect(resolveAppLink(throwing, `${HOST}/creator/jobs/${ULID}`)).toEqual({ kind: 'dashboard' })
  })

  it('a path that matches a NON-creator route is the dashboard, never that route', () => {
    const stub = {
      resolve: () => ({ name: 'campaigns.detail', matched: [{}], fullPath: '/creator/jobs/x' }),
    }

    expect(resolveAppLink(stub, `${HOST}/creator/jobs/x`)).toEqual({ kind: 'dashboard' })
  })

  it('a match with no route name (a symbol or none) is the dashboard', () => {
    const noName = { resolve: () => ({ matched: [{}], fullPath: '/creator/jobs/x' }) }
    const symbolName = {
      resolve: () => ({ name: Symbol('x'), matched: [{}], fullPath: '/creator/jobs/x' }),
    }

    expect(resolveAppLink(noName, `${HOST}/creator/jobs/x`)).toEqual({ kind: 'dashboard' })
    expect(resolveAppLink(symbolName, `${HOST}/creator/jobs/x`)).toEqual({ kind: 'dashboard' })
  })

  it('an empty match is the dashboard even when the name looks right', () => {
    const empty = {
      resolve: () => ({ name: 'creator.jobs.detail', matched: [], fullPath: '/creator/jobs/x' }),
    }

    expect(resolveAppLink(empty, `${HOST}/creator/jobs/x`)).toEqual({ kind: 'dashboard' })
  })
})

describe('route-table parity: every emailed creator CTA, and every creator route, is reachable by link', () => {
  /** The URLs the API's mails build from FRONTEND_MAIN_URL (checked against the real production email, R1). */
  const emailedCreatorCtas = [
    `/creator/assignments/${ULID}`,
    `/creator/jobs/${ULID}`,
    `/creator/messages/${ULID}`,
    '/creator/dashboard',
  ]

  it.each(emailedCreatorCtas)('the emailed link %s resolves to a creator route', (path) => {
    expect(resolveAppLink(router, `${HOST}${path}`)).toEqual({ kind: 'route', fullPath: path })
  })

  it('every named creator.* route with a concrete path opens through a link, to itself', () => {
    const creatorRoutes = router
      .getRoutes()
      .filter((r) => typeof r.name === 'string' && r.name.startsWith('creator.'))

    // A rename that stops a screen from being linkable must fail here.
    expect(creatorRoutes.length).toBeGreaterThanOrEqual(10)

    for (const route of creatorRoutes) {
      // Skip any route whose pattern is not plain `:param` segments (none today).
      if (/[()*?+]/.test(route.path)) {
        continue
      }

      const concrete = route.path.replace(/:[A-Za-z]+/g, ULID)

      expect(
        resolveAppLink(router, `${HOST}${concrete}`),
        `${String(route.name)} ${concrete}`,
      ).toEqual({ kind: 'route', fullPath: concrete })
    }
  })

  it('every creator.* route lives under the claimed prefix (a route outside it could never be linked)', () => {
    const outside = router
      .getRoutes()
      .filter((r) => typeof r.name === 'string' && r.name.startsWith('creator.'))
      .filter((r) => !r.path.startsWith(APP_LINK_PATH_PREFIX))

    expect(outside.map((r) => `${String(r.name)} ${r.path}`)).toEqual([])
  })

  it('no non-creator route is reachable through a link, even when the path is spelled under /creator/', () => {
    for (const path of ['/campaigns', '/roster', '/messages', '/sign-in', '/auth/verify-email']) {
      expect(resolveAppLink(router, `${HOST}/creator/..${path}`)).toEqual({ kind: 'ignore' })
    }
  })
})
