/**
 * AH-116 (item 6) — `CampaignMessagesPanel` fills the chat dialog's `pinned` slot with the
 * campaign's files (chat mode: creator-visible only). The files are read when a thread opens, not
 * before, so a closed dialog costs nothing.
 */

import type { CampaignAttachmentResource, MessageThreadRollupRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/messaging.api', () => ({
  messagingApi: { agencyRollup: vi.fn() },
  agencyChatTransport: vi.fn(() => ({ __transport: true })),
}))
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: { listAttachments: vi.fn() },
}))

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'
import { messagingApi } from '../api/messaging.api'
import CampaignMessagesPanel from './CampaignMessagesPanel.vue'

// VDialog teleports; render in place. ChatPanel polls a thread, which is out of scope here.
const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}
const ChatPanelStub = {
  name: 'ChatPanel',
  props: ['transport', 'title'],
  template: '<div data-test="chat-panel-stub" />',
}

const ROW: MessageThreadRollupRow = {
  id: 'thread-1',
  type: 'message_thread',
  attributes: {
    assignment_id: 'assignment-1',
    status: 'accepted',
    creator: { display_name: 'Jane Q' },
    last_message_at: null,
    last_message_preview: 'Hi',
    unread_count: 0,
  },
}

function attachment(id: string, visible: boolean): CampaignAttachmentResource {
  return {
    id,
    type: 'campaign_attachments',
    attributes: {
      original_filename: `${id}.pdf`,
      mime_type: 'application/pdf',
      size_bytes: 2048,
      visible_to_creators: visible,
      download_url: `https://files.example/${id}`,
      attached_at: '2026-10-01T10:00:00Z',
    },
    relationships: { attached_by: null },
  }
}

async function mountPanel(files: CampaignAttachmentResource[] | 'error' = []) {
  vi.mocked(messagingApi.agencyRollup).mockResolvedValue({ data: [ROW] })
  if (files === 'error') {
    vi.mocked(campaignsApi.listAttachments).mockRejectedValue(new Error('network down'))
  } else {
    vi.mocked(campaignsApi.listAttachments).mockResolvedValue({ data: files })
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(CampaignMessagesPanel, {
    props: { agencyId: 'agency-ulid', campaignUlid: 'campaign-ulid' },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub, ChatPanel: ChatPanelStub },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('CampaignMessagesPanel pinned files (AH-116)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('reads no files until a thread is opened', async () => {
    const wrapper = await mountPanel([attachment('shared', true)])

    expect(campaignsApi.listAttachments).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="campaign-files-chat"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('opening a thread pins the creator-visible files above it, without the Internal ones', async () => {
    const wrapper = await mountPanel([attachment('shared', true), attachment('secret', false)])

    await wrapper.get('[data-test="thread-row-thread-1"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.listAttachments).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid')
    const strip = wrapper.get('[data-test="chat-dialog-pinned"] [data-test="campaign-files-chat"]')
    expect(strip.find('[data-test="campaign-file-shared"]').exists()).toBe(true)
    expect(strip.find('[data-test="campaign-file-secret"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a campaign with no files, or a failed read, still opens the thread, with nothing pinned', async () => {
    for (const files of [[], 'error'] as const) {
      const wrapper = await mountPanel(files as CampaignAttachmentResource[] | 'error')
      await wrapper.get('[data-test="thread-row-thread-1"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="chat-panel-stub"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="campaign-files-chat"]').exists()).toBe(false)
      wrapper.unmount()
    }
  })
})
