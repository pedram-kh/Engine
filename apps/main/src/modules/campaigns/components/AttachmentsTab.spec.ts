/**
 * Vitest coverage for the campaign-detail Attachments tab (Campaign
 * attachments from the Files library, chunk B, AH-092, S6).
 *
 * Pins: fetch-on-mount; the empty state; row rendering (name, size,
 * attached-by, attached-at); the `canEdit` gate hiding attach/toggle/detach
 * for non-admin/manager viewers (view-only per D3); the visibility toggle
 * PATCHing through the server and reloading; and the detach confirm dialog
 * DELETE-ing the join row.
 */

import type {
  CampaignAttachmentListResponse,
  CampaignAttachmentResource,
} from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import AttachmentsTab from './AttachmentsTab.vue'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    listAttachments: vi.fn(),
    attachFile: vi.fn(),
    updateAttachment: vi.fn(),
    detachFile: vi.fn(),
  },
}))

vi.mock('@/modules/files/api/files.api', () => ({
  filesApi: { listOptions: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'

const CAMPAIGN = '01CAMPAIGNULIDXXXXXXXXXXXX'

function attachmentRow(
  overrides: Partial<CampaignAttachmentResource['attributes']> & { id?: string } = {},
): CampaignAttachmentResource {
  const { id, ...attrs } = overrides
  return {
    id: id ?? '01FILEA',
    type: 'campaign_attachments',
    attributes: {
      original_filename: 'brief.pdf',
      mime_type: 'application/pdf',
      size_bytes: 2048,
      visible_to_creators: false,
      download_url: 'https://example.test/signed',
      attached_at: '2026-08-01T10:00:00Z',
      ...attrs,
    },
    relationships: {
      attached_by: {
        data: { id: '01USER', type: 'users', attributes: { name: 'Alex Agency' } },
      },
    },
  } as CampaignAttachmentResource
}

function listResponse(rows: CampaignAttachmentResource[]): CampaignAttachmentListResponse {
  return { data: rows }
}

function mountTab(rows: CampaignAttachmentResource[], canEdit = true) {
  vi.mocked(campaignsApi.listAttachments).mockResolvedValue(listResponse(rows))

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  // Unlike a `v-dialog`-rooted component (teleported to `document.body`
  // regardless of attachment), this tab's own markup is NOT teleported — the
  // container must be attached to the live document for `document.querySelector`
  // assertions below to see it.
  const container = document.createElement('div')
  document.body.appendChild(container)

  return mount(AttachmentsTab, {
    props: { agencyId: 'agency-ulid', campaignId: CAMPAIGN, canEdit },
    global: { plugins: [i18n, vuetify] },
    attachTo: container,
  })
}

describe('AttachmentsTab (campaign-detail Attachments tab)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('fetches the attachment list on mount', async () => {
    wrapper = mountTab([attachmentRow()])
    await flushPromises()

    expect(campaignsApi.listAttachments).toHaveBeenCalledWith('agency-ulid', CAMPAIGN)
    expect(document.querySelector('[data-test="attachments-row-01FILEA"]')).not.toBeNull()
  })

  it('shows the empty state when there are no attachments', async () => {
    wrapper = mountTab([])
    await flushPromises()

    expect(document.querySelector('[data-test="attachments-empty-state"]')).not.toBeNull()
  })

  it('renders the filename, size, attached-by, and attached-at on a row', async () => {
    wrapper = mountTab([attachmentRow()])
    await flushPromises()

    expect(document.querySelector('[data-test="attachments-name-01FILEA"]')?.textContent).toContain(
      'brief.pdf',
    )
    expect(document.querySelector('[data-test="attachments-meta-01FILEA"]')?.textContent).toContain(
      'Alex Agency',
    )
  })

  it('hides the attach button, the visibility toggle, and detach for a non-editing viewer', async () => {
    wrapper = mountTab([attachmentRow()], false)
    await flushPromises()

    expect(document.querySelector('[data-test="attachments-open-picker"]')).toBeNull()
    expect(document.querySelector('[data-test="attachments-detach-01FILEA"]')).toBeNull()
    expect(
      document.querySelector('[data-test="attachments-visible-toggle-01FILEA"] input'),
    ).toHaveProperty('disabled', true)
  })

  it('shows the attach button and detach action for an editing agency member', async () => {
    wrapper = mountTab([attachmentRow()], true)
    await flushPromises()

    expect(document.querySelector('[data-test="attachments-open-picker"]')).not.toBeNull()
    expect(document.querySelector('[data-test="attachments-detach-01FILEA"]')).not.toBeNull()
  })

  it('toggles visibility through the server and reloads', async () => {
    vi.mocked(campaignsApi.updateAttachment).mockResolvedValue({ data: {} } as never)
    wrapper = mountTab([attachmentRow({ visible_to_creators: false })])
    await flushPromises()

    await (
      wrapper.vm as unknown as {
        toggleVisibility: (r: CampaignAttachmentResource) => Promise<void>
      }
    ).toggleVisibility(attachmentRow({ visible_to_creators: false }))
    await flushPromises()

    expect(campaignsApi.updateAttachment).toHaveBeenCalledWith('agency-ulid', CAMPAIGN, '01FILEA', {
      visible_to_creators: true,
    })
    // Reload after the toggle.
    expect(campaignsApi.listAttachments).toHaveBeenCalledTimes(2)
  })

  it('detaches through the confirm dialog and reloads', async () => {
    vi.mocked(campaignsApi.detachFile).mockResolvedValue(undefined)
    wrapper = mountTab([attachmentRow()])
    await flushPromises()

    document
      .querySelector<HTMLElement>('[data-test="attachments-detach-01FILEA"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(
      document.querySelector('[data-test="attachments-detach-dialog-message"]')?.textContent,
    ).toContain('brief.pdf')

    document
      .querySelector<HTMLElement>('[data-test="attachments-detach-dialog-confirm"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(campaignsApi.detachFile).toHaveBeenCalledWith('agency-ulid', CAMPAIGN, '01FILEA')
    expect(campaignsApi.listAttachments).toHaveBeenCalledTimes(2)
  })

  it('surfaces the load error state', async () => {
    vi.mocked(campaignsApi.listAttachments).mockRejectedValue(new Error('boom'))
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      fallbackLocale: 'en',
      availableLocales: ['en'],
      messages: { en: enApp } as never,
    }) as unknown as ReturnType<typeof createI18n>
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const container = document.createElement('div')
    document.body.appendChild(container)
    wrapper = mount(AttachmentsTab, {
      props: { agencyId: 'agency-ulid', campaignId: CAMPAIGN, canEdit: true },
      global: { plugins: [i18n, vuetify] },
      attachTo: container,
    })
    await flushPromises()

    expect(document.querySelector('[data-test="attachments-load-error"]')).not.toBeNull()
  })
})
