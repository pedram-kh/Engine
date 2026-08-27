/**
 * Vitest coverage for the campaign attach-files picker (Campaign attachments
 * from the Files library, chunk B, AH-092, S6).
 *
 * Pins: the unpaginated `?for=select` source list loads on open; an
 * already-attached file's row is disabled with the "already attached"
 * badge (never hidden — re-opening the picker after a partial attach must
 * not look like the file vanished); client-side search filters the ALREADY
 * fetched list (unlike `InviteCreatorsDialog`'s server-side search — this
 * picker's source is the small, already-unpaginated `?for=select` list);
 * and submit loops one `attachFile` call per selection, aggregating outcomes
 * rather than aborting the batch on a single failure.
 */

import type { AgencyFileOptionListResponse } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import AttachFilesDialog from './AttachFilesDialog.vue'

vi.mock('@/modules/files/api/files.api', () => ({
  filesApi: { listOptions: vi.fn() },
}))

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { attachFile: vi.fn() },
}))

import { filesApi } from '@/modules/files/api/files.api'
import { campaignsApi } from '../api/campaigns.api'

const CAMPAIGN = '01CAMPAIGNULIDXXXXXXXXXXXX'

function fileOption(id: string, name: string, size = 2048) {
  return {
    id,
    type: 'agency_files' as const,
    attributes: { original_filename: name, mime_type: 'application/pdf', size_bytes: size },
  }
}

function optionsResponse(files: ReturnType<typeof fileOption>[]): AgencyFileOptionListResponse {
  return { data: files }
}

function mountDialog(files: ReturnType<typeof fileOption>[], attachedFileIds: string[] = []) {
  vi.mocked(filesApi.listOptions).mockResolvedValue(optionsResponse(files))

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(AttachFilesDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: CAMPAIGN,
      attachedFileIds,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

type DialogVm = { search: string | null }

function setSearch(wrapper: ReturnType<typeof mount>, term: string | null): void {
  ;(wrapper.vm as unknown as DialogVm).search = term
  return void wrapper.vm.$nextTick()
}

describe('AttachFilesDialog (campaign attach picker)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('fetches the unpaginated ?for=select list on open', async () => {
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf')])
    await flushPromises()

    expect(filesApi.listOptions).toHaveBeenCalledWith('agency-ulid')
    expect(document.querySelector('[data-test="attach-files-row-01F1"]')).not.toBeNull()
  })

  it('disables an already-attached row and shows the badge, rather than hiding it', async () => {
    wrapper = mountDialog(
      [fileOption('01F1', 'brief.pdf'), fileOption('01F2', 'assets.zip')],
      ['01F1'],
    )
    await flushPromises()

    const row = document.querySelector('[data-test="attach-files-row-01F1"]')
    expect(row).not.toBeNull()
    expect(document.querySelector('[data-test="attach-files-already-01F1"]')).not.toBeNull()
    expect(document.querySelector('[data-test="attach-files-checkbox-01F1"] input')).toHaveProperty(
      'disabled',
      true,
    )
  })

  it('filters the fetched list client-side by filename', async () => {
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf'), fileOption('01F2', 'assets.zip')])
    await flushPromises()

    setSearch(wrapper, 'brief')
    await wrapper.vm.$nextTick()

    expect(document.querySelector('[data-test="attach-files-row-01F1"]')).not.toBeNull()
    expect(document.querySelector('[data-test="attach-files-row-01F2"]')).toBeNull()
  })

  it('shows the no-match state without re-fetching (client-side filter)', async () => {
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf')])
    await flushPromises()
    vi.mocked(filesApi.listOptions).mockClear()

    setSearch(wrapper, 'nonexistent')
    await wrapper.vm.$nextTick()

    expect(document.querySelector('[data-test="attach-files-no-match"]')).not.toBeNull()
    expect(filesApi.listOptions).not.toHaveBeenCalled()
  })

  it('shows the no-library state when the agency has no files at all', async () => {
    wrapper = mountDialog([])
    await flushPromises()

    expect(document.querySelector('[data-test="attach-files-no-library"]')).not.toBeNull()
    expect(document.querySelector('[data-test="attach-files-search"]')).toBeNull()
  })

  it('submits one attachFile call per selected file and emits the aggregate summary', async () => {
    vi.mocked(campaignsApi.attachFile).mockResolvedValue({ data: {} } as never)
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf'), fileOption('01F2', 'assets.zip')])
    await flushPromises()

    await document
      .querySelector<HTMLElement>('[data-test="attach-files-row-01F1"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await document
      .querySelector<HTMLElement>('[data-test="attach-files-row-01F2"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    await (wrapper.vm as unknown as { submit: () => Promise<void> }).submit()
    await flushPromises()

    expect(campaignsApi.attachFile).toHaveBeenCalledTimes(2)
    expect(campaignsApi.attachFile).toHaveBeenCalledWith('agency-ulid', CAMPAIGN, {
      agency_file_id: '01F1',
    })
    expect(campaignsApi.attachFile).toHaveBeenCalledWith('agency-ulid', CAMPAIGN, {
      agency_file_id: '01F2',
    })
    expect(wrapper.emitted('attached')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('does not let a click select an already-attached row', async () => {
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf')], ['01F1'])
    await flushPromises()

    document
      .querySelector<HTMLElement>('[data-test="attach-files-row-01F1"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    const submitBtn = document.querySelector<HTMLButtonElement>('[data-test="attach-files-submit"]')
    expect(submitBtn?.disabled).toBe(true)
  })

  it('aggregates outcomes rather than aborting the batch on one failure', async () => {
    vi.mocked(campaignsApi.attachFile)
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce({ data: {} } as never)
    wrapper = mountDialog([fileOption('01F1', 'brief.pdf'), fileOption('01F2', 'assets.zip')])
    await flushPromises()

    document
      .querySelector<HTMLElement>('[data-test="attach-files-row-01F1"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    document
      .querySelector<HTMLElement>('[data-test="attach-files-row-01F2"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    await (wrapper.vm as unknown as { submit: () => Promise<void> }).submit()
    await flushPromises()

    expect(campaignsApi.attachFile).toHaveBeenCalledTimes(2)
    // At least one succeeded, so the dialog still reports success and closes.
    expect(wrapper.emitted('attached')).toBeTruthy()
  })
})
