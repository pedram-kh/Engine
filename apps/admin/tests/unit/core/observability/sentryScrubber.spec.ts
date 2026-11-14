/**
 * AH-121 C2: the scrubber, both directly (synthetic events) and through a real
 * client with a recording transport. Mirrored in both SPAs.
 */
/* eslint-disable @typescript-eslint/no-explicit-any -- recorded Sentry payloads and mock call args are untyped JSON */

import { ApiError } from '@catalyst/api-client'
import * as Sentry from '@sentry/vue'
import { afterEach, describe, expect, it } from 'vitest'

import {
  scrubBreadcrumb,
  scrubEvent,
  stripQueryAndFragment,
} from '@/core/observability/sentryScrubber'

import { startRecordingSentry, ULID, type Harness } from '../../helpers/sentryRecorder'

const scrub = (event: Record<string, unknown>): Record<string, any> =>
  scrubEvent(event as never) as unknown as Record<string, any>

let harness: Harness | null = null
afterEach(async () => {
  await harness?.close()
  harness = null
})

async function captureThroughClient(fn: () => void): Promise<Record<string, any>> {
  harness = startRecordingSentry()
  fn()
  await harness.settled()
  const event = harness.recorded.events[0]
  if (event === undefined) {
    throw new Error('no event reached the transport')
  }
  return event
}

