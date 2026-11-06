/**
 * AH-119 Stage B — shared test support for the "flag off = the DOM of today" pins.
 *
 * `stageBFlagOffPins.json` holds the markup the messaging views rendered BEFORE Stage B (captured
 * from the last Stage A commit, scoped-style hashes and generated ids stripped). The specs mount the
 * current components with the Stage B switch off and require exactly that markup, in the web and the
 * Capacitor polarity, so Stage B can never change what a build 3 server / flag-off tenant sees.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import pins from './stageBFlagOffPins.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

export const FIXED_ISO = '2026-01-01T09:30:00+00:00'

/** Strip what differs between two copies of one component without any behavioural difference. */
export function normaliseHtml(html: string): string {
  return html
    .replace(/ data-v-[0-9a-f]+=""/g, '')
    .replace(/ id="[^"]*"/g, '')
    .replace(/ for="[^"]*"/g, '')
    .replace(/ aria-describedby="[^"]*"/g, '')
    .replace(/\d{1,2}:\d{2}\s?[AP]M/g, 'H:MM')
}

export function relMessage(id: string, own: boolean, extra: Record<string, unknown> = {}) {
  return {
    id,
    type: 'relationship_message',
    attributes: {
      kind: 'text',
      sender_role: own ? 'agency_user' : 'creator',
      body: own ? 'see https://example.com/x now' : 'hello there',
      attachments: [
        {
          kind: 'file',
          s3_path: 'a',
          mime_type: 'image/png',
          name: 'p.png',
          size_bytes: 10,
          view_url: 'https://files.test/p.png',
        },
        { kind: 'link', url: 'https://example.com', name: 'ex' },
      ],
      is_own: own,
      sender: { name: 'Agency Op' },
      read_by_counterparty: own ? true : null,
      created_at: FIXED_ISO,
      ...extra,
    },
  }
}

export function chatMessage(id: string, own: boolean, kind: 'text' | 'system' = 'text') {
  return {
    id,
    type: 'message',
    attributes: {
      kind,
      sender_role: own ? 'agency_user' : 'creator',
      body: kind === 'system' ? null : 'hi https://example.com',
      attachments:
        kind === 'system'
          ? []
          : [
              {
                s3_path: 'b',
                mime_type: 'application/pdf',
                name: 'b.pdf',
                size_bytes: 1,
                view_url: 'https://files.test/b.pdf',
              },
            ],
      system_event_key: kind === 'system' ? 'assignment.accepted' : null,
      system_event_data: null,
      is_own: own,
      sender: { name: 'Op' },
      created_at: FIXED_ISO,
    },
  }
}

export function relTransport(canSend = true) {
  return {
    list: vi.fn().mockResolvedValue({
      data: [
        relMessage('m1', false),
        relMessage('m2', true),
        relMessage('m3', true, { body: null }),
      ],
      meta: {
        thread: {
          id: 't',
          last_message_at: null,
          unread_count: 0,
          can_send: canSend,
          closed_reason: null,
        },
        has_more: false,
      },
    }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  }
}

export function chatTransport() {
  return {
    list: vi.fn().mockResolvedValue({
      data: [chatMessage('c1', false), chatMessage('c2', true), chatMessage('c3', false, 'system')],
      meta: {
        thread: {
          id: 't',
          assignment_id: 'a',
          last_message_at: null,
          unread_count: 0,
          human_send_blocked: false,
        },
        has_more: false,
      },
    }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  }
}

export const unifiedInboxProps = {
  items: [
    {
      key: 'k1',
      title: 'Ana',
      preview: 'yo',
      previewIsAttachment: false,
      lastMessageAt: FIXED_ISO,
      unreadCount: 2,
      avatarText: 'Ana',
      to: '/x',
      direct: true,
      brandName: null,
      brandColor: null,
      campaignName: null,
    },
    {
      key: 'k2',
      title: 'Bo',
      preview: null,
      previewIsAttachment: true,
      lastMessageAt: null,
      unreadCount: 0,
      avatarText: 'Bo',
      to: '/y',
      direct: false,
      brandName: 'B',
      brandId: 'b1',
      brandColor: 'pink',
      campaignName: 'C',
    },
  ],
  facets: { brands: [], campaigns: [] },
  unreadThreadsTotal: 2,
  filters: { brand: [], campaign: [], unread: false, owner: 'all', direct: false },
  loading: false,
  loadError: false,
  loadingMore: false,
  hasMore: true,
}

export interface MountOptions {
  /** `/me` features: undefined mounts with no Pinia at all (a bare component test). */
  features?: Record<string, boolean>
  pinia?: boolean
}

/** Mount `component` with the plugins the messaging views need and (optionally) a `/me` user. */
export async function mountView(
  component: object,
  props: Record<string, unknown>,
  options: MountOptions = {},
) {
  if (options.pinia === true || options.features !== undefined) {
    setActivePinia(createPinia())
    if (options.features !== undefined) {
      const auth = useAuthStore()
      auth.user = {
        id: 'u1',
        type: 'users',
        attributes: { features: { account_deletion: false, ...options.features } },
      } as never
    }
  }
  const wrapper = mount(component as never, {
    props: props as never,
    global: {
      plugins: [
        createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never }),
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
        createRouter({
          history: createMemoryHistory(),
          routes: [{ path: '/:p(.*)*', component: { template: '<div/>' } }],
        }),
      ],
    },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

/** Mount `component`, wait for the feed, return the normalised html of `selector`. */
export async function renderHtml(
  component: object,
  props: Record<string, unknown>,
  selector: string,
  options: MountOptions = {},
): Promise<string> {
  const wrapper = await mountView(component, props, options)
  const html = normaliseHtml(wrapper.find(selector).html())
  wrapper.unmount()
  return html
}

/**
 * The pre-Stage-B markup captured from the last Stage A commit. Clock times are masked on both
 * sides: the pins were captured in CET and CI runs in UTC (a rendered time is timezone-bound).
 */
export const FLAG_OFF_PINS: Record<string, string> = Object.fromEntries(
  Object.entries(pins as Record<string, string>).map(([key, html]) => [key, normaliseHtml(html)]),
)
