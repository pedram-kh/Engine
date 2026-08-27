/**
 * Vitest coverage for the Agency Files upload dialog (AH-091, chunk A).
 *
 * Pins the three-call presigned dance (init → PUT → complete): a successful
 * round-trip emits `uploaded` + closes; a presign-time rejection
 * (`agency_file.presign_failed` — unsupported MIME / over-cap, D3) surfaces
 * the "unsupported or too large" copy WITHOUT ever calling the vendor PUT;
 * a post-PUT completion failure surfaces the generic upload-failed copy.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/files.api', () => ({
  filesApi: { initUpload: vi.fn(), completeUpload: vi.fn() },
}))

// Keep ApiError real; stub only the vendor PUT (the CreatorAssignmentDetailPage precedent).
vi.mock('@catalyst/api-client', async (importActual) => {
  const actual = await importActual<typeof import('@catalyst/api-client')>()
  return { ...actual, uploadToPresignedUrl: vi.fn().mockResolvedValue(undefined) }
})

import { uploadToPresignedUrl } from '@catalyst/api-client'

import { filesApi } from '../api/files.api'
import AgencyFileUploadDialog from './AgencyFileUploadDialog.vue'

function mountDialog() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(AgencyFileUploadDialog, {
    props: { modelValue: true, agencyId: 'agency-ulid' },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

async function selectFile(wrapper: ReturnType<typeof mountDialog>, file: File): Promise<void> {
  const exposed = wrapper.vm as unknown as { onFileSelected?: (f: File | null) => Promise<void> }
  if (typeof exposed.onFileSelected !== 'function') {
    throw new Error(
      `AgencyFileUploadDialog did not expose onFileSelected; got ${Object.keys(wrapper.vm).join(',')}`,
    )
  }
  await exposed.onFileSelected(file)
  await flushPromises()
}

function makeFile(name = 'Q3 report.pdf', type = 'application/pdf', size = 2048): File {
  return new File([new Uint8Array(size)], name, { type })
}

describe('AgencyFileUploadDialog (AH-091, chunk A)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('runs the full init → PUT → complete dance and emits uploaded + closes', async () => {
    vi.mocked(filesApi.initUpload).mockResolvedValue({
      data: {
        upload_url: 'https://s3.example.test/put',
        upload_id: 'agencies/agency-ulid/files/01HZ.pdf',
        storage_path: 'agencies/agency-ulid/files/01HZ.pdf',
        expires_at: '2026-08-27T20:00:00.000000Z',
        max_bytes: 25 * 1024 * 1024,
      },
    })
    const created = {
      id: 'file-ulid',
      type: 'agency_files' as const,
      attributes: {
        original_filename: 'Q3 report.pdf',
        mime_type: 'application/pdf',
        size_bytes: 2048,
        category: 'campaign_attachment' as const,
        download_url: 'https://s3.example.test/get',
        created_at: '2026-08-27T19:00:00.000000Z',
      },
      relationships: { uploaded_by: null },
    }
    vi.mocked(filesApi.completeUpload).mockResolvedValue({ data: created })

    const wrapper = mountDialog()
    await selectFile(wrapper, makeFile())

    expect(filesApi.initUpload).toHaveBeenCalledWith('agency-ulid', {
      mime_type: 'application/pdf',
      declared_bytes: 2048,
    })
    expect(uploadToPresignedUrl).toHaveBeenCalledWith(
      'https://s3.example.test/put',
      expect.anything(),
      expect.objectContaining({ contentType: 'application/pdf' }),
    )
    expect(filesApi.completeUpload).toHaveBeenCalledWith('agency-ulid', {
      upload_id: 'agencies/agency-ulid/files/01HZ.pdf',
      original_filename: 'Q3 report.pdf',
    })

    expect(wrapper.emitted('uploaded')).toEqual([[created]])
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('surfaces the unsupported/too-large copy on a presign rejection, without ever calling the PUT', async () => {
    vi.mocked(filesApi.initUpload).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'agency_file.presign_failed',
        message: 'Unsupported file MIME type: video/mp4.',
      }),
    )

    const wrapper = mountDialog()
    await selectFile(wrapper, makeFile('clip.mp4', 'video/mp4'))

    expect(uploadToPresignedUrl).not.toHaveBeenCalled()
    expect(filesApi.completeUpload).not.toHaveBeenCalled()
    expect(wrapper.emitted('uploaded')).toBeUndefined()
    expect(document.body.textContent ?? '').toContain(enApp.app.files.errors.unsupportedOrTooLarge)
    wrapper.unmount()
  })

  it('surfaces the generic upload-failed copy when complete() fails after a successful PUT', async () => {
    vi.mocked(filesApi.initUpload).mockResolvedValue({
      data: {
        upload_url: 'https://s3.example.test/put',
        upload_id: 'agencies/agency-ulid/files/01HZ.pdf',
        storage_path: 'agencies/agency-ulid/files/01HZ.pdf',
        expires_at: '2026-08-27T20:00:00.000000Z',
        max_bytes: 25 * 1024 * 1024,
      },
    })
    vi.mocked(filesApi.completeUpload).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'agency_file.complete_failed',
        message: 'Uploaded object not found at the expected path.',
      }),
    )

    const wrapper = mountDialog()
    await selectFile(wrapper, makeFile())

    expect(uploadToPresignedUrl).toHaveBeenCalled()
    expect(wrapper.emitted('uploaded')).toBeUndefined()
    expect(document.body.textContent ?? '').toContain(enApp.app.files.errors.uploadFailed)
    wrapper.unmount()
  })
})
