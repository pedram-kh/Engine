/**
 * AH-121 C2: a real Sentry client with a recording transport (no network, no real
 * DSN). Events go through the real client, `beforeSend` and the real scrubber; the
 * recorder keeps what the transport was handed. Mirrored byte-for-byte in both SPAs.
 */
/* eslint-disable @typescript-eslint/no-explicit-any -- recorded Sentry payloads and mock call args are untyped JSON */

import * as Sentry from '@sentry/vue'
import { createApp, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { startSentry, type SentryUserState } from '@/core/observability/sentryClient'

export const FAKE_DSN = 'https://publickey@o0.ingest.example.invalid/1'
export const ULID = '01HZX3K9Q5M7R2T8V4W6Y0A1B2'

export interface Recorded {
  readonly events: Array<Record<string, any>>
  readonly headers: Array<Record<string, any>>
  /** The type of every envelope item the transport was handed (`event`, `session`, ...). */
  readonly itemTypes: string[]
}

export interface Harness {
  readonly recorded: Recorded
  readonly user: ReturnType<typeof ref<SentryUserState>>
  readonly router: ReturnType<typeof createRouter>
  readonly app: ReturnType<typeof createApp>
  /** Wait for the transport to have been handed `count` events. */
  settled: (count?: number) => Promise<void>
  close: () => Promise<void>
}

export function startRecordingSentry(overrides: Record<string, unknown> = {}): Harness {
  const recorded: Recorded = { events: [], headers: [], itemTypes: [] }
  const user = ref<SentryUserState>({ id: null, type: null })
  const app = createApp({ render: () => null })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { render: () => null } }],
  })
  const started = startSentry({
    app,
    router,
    dsn: FAKE_DSN,
    release: 'catalyst-test@0123456789ab',
    environment: 'test',
    user: () => user.value,
    overrides: {
      transport: () => ({
        send: async (envelope: any) => {
          recorded.headers.push(envelope[0])
          for (const item of envelope[1]) {
            recorded.itemTypes.push(item[0].type)
            if (item[0].type === 'event') {
              recorded.events.push(item[1])
            }
          }
          return {}
        },
        flush: async () => true,
      }),
      ...overrides,
    } as never,
  })
  if (!started) {
    throw new Error('startSentry did not start with a DSN')
  }
  return {
    recorded,
    user,
    router,
    app,
    settled: async (count = 1) => {
      await Sentry.flush(1000)
      for (let i = 0; i < 50 && recorded.events.length < count; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 10))
      }
    },
    close: async () => {
      await Sentry.close(0)
      Sentry.getCurrentScope().clear()
      Sentry.getIsolationScope().clear()
    },
  }
}
