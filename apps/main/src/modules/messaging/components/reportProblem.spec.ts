/**
 * AH-112 (D8) — the minimal "Report a problem" mailto in the CREATOR message threads.
 *
 * Pinned here: the exact href (address, encoding, subject shape) on both shared views, the
 * absence of the link when no `reportSubject` is passed (every agency surface), the architecture
 * rule that only the two creator pages pass the prop, and the label in all 24 locales.
 */

import type { RelationshipThreadMeta } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { promises as fs } from 'node:fs'
import path from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import { SUPPORT_EMAIL } from '@/core/config/support'
import enApp from '@/core/i18n/locales/en/app.json'

import type { ChatTransport } from '../api/messaging.api'
import type { RelationshipChatTransport } from '../api/relationshipMessaging.api'
import { reportCopyText, reportHref, reportReference, reportSubject } from '../utils/reportProblem'
import ChatPanel from './ChatPanel.vue'
import RelationshipThreadView from './RelationshipThreadView.vue'

const AGENCY_ULID = '01HZX3K8Q4R5T6V7W8Y9Z0A1B2'
const ASSIGNMENT_ULID = '01HZX3K8Q4R5T6V7W8Y9Z0C3D4'

const feedMeta = {
  id: 'thread-ulid',
  last_message_at: null,
  unread_count: 0,
  can_send: true,
  closed_reason: null,
} satisfies RelationshipThreadMeta

