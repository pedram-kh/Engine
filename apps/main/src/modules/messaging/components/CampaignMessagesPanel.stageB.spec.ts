/**
 * AH-119 Stage B — the campaign thread list previews a deleted newest message as
 * "Message deleted" (flag on) and leaves the preview exactly as sent (flag off, absent, no Pinia).
 *
 * Break-revert: ignore the switch in `previewOrDeleted` → the flag-off case fails; ignore
 * `last_message_is_deleted` → the flag-on case fails.
 */

import type { MessageThreadRollupRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

vi.mock('../api/messaging.api', () => ({
  messagingApi: { agencyRollup: vi.fn() },
  agencyChatTransport: vi.fn(() => ({})),
}))
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: { listAttachments: vi.fn().mockResolvedValue({ data: [] }) },
}))

import { messagingApi } from '../api/messaging.api'
import CampaignMessagesPanel from './CampaignMessagesPanel.vue'

const row = (extra: Record<string, unknown>): MessageThreadRollupRow =>
  ({
    id: 'thread-1',
    type: 'message_thread',
    attributes: {
      assignment_id: 'assignment-1',
      status: 'accepted',
      creator: { display_name: 'Jane Q' },
      last_message_at: null,
      last_message_preview: 'the original text',
      unread_count: 0,
      ...extra,
    },
  }) as MessageThreadRollupRow

async function subtitle(features: Record<string, boolean> | null, extra: Record<string, unknown>) {
  vi.mocked(messagingApi.agencyRollup).mockResolvedValue({ data: [row(extra)] })
  const pinia = createPinia()
  setActivePinia(pinia)
  if (features !== null) {
    useAuthStore().user = { id: 'u', type: 'users', attributes: { features } } as never
  }
  const wrapper = mount(CampaignMessagesPanel, {
    props: { agencyId: 'a', campaignUlid: 'c' },
    global: {
      plugins: [
        pinia,
        createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never }),
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
      stubs: {
        VDialog: { template: '<div />' },
        ChatPanel: { template: '<div />' },
      },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  const text = wrapper.get('.v-list-item-subtitle').text()
  wrapper.unmount()
  return text
}

describe('CampaignMessagesPanel — deleted newest message (AH-119 Stage B)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows "Message deleted" when the switch is on and the newest message is deleted', async () => {
    expect(
      await subtitle({ messages_v2_edit_search: true }, { last_message_is_deleted: true }),
    ).toBe('Message deleted')
  })

  it('shows the preview as sent when the newest message is not deleted', async () => {
    expect(
      await subtitle({ messages_v2_edit_search: true }, { last_message_is_deleted: false }),
    ).toBe('the original text')
  })

  it.each([
    ['switch false', { messages_v2_edit_search: false }],
    ['switch absent', {}],
    ['no signed-in user', null],
  ])('shows the preview as sent with %s, even for a deleted flag', async (_n, features) => {
    expect(await subtitle(features, { last_message_is_deleted: true })).toBe('the original text')
  })
})
