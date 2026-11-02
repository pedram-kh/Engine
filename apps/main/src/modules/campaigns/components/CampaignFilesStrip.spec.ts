/**
 * AH-116 (item 6) — `CampaignFilesStrip`: the read-only files list on the Campaign Overview and
 * above the campaign chat. Pins the two modes' different file sets, the Internal tag, the cap,
 * and that a file is only ever a link when it has a signed URL.
 */

import type { CampaignAttachmentResource } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

import CampaignFilesStrip from './CampaignFilesStrip.vue'

function file(
  id: string,
  overrides: Partial<CampaignAttachmentResource['attributes']> = {},
): CampaignAttachmentResource {
  return {
    id,
    type: 'campaign_attachments',
    attributes: {
      original_filename: `${id}.pdf`,
      mime_type: 'application/pdf',
      size_bytes: 2048,
      visible_to_creators: true,
      download_url: `https://files.example/${id}`,
      attached_at: '2026-10-01T10:00:00Z',
      ...overrides,
    },
    relationships: { attached_by: null },
  }
}

function mountStrip(files: CampaignAttachmentResource[], mode: 'overview' | 'chat') {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(CampaignFilesStrip, {
    props: { files, mode },
    global: { plugins: [i18n, vuetify] },
  })
}

const shownIds = (wrapper: ReturnType<typeof mountStrip>) =>
  wrapper.findAll('.agency-files__list > li').map((li) => li.attributes('data-test'))

describe('CampaignFilesStrip (AH-116)', () => {
  it('draws nothing at all for no files', () => {
    expect(mountStrip([], 'overview').html()).toBe('<!--v-if-->')
    expect(mountStrip([], 'chat').html()).toBe('<!--v-if-->')
  })

  it('overview lists every file and tags only the internal one', () => {
    const wrapper = mountStrip(
      [file('shared'), file('secret', { visible_to_creators: false })],
      'overview',
    )

    expect(shownIds(wrapper)).toEqual(['campaign-file-shared', 'campaign-file-secret'])
    expect(wrapper.get('[data-test="campaign-file-internal-secret"]').text()).toBe('Internal')
    expect(wrapper.find('[data-test="campaign-file-internal-shared"]').exists()).toBe(false)
  })

  it('chat lists ONLY what creators can see, and never tags', () => {
    const wrapper = mountStrip(
      [file('shared'), file('secret', { visible_to_creators: false })],
      'chat',
    )

    expect(shownIds(wrapper)).toEqual(['campaign-file-shared'])
    expect(wrapper.text()).not.toContain('Internal')
  })

  it('chat draws nothing when every file is internal', () => {
    const wrapper = mountStrip([file('secret', { visible_to_creators: false })], 'chat')
    expect(wrapper.find('[data-test="campaign-files-chat"]').exists()).toBe(false)
  })

  it("caps at four, counting the overflow from the mode's own list", () => {
    const files = ['a', 'b', 'c', 'd', 'e', 'f'].map((id) => file(id))

    const overview = mountStrip(files, 'overview')
    expect(shownIds(overview)).toHaveLength(4)
    expect(overview.get('[data-test="campaign-files-more"]').text()).toBe('+2 more')

    // Two of the six are internal: the chat's list is four, so nothing overflows.
    const mixed = [...files.slice(0, 4), file('x', { visible_to_creators: false })]
    const chat = mountStrip(mixed, 'chat')
    expect(shownIds(chat)).toHaveLength(4)
    expect(chat.find('[data-test="campaign-files-more"]').exists()).toBe(false)
  })

  it('exactly four shows no "+N more"', () => {
    const wrapper = mountStrip(
      ['a', 'b', 'c', 'd'].map((id) => file(id)),
      'overview',
    )
    expect(wrapper.find('[data-test="campaign-files-more"]').exists()).toBe(false)
  })

  it('"View all files" is an overview-only control, and emits', async () => {
    const overview = mountStrip([file('a')], 'overview')
    await overview.get('[data-test="campaign-files-view-all"]').trigger('click')
    expect(overview.emitted('view-all')).toHaveLength(1)

    const chat = mountStrip([file('a')], 'chat')
    expect(chat.find('[data-test="campaign-files-view-all"]').exists()).toBe(false)
  })

  it('a file with a signed URL is a safe new-tab download link, named for screen readers', () => {
    const link = mountStrip([file('brief')], 'chat').get('[data-test="campaign-file-brief"] a')

    expect(link.attributes('href')).toBe('https://files.example/brief')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
    expect(link.attributes('aria-label')).toBe('Download brief.pdf')
    expect(link.text()).toContain('2.0 KB')
  })

  it('a file with no URL (a non-S3 disk) is plain text, never a dead link', () => {
    const wrapper = mountStrip([file('local', { download_url: null })], 'chat')

    const row = wrapper.get('[data-test="campaign-file-local"]')
    expect(row.find('a').exists()).toBe(false)
    expect(row.text()).toContain('local.pdf')
  })
})
