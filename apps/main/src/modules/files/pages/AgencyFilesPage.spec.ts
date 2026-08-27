/**
 * Vitest coverage for the Agency Files library list page (AH-091, chunk A).
 *
 * Mirrors {@see PoolListPage.spec.ts} / {@see BrandListPage.spec.ts}: the
 * role-gated delete affordance (admin + manager only — staff keeps
 * download), the empty state + upload CTA, the delete confirm round-trip
 * (happy path + error path, object-first per D5), and the disabled download
 * button when `download_url` is null (non-S3 local dev, D4).
 *
 * Defense-in-depth (#40 / Sprint 2 § 5.17): the role-gated visibility test
 * pairs with the backend `AgencyFilePolicy` Pest coverage — the policy is
 * the SOT, the UI gate is a UX nicety.
 */

import type { AgencyFileResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import AgencyFilesPage from './AgencyFilesPage.vue'

vi.mock('../api/files.api', () => ({
  filesApi: {
    list: vi.fn(),
    delete: vi.fn(),
  },
}))

import { filesApi } from '../api/files.api'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function makeFile(overrides: Partial<AgencyFileResource['attributes']> = {}): AgencyFileResource {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'agency_files',
    attributes: {
      original_filename: 'Q3 report.pdf',
      mime_type: 'application/pdf',
      size_bytes: 245_000,
      category: 'campaign_attachment',
      download_url: 'https://s3.example.test/agencies/agency-ulid/files/01HZ.pdf?sig=abc',
      created_at: '2026-08-20T10:00:00.000000Z',
      ...overrides,
    },
    relationships: {
      uploaded_by: {
        data: { id: 'user-ulid', type: 'users', attributes: { name: 'Alex Manager' } },
      },
    },
  }
}

async function mountFilesPage(
  options: {
    files?: AgencyFileResource[]
    role?: 'agency_admin' | 'agency_manager' | 'agency_staff'
  } = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(filesApi.list).mockResolvedValue({
    data: options.files ?? [],
    meta: { total: (options.files ?? []).length, page: 1, per_page: 25, last_page: 1 },
  })

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: 'agency-ulid',
      agency_name: 'Test Agency',
      role: options.role ?? 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/files', name: 'files.list', component: { template: '<div />' } }],
  })
  await router.push('/files')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(AgencyFilesPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('AgencyFilesPage (AH-091, chunk A)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders each file with its name, size, and uploader', async () => {
    const file = makeFile()
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find(`[data-test="file-name-${file.id}"]`).text()).toBe('Q3 report.pdf')
    expect(harness.wrapper.find(`[data-test="file-size-${file.id}"]`).text()).toBe('239 KB')
    expect(harness.wrapper.find(`[data-test="file-uploaded-by-${file.id}"]`).text()).toBe(
      'Alex Manager',
    )
  })

  it('falls back to "Unknown" when the uploader account was deleted (nullOnDelete)', async () => {
    const file = makeFile()
    file.relationships.uploaded_by = null
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find(`[data-test="file-uploaded-by-${file.id}"]`).text()).toBe('Unknown')
  })

  it('shows the empty state with an upload CTA when there are no files', async () => {
    const harness = await mountFilesPage({ files: [] })
    cleanup = harness.cleanup

    expect(harness.wrapper.find('[data-test="files-empty-state"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="files-empty-cta"]').exists()).toBe(true)
  })

  it('disables the download button when download_url is null (non-S3 local dev, D4)', async () => {
    const file = makeFile({ download_url: null })
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    const downloadBtn = harness.wrapper.find(`[data-test="file-download-${file.id}"]`)
    expect(downloadBtn.attributes('disabled')).toBeDefined()
  })

  it('shows the delete affordance for agency_manager (not just admin)', async () => {
    const file = makeFile()
    const harness = await mountFilesPage({ files: [file], role: 'agency_manager' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find(`[data-test="file-delete-${file.id}"]`).exists()).toBe(true)
  })

  it('hides the delete affordance for staff (download stays)', async () => {
    const file = makeFile()
    const harness = await mountFilesPage({ files: [file], role: 'agency_staff' })
    cleanup = harness.cleanup

    expect(harness.wrapper.find(`[data-test="file-delete-${file.id}"]`).exists()).toBe(false)
    expect(harness.wrapper.find(`[data-test="file-download-${file.id}"]`).exists()).toBe(true)
  })

  it('cancelling the delete dialog does NOT call the delete API', async () => {
    const file = makeFile()
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    await harness.wrapper.find(`[data-test="file-delete-${file.id}"]`).trigger('click')
    await flushPromises()
    const cancelBtn = document.querySelector(
      '[data-test="file-delete-dialog-cancel"]',
    ) as HTMLElement | null
    cancelBtn?.click()
    await flushPromises()

    expect(filesApi.delete).not.toHaveBeenCalled()
  })

  it('happy path: confirming delete calls the API object-first and refreshes the list', async () => {
    const file = makeFile({ original_filename: 'Old brief.docx' })
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    vi.mocked(filesApi.delete).mockResolvedValue(undefined)

    await harness.wrapper.find(`[data-test="file-delete-${file.id}"]`).trigger('click')
    await flushPromises()

    const title = document.querySelector('[data-test="file-delete-dialog-title"]')
    const message = document.querySelector('[data-test="file-delete-dialog-message"]')
    expect(title?.textContent).toContain('Delete file')
    expect(message?.textContent).toContain('Old brief.docx')

    const listCallsBefore = vi.mocked(filesApi.list).mock.calls.length
    const confirmBtn = document.querySelector(
      '[data-test="file-delete-dialog-confirm"]',
    ) as HTMLElement | null
    confirmBtn?.click()
    await flushPromises()

    expect(filesApi.delete).toHaveBeenCalledWith('agency-ulid', file.id)
    expect(vi.mocked(filesApi.list).mock.calls.length).toBe(listCallsBefore + 1)
    expect(document.querySelector('[data-test="file-delete-dialog-title"]')).toBeNull()
  })

  it('error path: a failed delete (e.g. StorageDeleteFailedException) leaves the row visible with an error', async () => {
    const file = makeFile()
    const harness = await mountFilesPage({ files: [file] })
    cleanup = harness.cleanup

    vi.mocked(filesApi.delete).mockRejectedValue(new Error('500'))

    await harness.wrapper.find(`[data-test="file-delete-${file.id}"]`).trigger('click')
    await flushPromises()
    const confirmBtn = document.querySelector(
      '[data-test="file-delete-dialog-confirm"]',
    ) as HTMLElement | null
    confirmBtn?.click()
    await flushPromises()

    const errorAlert = document.querySelector('[data-test="file-delete-dialog-error"]')
    expect(errorAlert?.textContent).toContain('Failed to delete the file')
    // Dialog stays open; the row was never optimistically removed.
    expect(document.querySelector('[data-test="file-delete-dialog-title"]')).not.toBeNull()
    expect(harness.wrapper.find(`[data-test="file-name-${file.id}"]`).exists()).toBe(true)
  })

  it('opens the upload dialog from both the header button and the empty-state CTA', async () => {
    const harness = await mountFilesPage({ files: [] })
    cleanup = harness.cleanup

    expect(document.querySelector('[data-test="file-upload-dialog"]')).toBeNull()
    await harness.wrapper.find('[data-test="files-empty-cta"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-test="file-upload-dialog"]')).not.toBeNull()
  })
})
