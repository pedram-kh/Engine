import { ApiError } from '@catalyst/api-client'
import type { MessageResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import type { ChatTransport } from '../api/messaging.api'
import ChatPanel from './ChatPanel.vue'

function msg(id: string, attrs: Partial<MessageResource['attributes']> = {}): MessageResource {
  return {
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: 'agency_user',
      body: 'hello there',
      attachments: [],
      system_event_key: null,
      is_own: false,
      sender: { name: 'Agency Op' },
      created_at: '2026-01-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

function feed(messages: MessageResource[], opts: { blocked?: boolean; hasMore?: boolean } = {}) {
  return {
    data: messages,
    meta: {
      thread: {
        id: 'thread-ulid',
        assignment_id: 'assignment-ulid',
        last_message_at: null,
        unread_count: 0,
        human_send_blocked: opts.blocked ?? false,
      },
      has_more: opts.hasMore ?? false,
    },
  }
}

function makeTransport(overrides: Partial<ChatTransport> = {}): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue(feed([msg('m1')])),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
    ...overrides,
  }
}

async function mountPanel(transport: ChatTransport) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(ChatPanel, {
    props: { transport },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('ChatPanel', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the loaded feed', async () => {
    const wrapper = await mountPanel(makeTransport())
    expect(wrapper.find('[data-test="chat-feed"]').text()).toContain('hello there')
    wrapper.unmount()
  })

  it('renders a system message from its event key (localized, never stored text)', async () => {
    const system = msg('s1', {
      kind: 'system',
      sender_role: 'system',
      body: null,
      sender: null,
      system_event_key: 'assignment.contracted',
    })
    const wrapper = await mountPanel(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([system])) }),
    )
    expect(wrapper.find('[data-test="chat-feed"]').text()).toContain(
      'The contract was signed — production can begin.',
    )
    wrapper.unmount()
  })

  it('hides the compose form and shows the closed notice on a terminal thread', async () => {
    const wrapper = await mountPanel(
      makeTransport({ list: vi.fn().mockResolvedValue(feed([msg('m1')], { blocked: true })) }),
    )
    expect(wrapper.find('[data-test="chat-closed"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="chat-compose"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sends a typed message through the transport and clears the field', async () => {
    const transport = makeTransport({
      send: vi.fn().mockResolvedValue({ data: msg('m2', { is_own: true, body: 'my reply' }) }),
    })
    const wrapper = await mountPanel(transport)

    await wrapper.find('[data-test="chat-compose-body"] textarea').setValue('my reply')
    await wrapper.find('[data-test="chat-compose"]').trigger('submit')
    await flushPromises()

    expect(transport.send).toHaveBeenCalledWith({ body: 'my reply' })
    expect(
      (wrapper.find('[data-test="chat-compose-body"] textarea').element as HTMLTextAreaElement)
        .value,
    ).toBe('')
    wrapper.unmount()
  })

  it('binds a 422 body validation error onto the compose field', async () => {
    const transport = makeTransport({
      send: vi.fn().mockRejectedValue(
        new ApiError({
          status: 422,
          code: 'validation.field_required',
          message: 'Validation failed.',
          details: [
            {
              detail: 'The message body is invalid.',
              source: { pointer: '/data/attributes/body' },
              meta: { field: 'body' },
            },
          ],
        }),
      ),
    })
    const wrapper = await mountPanel(transport)

    await wrapper.find('[data-test="chat-compose-body"] textarea').setValue('bad')
    await wrapper.find('[data-test="chat-compose"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('The message body is invalid.')
    wrapper.unmount()
  })
})
