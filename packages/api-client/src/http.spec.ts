import axios, { type AxiosInstance } from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { ApiError } from './errors'
import { createHttpClient, type HttpClient } from './http'

const BASE_URL = 'http://api.test/api/v1'
const CSRF_URL = 'http://api.test/sanctum/csrf-cookie'

interface Harness {
  http: HttpClient
  axios: AxiosInstance
  mock: MockAdapter
}

function makeHarness(): Harness {
  const instance = axios.create({
    withCredentials: true,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    xsrfCookieName: undefined,
    xsrfHeaderName: undefined,
  })
  const mock = new MockAdapter(instance)
  const http = createHttpClient({ baseUrl: BASE_URL, axiosInstance: instance })
  return { http, axios: instance, mock }
}

function setXsrfCookie(value: string): void {
  document.cookie = `XSRF-TOKEN=${encodeURIComponent(value)}; path=/`
}

function clearAllCookies(): void {
  for (const cookie of document.cookie.split(';')) {
    const eq = cookie.indexOf('=')
    const name = (eq > -1 ? cookie.slice(0, eq) : cookie).trim()
    if (name.length > 0) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
    }
  }
}

describe('createHttpClient', () => {
  let h: Harness

  beforeEach(() => {
    h = makeHarness()
    clearAllCookies()
  })

  afterEach(() => {
    h.mock.reset()
    h.mock.restore()
    clearAllCookies()
  })

  describe('GET requests', () => {
    it('returns the parsed JSON body and does not call the CSRF endpoint', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, { data: { id: '01HQ' } })

      const body = await h.http.get('/me')

      expect(body).toEqual({ data: { id: '01HQ' } })
      expect(h.mock.history.get.find((r) => r.url === CSRF_URL)).toBeUndefined()
    })

    it('always issues with withCredentials: true', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, {})
      await h.http.get('/me')
      expect(h.mock.history.get[0]?.withCredentials).toBe(true)
    })

    it('forwards optional headers without clobbering Accept / Content-Type', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, {})
      await h.http.get('/me', { headers: { 'Accept-Language': 'pt' } })
      const headers = h.mock.history.get[0]?.headers as Record<string, string>
      expect(headers?.['Accept-Language']).toBe('pt')
      expect(headers?.['Accept']).toBe('application/json')
      expect(headers?.['X-Requested-With']).toBe('XMLHttpRequest')
    })

    it('throws an ApiError shaped from the envelope on a 4xx', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(401, {
        errors: [{ status: '401', code: 'auth.invalid_credentials', title: 'no.' }],
        meta: { request_id: 'req-1' },
      })

      await expect(h.http.get('/me')).rejects.toBeInstanceOf(ApiError)
      try {
        await h.http.get('/me')
      } catch (err) {
        const e = err as ApiError
        expect(e.status).toBe(401)
        expect(e.code).toBe('auth.invalid_credentials')
        expect(e.requestId).toBe('req-1')
      }
    })

    it('throws an ApiError with status 0 on network failure', async () => {
      h.mock.onGet(`${BASE_URL}/me`).networkError()

      await expect(h.http.get('/me')).rejects.toMatchObject({
        status: 0,
        code: 'network.error',
      })
    })
  })

  describe('CSRF preflight on state-changing methods', () => {
    it('issues GET /sanctum/csrf-cookie before POST', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, { data: { id: '01HQ' } })

      await h.http.post('/auth/login', { email: 'a@b.c', password: 'pw' })

      expect(h.mock.history.get).toHaveLength(1)
      expect(h.mock.history.get[0]?.url).toBe(CSRF_URL)
      expect(h.mock.history.post).toHaveLength(1)
      expect(h.mock.history.post[0]?.url).toBe(`${BASE_URL}/auth/login`)
    })

    it('issues GET /sanctum/csrf-cookie before PATCH', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPatch(`${BASE_URL}/me`).reply(204)

      await h.http.patch('/me', { theme_preference: 'dark' })

      expect(h.mock.history.get[0]?.url).toBe(CSRF_URL)
      expect(h.mock.history.patch).toHaveLength(1)
    })

    it('issues GET /sanctum/csrf-cookie before DELETE', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onDelete(`${BASE_URL}/me/sessions/1`).reply(204)

      await h.http.delete('/me/sessions/1')

      expect(h.mock.history.get[0]?.url).toBe(CSRF_URL)
      expect(h.mock.history.delete).toHaveLength(1)
    })

    it('attaches X-XSRF-TOKEN derived from the XSRF-TOKEN cookie on POST', async () => {
      h.mock.onGet(CSRF_URL).reply(() => {
        // Real Laravel sets the cookie inside the preflight reply.
        // The mock can't drop a Set-Cookie header that JSDOM honours,
        // so we set the cookie inline to simulate the post-preflight
        // state.
        setXsrfCookie('csrf-secret-value')
        return [204, '']
      })
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', { email: 'a@b.c', password: 'pw' })

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-XSRF-TOKEN']).toBe('csrf-secret-value')
    })

    it('decodes URL-encoded XSRF-TOKEN cookies (Laravel encodes them)', async () => {
      h.mock.onGet(CSRF_URL).reply(() => {
        // Laravel writes the token URL-encoded.
        setXsrfCookie('eyJpdiI6Ij+/=')
        return [204, '']
      })
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', {})

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-XSRF-TOKEN']).toBe('eyJpdiI6Ij+/=')
    })

    it('still issues the request if the XSRF-TOKEN cookie is missing (server returns 419, surfaced as ApiError)', async () => {
      // No cookie set. The preflight is mocked to return 204 without
      // setting any cookie — production Laravel would set one, but a
      // proxy stripping cookies could break this. Make sure we don't
      // crash; we should still send the POST with no token, and a 419
      // from the server should bubble up cleanly.
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(419, {
        errors: [{ status: '419', code: 'auth.csrf_mismatch', title: 'CSRF mismatch.' }],
      })

      await expect(h.http.post('/auth/login', {})).rejects.toMatchObject({
        status: 419,
        code: 'auth.csrf_mismatch',
      })
    })

    it('does not issue the CSRF preflight on GET', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, {})
      await h.http.get('/me')
      expect(h.mock.history.get.find((r) => r.url === CSRF_URL)).toBeUndefined()
    })

    it('still throws ApiError if the CSRF preflight itself fails', async () => {
      h.mock.onGet(CSRF_URL).reply(503, {
        errors: [{ code: 'maintenance.scheduled', title: 'down' }],
      })

      await expect(h.http.post('/auth/login', {})).rejects.toMatchObject({
        status: 503,
        code: 'maintenance.scheduled',
      })
    })
  })

  describe('path resolution', () => {
    it('preserves an absolute http URL passed directly', async () => {
      h.mock.onGet('http://other.test/raw').reply(200, {})
      await h.http.get('http://other.test/raw')
      expect(h.mock.history.get[0]?.url).toBe('http://other.test/raw')
    })

    it('honours an absolute URL on the request path verbatim, ignoring baseUrl', async () => {
      h.mock.onGet('https://other.test/raw').reply(200, {})
      await h.http.get('https://other.test/raw')
      expect(h.mock.history.get[0]?.url).toBe('https://other.test/raw')
    })

    it('routes a path with a leading slash under the versioned base', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, {})
      await h.http.get('/me')
      expect(h.mock.history.get[0]?.url).toBe(`${BASE_URL}/me`)
    })

    it('routes a path without a leading slash the same way', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(200, {})
      await h.http.get('me')
      expect(h.mock.history.get[0]?.url).toBe(`${BASE_URL}/me`)
    })

    it('handles a baseUrl with a trailing slash', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({ baseUrl: 'http://api.test/api/v1/', axiosInstance: instance })
      mock.onGet('http://api.test/api/v1/me').reply(200, {})
      await http.get('/me')
      expect(mock.history.get[0]?.url).toBe('http://api.test/api/v1/me')
      mock.restore()
    })

    it('uses a relative csrf path when baseUrl is itself relative', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({ baseUrl: '/api/v1', axiosInstance: instance })
      mock.onGet('/sanctum/csrf-cookie').reply(204)
      mock.onPost('/api/v1/auth/login').reply(200, {})
      await http.post('/auth/login', {})
      expect(mock.history.get[0]?.url).toBe('/sanctum/csrf-cookie')
      mock.restore()
    })
  })

  describe('defaults and overrides', () => {
    it('forces withCredentials true even if an injected axios instance defaulted to false', async () => {
      const instance = axios.create({ withCredentials: false })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({ baseUrl: BASE_URL, axiosInstance: instance })
      mock.onGet(`${BASE_URL}/me`).reply(200, {})

      await http.get('/me')
      expect(mock.history.get[0]?.withCredentials).toBe(true)

      mock.restore()
    })

    it('prepends a leading slash to csrfCookieUrl when one is missing', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        csrfCookieUrl: 'sanctum/csrf-cookie',
        axiosInstance: instance,
      })
      mock.onGet(CSRF_URL).reply(204)
      mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await http.post('/auth/login', {})

      expect(mock.history.get[0]?.url).toBe(CSRF_URL)

      mock.restore()
    })

    it('honours an absolute csrfCookieUrl verbatim, ignoring baseUrl host', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        csrfCookieUrl: 'https://other.test/sanctum/csrf-cookie',
        axiosInstance: instance,
      })
      mock.onGet('https://other.test/sanctum/csrf-cookie').reply(204)
      mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await http.post('/auth/login', {})

      expect(mock.history.get[0]?.url).toBe('https://other.test/sanctum/csrf-cookie')

      mock.restore()
    })

    it('honours custom xsrfCookieName / xsrfHeaderName / csrfCookieUrl', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        csrfCookieUrl: '/custom/csrf',
        xsrfCookieName: 'CUSTOM-COOKIE',
        xsrfHeaderName: 'X-CUSTOM-HEADER',
        axiosInstance: instance,
      })
      mock.onGet('http://api.test/custom/csrf').reply(() => {
        document.cookie = 'CUSTOM-COOKIE=ABC; path=/'
        return [204, '']
      })
      mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await http.post('/auth/login', {})

      const headers = mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-CUSTOM-HEADER']).toBe('ABC')
      expect(headers?.['X-XSRF-TOKEN']).toBeUndefined()

      mock.restore()
    })

    it('produces a working production-default client when no axiosInstance is injected', async () => {
      // Smoke test: createHttpClient without axiosInstance must not
      // crash on construction or first call. We do not expect to hit
      // the network — just that the path through the default branch
      // is exercised. Triggering a network error and asserting the
      // ApiError shape is sufficient.
      const http = createHttpClient({ baseUrl: 'http://127.0.0.1:65535/api/v1' })
      await expect(http.get('/me')).rejects.toMatchObject({ status: 0, code: 'network.error' })
    })
  })

  describe('error normalization edge cases', () => {
    it('rewraps a thrown ApiError without altering it', async () => {
      // If something inside the request pipeline already threw an
      // ApiError (e.g. an interceptor), the normalizer should pass
      // it through.
      const direct = new ApiError({ status: 418, code: 'teapot', message: 'I am a teapot' })
      h.axios.interceptors.request.use(() => {
        throw direct
      })
      await expect(h.http.get('/me')).rejects.toBe(direct)
    })

    it('wraps a non-axios non-ApiError thrown inside the pipeline as a network error', async () => {
      const odd = new TypeError('something else')
      h.axios.interceptors.request.use(() => {
        throw odd
      })
      await expect(h.http.get('/me')).rejects.toMatchObject({
        status: 0,
        code: 'network.error',
      })
    })

    it('reads no cookie and produces no header when document is unavailable in JSDOM', async () => {
      // When no XSRF cookie is set the preflight call still happens
      // but the resulting POST omits X-XSRF-TOKEN.
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', {})

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-XSRF-TOKEN']).toBeUndefined()
    })

    it('omits X-XSRF-TOKEN when other cookies exist but XSRF-TOKEN is absent', async () => {
      document.cookie = 'session_id=abc; path=/'
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', {})

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-XSRF-TOKEN']).toBeUndefined()
    })

    it('falls back to the raw cookie value when decodeURIComponent throws', async () => {
      // `%E0%A4%A` is a truncated percent-escape that
      // `decodeURIComponent` rejects with URIError. The client
      // should still forward the raw value.
      document.cookie = 'XSRF-TOKEN=%E0%A4%A; path=/'
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', {})

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['X-XSRF-TOKEN']).toBe('%E0%A4%A')
    })
  })

  describe('onUnauthorized callback', () => {
    it('fires on a 401 response with the original request path', async () => {
      const seen: string[] = []
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        axiosInstance: instance,
        onUnauthorized: (p) => seen.push(p),
      })
      mock.onGet(`${BASE_URL}/me`).reply(401, {
        errors: [{ status: '401', code: 'auth.unauthenticated', title: 'no.' }],
      })

      await expect(http.get('/me')).rejects.toBeInstanceOf(ApiError)
      expect(seen).toEqual(['/me'])

      mock.restore()
    })

    it('fires once per call even when the same caller retries', async () => {
      const seen: string[] = []
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        axiosInstance: instance,
        onUnauthorized: (p) => seen.push(p),
      })
      mock.onGet(`${BASE_URL}/users/1`).reply(401, {
        errors: [{ status: '401', code: 'auth.unauthenticated' }],
      })

      await expect(http.get('/users/1')).rejects.toBeInstanceOf(ApiError)
      await expect(http.get('/users/1')).rejects.toBeInstanceOf(ApiError)
      expect(seen).toEqual(['/users/1', '/users/1'])

      mock.restore()
    })

    it('does not fire on non-401 errors', async () => {
      const seen: string[] = []
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        axiosInstance: instance,
        onUnauthorized: (p) => seen.push(p),
      })
      mock.onGet(`${BASE_URL}/me`).reply(403, {
        errors: [{ status: '403', code: 'auth.mfa.required' }],
      })

      await expect(http.get('/me')).rejects.toBeInstanceOf(ApiError)
      expect(seen).toHaveLength(0)

      mock.restore()
    })

    it('does not fire on a network error (status 0)', async () => {
      const seen: string[] = []
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        axiosInstance: instance,
        onUnauthorized: (p) => seen.push(p),
      })
      mock.onGet(`${BASE_URL}/me`).networkError()

      await expect(http.get('/me')).rejects.toMatchObject({ status: 0 })
      expect(seen).toHaveLength(0)

      mock.restore()
    })

    it('still throws the ApiError when the callback itself throws', async () => {
      const instance = axios.create({ withCredentials: true })
      const mock = new MockAdapter(instance)
      const http = createHttpClient({
        baseUrl: BASE_URL,
        axiosInstance: instance,
        onUnauthorized: () => {
          throw new Error('policy hook exploded')
        },
      })
      mock.onGet(`${BASE_URL}/me`).reply(401, {
        errors: [{ status: '401', code: 'auth.unauthenticated' }],
      })

      await expect(http.get('/me')).rejects.toBeInstanceOf(ApiError)

      mock.restore()
    })

    it('is a no-op when no callback is provided', async () => {
      h.mock.onGet(`${BASE_URL}/me`).reply(401, {
        errors: [{ status: '401', code: 'auth.unauthenticated' }],
      })

      await expect(h.http.get('/me')).rejects.toBeInstanceOf(ApiError)
    })
  })

  describe('default request bodies', () => {
    it('defaults POST body to {} when omitted', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/logout`).reply(200, {})

      await h.http.post('/auth/logout')

      expect(h.mock.history.post[0]?.data).toBe('{}')
    })

    it('defaults PATCH body to {} when omitted', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPatch(`${BASE_URL}/me`).reply(204)

      await h.http.patch('/me')

      expect(h.mock.history.patch[0]?.data).toBe('{}')
    })

    it('omits the request body on DELETE when none is given', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onDelete(`${BASE_URL}/me/sessions/1`).reply(204)

      await h.http.delete('/me/sessions/1')

      expect(h.mock.history.delete[0]?.data).toBeUndefined()
    })

    it('forwards the request body on DELETE when given', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onDelete(`${BASE_URL}/me/two-factor`).reply(204)

      await h.http.delete('/me/two-factor', { totp_code: '123456' })

      expect(h.mock.history.delete[0]?.data).toBe(JSON.stringify({ totp_code: '123456' }))
    })

    it('forwards optional headers on POST without clobbering JSON defaults', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/auth/login`).reply(200, {})

      await h.http.post('/auth/login', { x: 1 }, { headers: { 'Accept-Language': 'pt' } })

      const headers = h.mock.history.post[0]?.headers as Record<string, string>
      expect(headers?.['Accept-Language']).toBe('pt')
      expect(headers?.['Content-Type']).toBe('application/json')
    })

    it('forwards optional headers on PATCH and DELETE', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPatch(`${BASE_URL}/me`).reply(204)
      h.mock.onDelete(`${BASE_URL}/me/sessions/1`).reply(204)

      await h.http.patch('/me', { theme_preference: 'dark' }, { headers: { 'X-A': '1' } })
      await h.http.delete('/me/sessions/1', undefined, { headers: { 'X-B': '2' } })

      const patchHeaders = h.mock.history.patch[0]?.headers as Record<string, string>
      const deleteHeaders = h.mock.history.delete[0]?.headers as Record<string, string>
      expect(patchHeaders?.['X-A']).toBe('1')
      expect(deleteHeaders?.['X-B']).toBe('2')
    })

    // -----------------------------------------------------------------
    // FormData / multipart contract (Sprint 3 Chunk 4 sub-step 11)
    //
    // The avatar / portfolio / bulk-invite endpoints pass a `FormData`
    // instance as the body. The browser-set boundary lives inside the
    // `Content-Type: multipart/form-data; boundary=…` header — if we
    // ship the default `application/json` instead, axios 1.x's
    // `transformRequest` JSON-stringifies the FormData via
    // `formDataToJSON`, and Laravel's `$request->file(…)` sees no upload.
    // The client deletes the explicit Content-Type for FormData bodies so
    // axios + the browser cooperate on the multipart header.
    // -----------------------------------------------------------------
    it('drops the JSON Content-Type when the body is FormData (multipart)', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/avatar`).reply(200, {})

      const form = new FormData()
      form.append('avatar', new Blob(['x'], { type: 'image/png' }), 'avatar.png')

      await h.http.post('/avatar', form)

      const headers = h.mock.history.post[0]?.headers as Record<string, string> | undefined
      const contentType = headers?.['Content-Type'] ?? headers?.['content-type']
      // Acceptable outcomes: header dropped entirely OR axios filled it
      // with `multipart/form-data; boundary=…`. The forbidden outcome
      // is `application/json` (which would re-trigger formDataToJSON).
      const dropped = contentType === undefined || contentType === ''
      const multipart = typeof contentType === 'string' && /multipart\/form-data/i.test(contentType)
      expect(dropped || multipart).toBe(true)
    })

    it('keeps the JSON Content-Type for plain-object bodies on the same instance', async () => {
      h.mock.onGet(CSRF_URL).reply(204)
      h.mock.onPost(`${BASE_URL}/avatar`).reply(200, {})
      h.mock.onPost(`${BASE_URL}/sessions`).reply(204)

      const form = new FormData()
      form.append('avatar', new Blob(['x'], { type: 'image/png' }), 'avatar.png')
      await h.http.post('/avatar', form)
      await h.http.post('/sessions', { email: 'x@y.z' })

      const jsonHeaders = h.mock.history.post[1]?.headers as Record<string, string>
      expect(jsonHeaders?.['Content-Type']).toBe('application/json')
    })
  })
})
