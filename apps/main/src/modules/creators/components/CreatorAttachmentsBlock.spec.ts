/**
 * Vitest coverage for the shared creator-facing attachment block (Campaign
 * attachments from the Files library, chunk B, AH-092, S6).
 *
 * Pins: nothing renders for an empty array (the not-visible / never-attached
 * case is silent, not an empty-state message); a non-empty array renders
 * every attachment's filename, formatted size, and a download link built
 * from the row's own `url`; a null `url` degrades to a non-clickable row
 * rather than a broken link.
 */

import type { CreatorCampaignAttachment } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enCreator from '@/core/i18n/locales/en/creator.json'

import CreatorAttachmentsBlock from './CreatorAttachmentsBlock.vue'

function mountBlock(attachments: CreatorCampaignAttachment[]) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enCreator } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(CreatorAttachmentsBlock, {
    props: { attachments },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('CreatorAttachmentsBlock', () => {
  it('renders nothing for an empty array', () => {
    const wrapper = mountBlock([])

    expect(wrapper.find('[data-testid="creator-attachments-block"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })

  it('renders every attachment with its filename, formatted size, and download link', () => {
    const wrapper = mountBlock([
      {
        filename: 'brief.pdf',
        mime_type: 'application/pdf',
        size_bytes: 204800,
        url: 'https://example.com/signed/brief.pdf',
      },
      {
        filename: 'shotlist.docx',
        mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        size_bytes: 51200,
        url: 'https://example.com/signed/shotlist.docx',
      },
    ])

    expect(wrapper.find('[data-testid="creator-attachments-block"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-attachments-block"]').text()).toContain(
      'Attachments',
    )

    const first = wrapper.find('[data-testid="creator-attachment-0"]')
    expect(first.text()).toContain('brief.pdf')
    expect(first.text()).toContain('200 KB')
    expect(first.attributes('href')).toBe('https://example.com/signed/brief.pdf')
    expect(first.attributes('target')).toBe('_blank')
    expect(first.attributes('rel')).toContain('noopener')

    const second = wrapper.find('[data-testid="creator-attachment-1"]')
    expect(second.text()).toContain('shotlist.docx')
    expect(second.text()).toContain('50 KB')
  })

  it('degrades to a non-clickable row when the signed url is null (TTL/receiver gap)', () => {
    const wrapper = mountBlock([
      {
        filename: 'brief.pdf',
        mime_type: 'application/pdf',
        size_bytes: 1024,
        url: null,
      },
    ])

    const row = wrapper.find('[data-testid="creator-attachment-0"]')
    expect(row.exists()).toBe(true)
    expect(row.attributes('href')).toBeUndefined()
  })
})