function relationshipTransport(): RelationshipChatTransport {
  return {
    list: vi.fn().mockResolvedValue({ data: [], meta: { thread: feedMeta, has_more: false } }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
  }
}

function chatTransport(): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue({
      data: [],
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

async function mountWith<T extends object>(component: T, props: Record<string, unknown>) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(component as never, {
    props: props as never,
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('reportProblem helper', () => {
  it('builds the relationship and assignment subjects: fixed English prefix, kind word, one ulid', () => {
    expect(reportSubject('relationship', AGENCY_ULID)).toBe(
      `Catalyst app report: relationship ${AGENCY_ULID}`,
    )
    expect(reportSubject('assignment', ASSIGNMENT_ULID)).toBe(
      `Catalyst app report: assignment ${ASSIGNMENT_ULID}`,
    )
  })

  it('addresses the support inbox with the subject URL-encoded (colon and spaces escaped)', () => {
    expect(reportHref(reportSubject('relationship', AGENCY_ULID))).toBe(
      `mailto:${SUPPORT_EMAIL}?subject=Catalyst%20app%20report%3A%20relationship%20${AGENCY_ULID}`,
    )
  })

  it('takes the reference from the subject (its last word, the ulid)', () => {
    expect(reportReference(reportSubject('relationship', AGENCY_ULID))).toBe(AGENCY_ULID)
    expect(reportReference(reportSubject('assignment', ASSIGNMENT_ULID))).toBe(ASSIGNMENT_ULID)
  })

  it('builds the copy payload in fixed English, with an em dash: address, then the reference', () => {
    expect(reportCopyText(AGENCY_ULID)).toBe(`${SUPPORT_EMAIL} \u2014 reference: ${AGENCY_ULID}`)
  })
})

describe('RelationshipThreadView (creator relationship thread)', () => {
  it('renders the link with the exact href when a reportSubject is passed', async () => {
    const wrapper = await mountWith(RelationshipThreadView, {
      transport: relationshipTransport(),
      title: 'Acme Agency',
      reportSubject: reportSubject('relationship', AGENCY_ULID),
    })
    const link = wrapper.find('[data-test="relationship-thread-report"]')

    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('Report a problem')
    expect(link.attributes('href')).toBe(
      `mailto:${SUPPORT_EMAIL}?subject=Catalyst%20app%20report%3A%20relationship%20${AGENCY_ULID}`,
    )
    wrapper.unmount()
  })

  it('renders no link without a reportSubject (the agency surfaces)', async () => {
    const wrapper = await mountWith(RelationshipThreadView, {
      transport: relationshipTransport(),
      title: 'Acme Creator',
    })

    expect(wrapper.find('[data-test="relationship-thread-report"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('ChatPanel (creator campaign thread)', () => {
  it('renders the link with the exact href when a reportSubject is passed', async () => {
    const wrapper = await mountWith(ChatPanel, {
      transport: chatTransport(),
      reportSubject: reportSubject('assignment', ASSIGNMENT_ULID),
    })
    const link = wrapper.find('[data-test="chat-report"]')

    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('Report a problem')
    expect(link.attributes('href')).toBe(
      `mailto:${SUPPORT_EMAIL}?subject=Catalyst%20app%20report%3A%20assignment%20${ASSIGNMENT_ULID}`,
    )
    wrapper.unmount()
  })

  it('renders no link without a reportSubject (the agency surfaces)', async () => {
    const wrapper = await mountWith(ChatPanel, { transport: chatTransport() })

    expect(wrapper.find('[data-test="chat-report"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('the always-visible fallback (no mail client)', () => {
  const originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard')
  const originalExec = Object.getOwnPropertyDescriptor(document, 'execCommand')

  afterEach(() => {
    vi.useRealTimers()
    if (originalClipboard) Object.defineProperty(navigator, 'clipboard', originalClipboard)
    else Reflect.deleteProperty(navigator, 'clipboard')
    if (originalExec) Object.defineProperty(document, 'execCommand', originalExec)
    else Reflect.deleteProperty(document, 'execCommand')
  })

  function setClipboard(writeText: (text: string) => Promise<void>): void {
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  }

  function setExec(impl: () => boolean): ReturnType<typeof vi.fn> {
    const exec = vi.fn(impl)
    Object.defineProperty(document, 'execCommand', { value: exec, configurable: true })
    return exec
  }

  const EXPECTED_LINE = (ulid: string): string =>
    `or email ${SUPPORT_EMAIL} \u2014 reference: ${ulid}`
  const EXPECTED_PAYLOAD = (ulid: string): string => `${SUPPORT_EMAIL} \u2014 reference: ${ulid}`

  async function relationship() {
    return mountWith(RelationshipThreadView, {
      transport: relationshipTransport(),
      title: 'Acme Agency',
      reportSubject: reportSubject('relationship', AGENCY_ULID),
    })
  }

  async function campaign() {
    return mountWith(ChatPanel, {
      transport: chatTransport(),
      reportSubject: reportSubject('assignment', ASSIGNMENT_ULID),
    })
  }

  it('shows the address and the agency ulid as plain text in the relationship thread, beside the unchanged mailto', async () => {
    const wrapper = await relationship()

    expect(wrapper.find('[data-test="report-fallback-text"]').text()).toBe(
      EXPECTED_LINE(AGENCY_ULID),
    )
    expect(wrapper.find('[data-test="relationship-thread-report"]').attributes('href')).toBe(
      reportHref(reportSubject('relationship', AGENCY_ULID)),
    )
    wrapper.unmount()
  })

  it('shows the address and the assignment ulid as plain text in the campaign thread, beside the unchanged mailto', async () => {
    const wrapper = await campaign()

    expect(wrapper.find('[data-test="report-fallback-text"]').text()).toBe(
      EXPECTED_LINE(ASSIGNMENT_ULID),
    )
    expect(wrapper.find('[data-test="chat-report"]').attributes('href')).toBe(
      reportHref(reportSubject('assignment', ASSIGNMENT_ULID)),
    )
    wrapper.unmount()
  })

  it('renders nothing without a reportSubject (the agency surfaces)', async () => {
    const rel = await mountWith(RelationshipThreadView, {
      transport: relationshipTransport(),
      title: 'Acme Creator',
    })
    const chat = await mountWith(ChatPanel, { transport: chatTransport() })

    expect(rel.find('[data-test="report-fallback"]').exists()).toBe(false)
    expect(chat.find('[data-test="report-fallback"]').exists()).toBe(false)
    rel.unmount()
    chat.unmount()
  })

  it('copies exactly "address \u2014 reference: ulid" (relationship thread) and shows a tick', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    setClipboard(writeText)
    const wrapper = await relationship()

    await wrapper.find('[data-test="report-fallback-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText).toHaveBeenCalledWith(EXPECTED_PAYLOAD(AGENCY_ULID))
    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-check').exists()).toBe(true)
    wrapper.unmount()
  })

  it('copies exactly "address \u2014 reference: ulid" (campaign thread), and the tick resets', async () => {
    vi.useFakeTimers()
    const writeText = vi.fn().mockResolvedValue(undefined)
    setClipboard(writeText)
    const wrapper = await campaign()

    await wrapper.find('[data-test="report-fallback-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(EXPECTED_PAYLOAD(ASSIGNMENT_ULID))
    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-check').exists()).toBe(true)

    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-check').exists()).toBe(false)
    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-content-copy').exists()).toBe(true)
    wrapper.unmount()
  })

  it('falls back to execCommand with the same payload when the clipboard API is missing', async () => {
    Reflect.deleteProperty(navigator, 'clipboard')
    let copied = ''
    const exec = setExec(() => {
      copied = (document.querySelector('textarea') as HTMLTextAreaElement).value
      return true
    })
    const wrapper = await relationship()

    await wrapper.find('[data-test="report-fallback-copy"]').trigger('click')
    await flushPromises()

    expect(exec).toHaveBeenCalledWith('copy')
    expect(copied).toBe(EXPECTED_PAYLOAD(AGENCY_ULID))
    expect(document.querySelector('textarea')).toBeNull()
    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-check').exists()).toBe(true)
    wrapper.unmount()
  })

  it('falls back to execCommand when the clipboard API rejects, and shows no tick if that fails too', async () => {
    setClipboard(vi.fn().mockRejectedValue(new Error('denied')))
    setExec(() => {
      throw new Error('unsupported')
    })
    const wrapper = await relationship()

    await wrapper.find('[data-test="report-fallback-copy"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="report-fallback-copy"] .mdi-check').exists()).toBe(false)
    expect(wrapper.find('[data-test="report-fallback-text"]').text()).toBe(
      EXPECTED_LINE(AGENCY_ULID),
    )
    wrapper.unmount()
  })

  it('keeps the line selectable on iOS (a style rule, which jsdom cannot compute)', async () => {
    const source = await fs.readFile(path.join(__dirname, 'ReportFallback.vue'), 'utf8')

    expect(source).toMatch(/-webkit-user-select:\s*text/)
    expect(source).toMatch(/\buser-select:\s*text/)
  })
})

describe('who passes report-subject (architecture)', () => {
  const SRC = path.resolve(__dirname, '../../..')

  async function vueFiles(dir: string): Promise<string[]> {
    const out: string[] = []
    for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name)
      if (entry.isDirectory()) out.push(...(await vueFiles(full)))
      else if (entry.name.endsWith('.vue')) out.push(full)
    }
    return out
  }

  it('only the two creator pages pass it; no agency surface does', async () => {
    const passing: string[] = []
    for (const file of await vueFiles(SRC)) {
      const source = await fs.readFile(file, 'utf8')
      // The two components declare the prop; a PASSER binds it in a template.
      if (/:report-subject=|:reportSubject=/.test(source)) passing.push(path.relative(SRC, file))
    }

    expect(passing.sort()).toEqual([
      'modules/creators/pages/CreatorAssignmentDetailPage.vue',
      'modules/messaging/pages/CreatorRelationshipThreadPage.vue',
    ])
  })
})

describe('app.messaging.reportProblem in all 24 locales', () => {
  const LOCALE_ROOT = path.resolve(__dirname, '../../../core/i18n/locales')

  async function label(locale: string): Promise<string> {
    const raw = await fs.readFile(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')
    const parsed = JSON.parse(raw) as { app?: { messaging?: { reportProblem?: unknown } } }
    const value = parsed.app?.messaging?.reportProblem
    expect(typeof value).toBe('string')
    return value as string
  }

  it('is "Report a problem" in en', async () => {
    expect(await label('en')).toBe('Report a problem')
  })

  async function fallback(locale: string): Promise<string> {
    const raw = await fs.readFile(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')
    const parsed = JSON.parse(raw) as { app?: { messaging?: { reportFallback?: unknown } } }
    const value = parsed.app?.messaging?.reportFallback
    expect(typeof value).toBe('string')
    return value as string
  }

  it('reportFallback is "or email {email} \u2014 reference: {reference}" in en', async () => {
    expect(await fallback('en')).toBe('or email {email} \u2014 reference: {reference}')
  })

  it.each([
    'bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu',
    'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
  ])('%s: present, non-empty and translated (not the English string)', async (locale) => {
    const value = await label(locale)

    expect(value.trim().length).toBeGreaterThan(0)
    expect(value).not.toBe('Report a problem')
  }) // prettier-ignore

  it.each([
    'bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu',
    'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
  ])('%s: reportFallback keeps both placeholders once and is translated', async (locale) => {
    const value = await fallback(locale)

    expect(value.match(/\{email\}/g)).toHaveLength(1)
    expect(value.match(/\{reference\}/g)).toHaveLength(1)
    expect(value).not.toBe('or email {email} \u2014 reference: {reference}')
  }) // prettier-ignore
})