describe('URLs lose query and fragment', () => {
  it('turns "/sign-in?redirect=/" into "/sign-in" (the C0 finding)', () => {
    expect(stripQueryAndFragment('/sign-in?redirect=/')).toBe('/sign-in')
    const crumb = scrubBreadcrumb({
      category: 'navigation',
      data: { from: '/dashboard?email=a@b.co#token=zzz', to: '/sign-in?redirect=/' },
    })
    expect(crumb?.data).toEqual({ from: '/dashboard', to: '/sign-in' })
  })

  it('strips ?token=, ?email= and #token= from request.url', () => {
    for (const url of [
      'https://app.example.test/reset?token=abc123',
      'https://app.example.test/invite?email=jane@example.com',
      'https://app.example.test/impersonation/claim#token=abc123',
    ]) {
      const out = scrub({ request: { url } })
      expect(out.request.url).toBe(url.split(/[?#]/)[0])
    }
  })

  it('strips the main SPA /impersonation/claim#token= URL everywhere it can appear', () => {
    const url = 'http://127.0.0.1:5173/impersonation/claim#token=TESTTOKEN123'
    const out = scrub({
      request: { url },
      transaction: '/impersonation/claim#token=TESTTOKEN123',
      message: `claim failed at ${url}`,
      breadcrumbs: [
        {
          category: 'navigation',
          data: { from: '/', to: '/impersonation/claim#token=TESTTOKEN123' },
        },
      ],
    })
    expect(out.request.url).toBe('http://127.0.0.1:5173/impersonation/claim')
    expect(out.transaction).toBe('/impersonation/claim')
    expect(out.message).toBe('claim failed at http://127.0.0.1:5173/impersonation/claim')
    expect(out.breadcrumbs[0].data).toEqual({ from: '/', to: '/impersonation/claim' })
    expect(JSON.stringify(out)).not.toContain('TESTTOKEN123')
  })

  it('strips them from URLs embedded in free text too', () => {
    const out = scrub({
      message:
        'failed GET https://app.example.test/x?redirect=/creators&page=2 and /sign-in?next=/home#section',
    })
    expect(out.message).toBe('failed GET https://app.example.test/x and /sign-in')
  })

  it('keeps the route path with its ulids', () => {
    expect(scrub({ transaction: `/agencies/${ULID}/creators?x=1` }).transaction).toBe(
      `/agencies/${ULID}/creators`,
    )
  })
})

describe('emails and bearer-shaped strings', () => {
  it('redacts emails, bearer headers, Sanctum tokens and key=value secrets anywhere', () => {
    const out = scrub({
      message:
        'user jane.doe@example.com sent Authorization: Bearer abc.def-123 with 12|' +
        'a'.repeat(40),
      tags: { who: 'jane.doe@example.com' },
      extra: { note: 'password=hunter2 and api_key=sekret' },
      exception: { values: [{ value: 'bad token for bob@example.org' }] },
    })
    const text = JSON.stringify(out)
    for (const leaked of [
      'jane.doe',
      'bob@example',
      'abc.def-123',
      'a'.repeat(40),
      'hunter2',
      'sekret',
    ]) {
      expect(text).not.toContain(leaked)
    }
    expect(out.message).toContain('Bearer [token]')
  })

  it('redacts a 40-character token planted elsewhere', () => {
    const planted = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7U'
    expect(planted).toHaveLength(40)
    expect(JSON.stringify(scrub({ extra: { planted }, tags: { t: planted } }))).not.toContain(
      planted,
    )
  })
})

describe('request block', () => {
  it('drops headers, cookies, query string and data; keeps only url and method', () => {
    const out = scrub({
      request: {
        url: 'https://app.example.test/a?b=1',
        method: 'POST',
        headers: { Authorization: 'Bearer x', Cookie: 'sid=1', Referer: 'https://x/?token=1' },
        cookies: { sid: '1' },
        data: { password: 'p' },
        query_string: 'token=1',
      },
    })
    expect(out.request).toEqual({ url: 'https://app.example.test/a', method: 'POST' })
  })
})

describe('user block', () => {
  it('rebuilds user as { id } only, whatever else is set', () => {
    const out = scrub({
      user: {
        id: ULID,
        email: 'a@b.co',
        username: 'jane',
        name: 'Jane',
        ip_address: '1.2.3.4',
        segment: 'x',
        geo: {},
      },
    })
    expect(Object.keys(out.user)).toEqual(['id'])
    expect(out.user.id).toBe(ULID)
  })

  it('drops a user whose id is not a ulid (an email or numeric id cannot slip through)', () => {
    expect(scrub({ user: { id: 'jane@example.com' } }).user).toBeUndefined()
    expect(scrub({ user: { id: '42' } }).user).toBeUndefined()
  })

  it('through the real client: a stray Sentry.setUser({ email }) is stripped to the id', async () => {
    const event = await captureThroughClient(() => {
      Sentry.setUser({
        id: ULID,
        email: 'a@b.co',
        username: 'jane',
        ip_address: '1.2.3.4',
        segment: 's',
      })
      Sentry.captureException(new Error('boom'))
    })
    expect(Object.keys(event.user)).toEqual(['id'])
  })
})

describe('breadcrumbs', () => {
  it('drops console and UI crumbs and keeps an allow-list of data keys', () => {
    expect(scrubBreadcrumb({ category: 'console', message: 'x' })).toBeNull()
    expect(scrubBreadcrumb({ category: 'ui.click', message: 'div > button' })).toBeNull()
    const crumb = scrubBreadcrumb({
      category: 'fetch',
      data: {
        url: '/api/v1/me?token=1',
        method: 'GET',
        status_code: 200,
        request_body: '{"p":1}',
        response: 'x',
      },
    })
    expect(crumb?.data).toEqual({ url: '/api/v1/me', method: 'GET', status_code: 200 })
  })

  it('through the real client: console.log leaves no breadcrumb', async () => {
    const event = await captureThroughClient(() => {
      console.log('hello jane@example.com')
      Sentry.captureException(new Error('boom'))
    })
    expect(JSON.stringify(event.breadcrumbs ?? [])).not.toContain('hello')
  })
})

describe("Sentry's own identifiers survive the opaque-token rule", () => {
  it('keeps valid 32-hex event_id and trace_id and 16-hex span ids, still redacts a planted token', () => {
    const planted = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7U'
    const out = scrub({
      event_id: 'a98a8584e3f84a4c9d6b0f3d2c1e5b7a',
      contexts: {
        trace: {
          trace_id: '0123456789abcdef0123456789abcdef',
          span_id: '0123456789abcdef',
          parent_span_id: 'fedcba9876543210',
        },
      },
      debug_meta: {
        images: [{ type: 'sourcemap', debug_id: '0b9d4c6a-1e2f-4a3b-8c7d-9e0f1a2b3c4d' }],
      },
      extra: { planted },
    })
    expect(out.event_id).toMatch(/^[0-9a-f]{32}$/)
    expect(out.contexts.trace.trace_id).toMatch(/^[0-9a-f]{32}$/)
    expect(out.contexts.trace.span_id).toMatch(/^[0-9a-f]{16}$/)
    expect(out.contexts.trace.parent_span_id).toMatch(/^[0-9a-f]{16}$/)
    expect(out.debug_meta.images[0].debug_id).toBe('0b9d4c6a-1e2f-4a3b-8c7d-9e0f1a2b3c4d')
    expect(out.extra.planted).toBe('[token]')
  })

  it('a token planted UNDER a Sentry id key is still redacted (shape-checked exemption)', () => {
    const planted = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7U'
    expect(scrub({ contexts: { trace: { trace_id: planted } } }).contexts.trace.trace_id).toBe(
      '[token]',
    )
  })

  it('through the real client: ids stay valid on the wire, a planted token does not', async () => {
    const planted = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7U'
    const event = await captureThroughClient(() => {
      Sentry.setExtra('planted', planted)
      Sentry.captureException(new Error('boom'))
    })
    expect(event.event_id).toMatch(/^[0-9a-f]{32}$/)
    expect(harness?.recorded.headers[0]?.event_id).toMatch(/^[0-9a-f]{32}$/)
    expect(event.contexts?.trace?.trace_id).toMatch(/^[0-9a-f]{32}$/)
    expect(event.contexts?.trace?.span_id).toMatch(/^[0-9a-f]{16}$/)
    expect(JSON.stringify(event)).not.toContain(planted)
    expect(event.release).toBe('catalyst-test@0123456789ab')
  })
})

describe('noise rules (B2)', () => {
  const hint = (originalException: unknown) => ({ originalException }) as never
  const apiError = (status: number, code: string) => new ApiError({ status, code, message: 'x' })

  it('does not send an ApiError below 500, a network error, or a cancelled request', () => {
    for (const error of [
      apiError(404, 'http.not_found'),
      apiError(422, 'validation.field_required'),
      apiError(499, 'x'),
      ApiError.fromNetworkError(new TypeError('Failed to fetch')),
      Object.assign(new Error('aborted'), { name: 'AbortError' }),
    ]) {
      expect(scrubEvent({ message: 'm' } as never, hint(error))).toBeNull()
    }
  })

  it('sends an ApiError of 500 and above, and always sends http.invalid_response_body', () => {
    expect(
      scrubEvent({ message: 'm' } as never, hint(apiError(500, 'server.error'))),
    ).not.toBeNull()
    expect(
      scrubEvent({ message: 'm' } as never, hint(apiError(200, 'http.invalid_response_body'))),
    ).not.toBeNull()
    expect(
      scrubEvent({ message: 'm' } as never, hint(ApiError.fromEnvelope(200, 'not json'))),
    ).not.toBeNull()
    expect(scrubEvent({ message: 'm' } as never, hint(new Error('plain')))).not.toBeNull()
  })

  it('through the real client: a 404 ApiError never reaches the transport, a 500 does', async () => {
    harness = startRecordingSentry()
    Sentry.captureException(apiError(404, 'http.not_found'))
    Sentry.captureException(apiError(500, 'server.error'))
    await harness.settled()
    expect(harness.recorded.events).toHaveLength(1)
    expect(harness.recorded.events[0]?.exception.values[0].value).toBe('x')
  })
})

describe('fails closed', () => {
  it('drops the event instead of sending it raw when scrubbing throws', () => {
    const hostile: Record<string, unknown> = {}
    Object.defineProperty(hostile, 'message', {
      enumerable: true,
      get() {
        throw new Error('boom')
      },
    })
    expect(scrubEvent(hostile as never)).toBeNull()
  })
})
